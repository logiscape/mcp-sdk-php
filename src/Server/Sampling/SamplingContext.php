<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Server/Sampling/SamplingContext.php
 */

declare(strict_types=1);

namespace Mcp\Server\Sampling;

use Mcp\Server\ServerSession;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\Meta;
use Mcp\Types\ModelPreferences;
use Mcp\Types\Role;
use Mcp\Types\SamplingCapability;
use Mcp\Types\SamplingMessage;
use Mcp\Types\TextContent;
use Mcp\Types\ToolChoice;

/**
 * Transport-agnostic interface for requesting LLM sampling from the client,
 * injected into tool handler callbacks.
 *
 * Per the MCP spec, `sampling/createMessage` MUST only be sent while a
 * server-side request is being processed — i.e. during a tool call. That
 * constraint is enforced structurally: a `SamplingContext` is only ever
 * instantiated inside `McpServer`'s tool handler closure.
 *
 * Behavior adapts automatically based on transport:
 *
 * - **Stdio**: Calls {@see ServerSession::sendSamplingRequest()} which blocks
 *   synchronously for the client's response.
 * - **HTTP (first round)**: Throws {@see SamplingSuspendException} to trigger
 *   the suspend/resume pattern — the HTTP session writes the request to the
 *   client and defers the tool's response to a subsequent HTTP cycle.
 * - **HTTP (resume round)**: Returns the preloaded result from the previous
 *   HTTP cycle without re-sending anything to the client.
 *
 * If the client has not declared the `sampling` capability (or the negotiated
 * protocol version doesn't cover it), {@see createMessage()} and
 * {@see prompt()} return null — aligned with the spec's "MUST NOT send without
 * capability" rule. Tool handlers decide how to surface that.
 */
class SamplingContext
{
    private int $sequenceCounter = 0;

    /**
     * @param ServerSession $session The active server session
     * @param bool $httpMode Whether running in HTTP (stateless) mode
     * @param array<int, array<string, mixed>> $preloadedResults Results from previous HTTP rounds, keyed by sequence number
     * @param string $toolName Current tool name (for suspend state)
     * @param array<string, mixed> $toolArguments Current tool arguments (for suspend state)
     * @param int|string $originalRequestId JSON-RPC id of the originating tools/call (`string | number`).
     *                   The default placeholder is overwritten by HttpServerSession when it records the
     *                   suspend, using the actual id from the current responder.
     */
    public function __construct(
        private readonly ServerSession $session,
        private readonly bool $httpMode = false,
        private readonly array $preloadedResults = [],
        private readonly string $toolName = '',
        private readonly array $toolArguments = [],
        private readonly int|string $originalRequestId = 0,
    ) {}

    /**
     * Check if the client supports sampling at all (capability + version).
     */
    public function supportsSampling(): bool
    {
        if (!$this->session->clientSupportsFeature('sampling')) {
            return false;
        }
        return $this->getClientSamplingCapability() !== null;
    }

    /**
     * Check if the client supports tool use within sampling (2025-11-25+).
     *
     * Per spec, servers MUST NOT include `tools` in `sampling/createMessage`
     * unless the client declared the `sampling.tools` sub-capability.
     */
    public function supportsToolsInSampling(): bool
    {
        if (!$this->session->clientSupportsFeature('sampling_with_tools')) {
            return false;
        }
        $cap = $this->getClientSamplingCapability();
        if ($cap === null) {
            return false;
        }
        $extras = $cap->jsonSerialize();
        return is_array($extras) && array_key_exists('tools', $extras);
    }

    /**
     * Request a completion from the client's LLM.
     *
     * Returns null when sampling is not available on the client (no capability,
     * wrong protocol version, or the client returns an error response). Use
     * this return value to decide whether the tool can proceed.
     *
     * @param SamplingMessage[] $messages
     * @param string[]|null $stopSequences
     * @param array<int, \Mcp\Types\Tool>|null $tools Requires the client's `sampling.tools`
     *        sub-capability. When the client hasn't advertised it, this method returns null without
     *        sending a request; callers should retry without tools or choose a different fallback.
     */
    public function createMessage(
        array $messages,
        int $maxTokens,
        ?string $systemPrompt = null,
        ?float $temperature = null,
        ?array $stopSequences = null,
        ?ModelPreferences $modelPreferences = null,
        ?string $includeContext = null,
        ?Meta $metadata = null,
        ?array $tools = null,
        ?ToolChoice $toolChoice = null,
        ?Meta $_meta = null,
    ): ?CreateMessageResult {
        if (!$this->supportsSampling()) {
            return null;
        }

        // Tool-enabled sampling requires the client's sampling.tools
        // sub-capability. Returning null lets the caller distinguish "tools
        // unsupported → fall back" from "got a real result", and avoids
        // reinterpreting the request as plain sampling.
        if (($tools !== null || $toolChoice !== null) && !$this->supportsToolsInSampling()) {
            return null;
        }

        $seq = $this->sequenceCounter++;

        // HTTP resume path: return preloaded result if available
        if ($this->httpMode && isset($this->preloadedResults[$seq])) {
            return CreateMessageResult::fromResponseData($this->preloadedResults[$seq]);
        }

        // HTTP suspend path: throw to trigger suspend/resume
        if ($this->httpMode) {
            $request = new CreateMessageRequest(
                messages: $messages,
                maxTokens: $maxTokens,
                stopSequences: $stopSequences,
                systemPrompt: $systemPrompt,
                temperature: $temperature,
                metadata: $metadata,
                modelPreferences: $modelPreferences,
                includeContext: $includeContext,
                tools: $tools,
                toolChoice: $toolChoice,
                _meta: $_meta,
            );
            // Validate the transcript before suspending so cross-message
            // invariants surface as an InvalidArgumentException at the call
            // site rather than a -32602 from the client.
            $request->validate();
            throw new SamplingSuspendException(
                request: $request,
                toolName: $this->toolName,
                toolArguments: $this->toolArguments,
                originalRequestId: $this->originalRequestId,
                samplingSequence: $seq,
                previousResults: $this->preloadedResults,
            );
        }

        // Stdio path: send request and block for response
        return $this->session->sendSamplingRequest(
            messages: $messages,
            maxTokens: $maxTokens,
            stopSequences: $stopSequences,
            systemPrompt: $systemPrompt,
            temperature: $temperature,
            metadata: $metadata,
            modelPreferences: $modelPreferences,
            includeContext: $includeContext,
            tools: $tools,
            toolChoice: $toolChoice,
            _meta: $_meta,
        );
    }

    /**
     * Convenience: request a completion for a single-turn text prompt.
     *
     * Wraps the prompt in a one-element `user` message. Returns null if sampling
     * is not supported — see {@see createMessage()} for semantics.
     */
    public function prompt(
        string $text,
        int $maxTokens = 1000,
        ?string $systemPrompt = null,
        ?float $temperature = null,
    ): ?CreateMessageResult {
        $messages = [
            new SamplingMessage(
                role: Role::USER,
                content: new TextContent(text: $text),
            ),
        ];

        return $this->createMessage(
            messages: $messages,
            maxTokens: $maxTokens,
            systemPrompt: $systemPrompt,
            temperature: $temperature,
        );
    }

    private function getClientSamplingCapability(): ?SamplingCapability
    {
        $clientParams = $this->session->getClientParams();
        if ($clientParams === null) {
            return null;
        }
        return $clientParams->capabilities->sampling ?? null;
    }
}
