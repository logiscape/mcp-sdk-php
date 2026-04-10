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
 * Filename: Server/Elicitation/ElicitationContext.php
 */

declare(strict_types=1);

namespace Mcp\Server\Elicitation;

use Mcp\Server\McpServerException;
use Mcp\Server\ServerSession;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ElicitationCapability;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\Meta;
use Mcp\Types\TaskRequestParams;

/**
 * Transport-agnostic elicitation interface injected into tool handler callbacks.
 *
 * Provides methods for requesting form-mode and URL-mode elicitation from the
 * client. Behavior adapts automatically based on transport:
 *
 * - **Stdio**: Calls sendElicitationRequest() which blocks synchronously.
 * - **HTTP**: Returns preloaded results from previous rounds, or throws
 *   ElicitationSuspendException to trigger the suspend/resume pattern.
 *
 * ## Form vs URL mode semantics
 *
 * **Form mode** (`form()`, `requiresForm()`): The client collects structured data
 * from the user and returns it in the response. When `action` is `"accept"`, the
 * `content` field contains the submitted data. This is a complete round-trip.
 *
 * **URL mode** (`url()`): The client opens a URL for out-of-band interaction
 * (OAuth, payment, API key entry). When `action` is `"accept"`, it means the user
 * **consented to open the URL** — it does NOT mean the out-of-band interaction has
 * completed. The typical pattern for URL mode is the error-based flow: use
 * `throwUrlRequired()` to return a -32042 error, letting the client handle the URL
 * flow and retry the tool call once the out-of-band interaction completes.
 *
 * Tool handlers use this class without knowing which transport is active.
 */
class ElicitationContext
{
    private int $sequenceCounter = 0;

    /**
     * @param ServerSession $session The active server session
     * @param bool $httpMode Whether running in HTTP (stateless) mode
     * @param array<int, array<string, mixed>> $preloadedResults Results from previous HTTP rounds, keyed by sequence number
     * @param string $toolName Current tool name (for suspend state)
     * @param array<string, mixed> $toolArguments Current tool arguments (for suspend state)
     * @param int $originalRequestId Original tools/call request ID (for suspend state)
     */
    public function __construct(
        private readonly ServerSession $session,
        private readonly bool $httpMode = false,
        private readonly array $preloadedResults = [],
        private readonly string $toolName = '',
        private readonly array $toolArguments = [],
        private readonly int $originalRequestId = 0,
    ) {}

    /**
     * Check if the client supports form-mode elicitation.
     */
    public function supportsForm(): bool
    {
        if (!$this->session->clientSupportsFeature('elicitation')) {
            return false;
        }
        $caps = $this->getClientElicitationCapability();
        if ($caps === null) {
            return false;
        }
        // Per spec: empty object "elicitation": {} is equivalent to form-only support
        // So form is supported if elicitation capability exists and either form is true or both are null
        return $caps->form !== null || ($caps->form === null && $caps->url === null);
    }

    /**
     * Check if the client supports URL-mode elicitation.
     */
    public function supportsUrl(): bool
    {
        if (!$this->session->clientSupportsFeature('url_elicitation')) {
            return false;
        }
        $caps = $this->getClientElicitationCapability();
        if ($caps === null) {
            return false;
        }
        return $caps->url !== null;
    }

    /**
     * Request form-mode elicitation from the client.
     *
     * The client collects structured data from the user inline. When the result
     * action is "accept", the `content` field contains the submitted data.
     *
     * @param string $message Human-readable explanation of why the information is needed
     * @param array<string, mixed> $requestedSchema JSON Schema defining expected response structure
     * @return ElicitationCreateResult|null The client's response, or null if not supported
     */
    public function form(string $message, array $requestedSchema, ?Meta $_meta = null, ?TaskRequestParams $task = null): ?ElicitationCreateResult
    {
        if (!$this->supportsForm()) {
            return null;
        }

        $seq = $this->sequenceCounter++;

        // HTTP resume path: return preloaded result if available
        if ($this->httpMode && isset($this->preloadedResults[$seq])) {
            return ElicitationCreateResult::fromResponseData($this->preloadedResults[$seq]);
        }

        // HTTP suspend path: throw to trigger suspend/resume
        if ($this->httpMode) {
            $request = new ElicitationCreateRequest(
                message: $message,
                mode: 'form',
                requestedSchema: $requestedSchema,
                _meta: $_meta,
                task: $task,
            );
            throw new ElicitationSuspendException(
                request: $request,
                toolName: $this->toolName,
                toolArguments: $this->toolArguments,
                originalRequestId: $this->originalRequestId,
                elicitationSequence: $seq,
                previousResults: $this->preloadedResults,
            );
        }

        // Stdio path: send request and block for response
        return $this->session->sendElicitationRequest(
            message: $message,
            requestedSchema: $requestedSchema,
            _meta: $_meta,
            task: $task,
        );
    }

    /**
     * Request URL-mode elicitation from the client (inline consent).
     *
     * IMPORTANT: For URL mode, an "accept" response means the user **consented to
     * open the URL** — it does NOT mean the out-of-band interaction (OAuth, payment,
     * etc.) has completed. If your tool needs to wait for completion, use
     * `throwUrlRequired()` instead, which returns a -32042 error so the client can
     * handle the full lifecycle and retry.
     *
     * This method is appropriate when you only need consent (e.g., directing the user
     * to documentation or a status page).
     *
     * @param string $message Human-readable explanation of why the interaction is needed
     * @param string $url The URL the user should navigate to
     * @param string|null $elicitationId Unique identifier for this elicitation (auto-generated if null)
     * @return ElicitationCreateResult|null The client's consent response, or null if not supported
     */
    public function url(string $message, string $url, ?string $elicitationId = null, ?Meta $_meta = null, ?TaskRequestParams $task = null): ?ElicitationCreateResult
    {
        if (!$this->supportsUrl()) {
            return null;
        }

        $elicitationId = $elicitationId ?? bin2hex(random_bytes(16));
        $seq = $this->sequenceCounter++;

        // HTTP resume path
        if ($this->httpMode && isset($this->preloadedResults[$seq])) {
            return ElicitationCreateResult::fromResponseData($this->preloadedResults[$seq]);
        }

        // HTTP suspend path
        if ($this->httpMode) {
            $request = new ElicitationCreateRequest(
                message: $message,
                mode: 'url',
                url: $url,
                elicitationId: $elicitationId,
                _meta: $_meta,
                task: $task,
            );
            throw new ElicitationSuspendException(
                request: $request,
                toolName: $this->toolName,
                toolArguments: $this->toolArguments,
                originalRequestId: $this->originalRequestId,
                elicitationSequence: $seq,
                previousResults: $this->preloadedResults,
            );
        }

        // Stdio path
        return $this->session->sendElicitationRequest(
            message: $message,
            url: $url,
            elicitationId: $elicitationId,
            _meta: $_meta,
            task: $task,
        );
    }

    /**
     * Request form-mode elicitation, throwing if the client declines or cancels.
     *
     * @param string $message Human-readable explanation
     * @param array<string, mixed> $requestedSchema JSON Schema for the form
     * @return ElicitationCreateResult The accepted result (with content)
     * @throws ElicitationDeclinedException If the client declines, cancels, or doesn't support form elicitation
     */
    public function requiresForm(string $message, array $requestedSchema, ?Meta $_meta = null, ?TaskRequestParams $task = null): ElicitationCreateResult
    {
        $result = $this->form($message, $requestedSchema, $_meta, $task);
        if ($result === null) {
            throw new ElicitationDeclinedException('unsupported', 'Client does not support form elicitation');
        }
        if ($result->action !== 'accept') {
            throw new ElicitationDeclinedException($result->action);
        }
        return $result;
    }

    /**
     * Throw a -32042 URLElicitationRequired error for out-of-band URL flows.
     *
     * This is the recommended pattern for OAuth, API key entry, payment, and other
     * flows where the tool cannot proceed until an out-of-band interaction completes.
     * The error tells the client which URL(s) to present to the user. After the user
     * completes the flow, the client retries the original tool call.
     *
     * Typical usage in a tool handler:
     * ```php
     * function(string $query, ElicitationContext $elicit) {
     *     if (!$this->hasCredentials()) {
     *         $elicit->throwUrlRequired(
     *             'Authorization is required to access your files.',
     *             'https://myserver.example.com/oauth/start',
     *         );
     *     }
     *     // If we get here, credentials exist from a previous successful flow.
     *     return $this->fetchData($query);
     * }
     * ```
     *
     * @param string $message Human-readable explanation of why the URL interaction is needed
     * @param string $url The URL the user should navigate to
     * @param string|null $elicitationId Unique identifier (auto-generated if null)
     * @throws McpServerException Always throws with error code -32042
     * @return never
     */
    public function throwUrlRequired(
        string $message,
        string $url,
        ?string $elicitationId = null,
    ): never {
        $elicitationId = $elicitationId ?? bin2hex(random_bytes(16));

        throw McpServerException::urlElicitationRequired(
            elicitations: [
                [
                    'mode' => 'url',
                    'elicitationId' => $elicitationId,
                    'url' => $url,
                    'message' => $message,
                ],
            ],
            message: $message,
        );
    }

    /**
     * Throw a -32042 URLElicitationRequired error with multiple URL elicitations.
     *
     * Use when the tool requires the user to complete multiple out-of-band
     * interactions before it can proceed.
     *
     * @param array<int, array{message: string, url: string, elicitationId?: string}> $elicitations
     * @param string $message Overall error message
     * @throws McpServerException Always throws with error code -32042
     * @return never
     */
    public function throwMultipleUrlRequired(array $elicitations, string $message = ''): never
    {
        $formatted = [];
        foreach ($elicitations as $elicitation) {
            $formatted[] = [
                'mode' => 'url',
                'elicitationId' => $elicitation['elicitationId'] ?? bin2hex(random_bytes(16)),
                'url' => $elicitation['url'],
                'message' => $elicitation['message'],
            ];
        }

        throw McpServerException::urlElicitationRequired(
            elicitations: $formatted,
            message: $message ?: 'This request requires URL elicitation to proceed.',
        );
    }

    /**
     * Send a notifications/elicitation/complete notification to the client.
     *
     * Call this when your server learns (through its own endpoint, e.g., an OAuth
     * callback) that an out-of-band URL-mode elicitation has completed. The client
     * can use this to prompt the user to retry the original request.
     *
     * @param string $elicitationId The ID of the completed elicitation
     */
    public function notifyUrlComplete(string $elicitationId): void
    {
        $this->session->sendElicitationCompleteNotification($elicitationId);
    }

    private function getClientElicitationCapability(): ?ElicitationCapability
    {
        $clientParams = $this->session->getClientParams();
        if ($clientParams === null) {
            return null;
        }
        return $clientParams->capabilities->elicitation ?? null;
    }
}
