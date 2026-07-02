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
 * Filename: Server/InputRequired/InputContext.php
 */

declare(strict_types=1);

namespace Mcp\Server\InputRequired;

use Mcp\Server\ServerSession;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ListRootsRequest;

/**
 * Batch-style SEP-2322 input gathering, injected into tool/prompt
 * callbacks by McpServer (declare an `InputContext` parameter).
 *
 * Where {@see \Mcp\Server\Elicitation\ElicitationContext} suspends on the
 * first unresolved input, this context lets a handler register SEVERAL
 * input requests — elicitation, sampling, roots, in any mix — and suspend
 * once with all of them in a single InputRequiredResult round:
 *
 * ```php
 * $input->wantForm('user_name', 'Your name?', $schema);
 * $input->wantRoots('workspace');
 * $results = $input->collect();          // suspends here on round 1
 * $name = $results['user_name']['content']['name'] ?? null;
 * ```
 *
 * Available only on the modern (2026-07-28) path — the legacy revisions
 * have no wire shape for multi-request rounds. Handlers that must also
 * serve legacy clients should keep using ElicitationContext /
 * SamplingContext, which fall back to the legacy patterns automatically.
 */
final class InputContext
{
    public function __construct(
        private readonly ServerSession $session,
        private readonly ?InputExchange $exchange = null,
    ) {}

    /**
     * Whether the multi-round-trip mechanism is active (modern request).
     */
    public function isAvailable(): bool
    {
        return $this->exchange !== null;
    }

    /**
     * Whether this request's client capabilities declare a feature
     * ('elicitation', 'sampling', 'roots', ...). Spec: servers MUST NOT
     * send input requests the client has not declared support for.
     */
    public function supports(string $feature): bool
    {
        $caps = $this->session->getClientParams()?->capabilities;
        if ($caps === null) {
            return false;
        }
        $serialized = json_decode((string) json_encode($caps), true);
        return is_array($serialized) && array_key_exists($feature, $serialized);
    }

    /**
     * Register a form-mode elicitation; returns the raw ElicitResult
     * array once the client has supplied it, null while pending.
     *
     * @param array<string, mixed> $requestedSchema
     * @return array<string, mixed>|null
     */
    public function wantForm(string $key, string $message, array $requestedSchema): ?array
    {
        $raw = $this->exchangeOrFail()->resolve($key);
        if (is_array($raw) && isset($raw['action']) && is_string($raw['action'])) {
            $this->exchangeOrFail()->accept($key, $raw);
            return $raw;
        }
        $this->exchangeOrFail()->queue($key, new ElicitationCreateRequest(
            message: $message,
            mode: 'form',
            requestedSchema: $requestedSchema,
        ));
        return null;
    }

    /**
     * Register a sampling request; returns the raw CreateMessageResult
     * array once supplied, null while pending.
     *
     * @param array<int, \Mcp\Types\SamplingMessage> $messages
     * @return array<string, mixed>|null
     *
     * @deprecated The Sampling feature is deprecated as of protocol revision
     *             2026-07-28 (SEP-2577); migration: integrate directly with
     *             LLM provider APIs. See the deprecated features registry.
     */
    public function wantSample(string $key, array $messages, int $maxTokens, ?string $systemPrompt = null): ?array
    {
        $this->session->warnDeprecatedFeature(\Mcp\Shared\FeatureLifecycle::SAMPLING);
        $raw = $this->exchangeOrFail()->resolve($key);
        if (is_array($raw) && isset($raw['role'], $raw['content'])) {
            $this->exchangeOrFail()->accept($key, $raw);
            return $raw;
        }
        $this->exchangeOrFail()->queue($key, new CreateMessageRequest(
            messages: $messages,
            maxTokens: $maxTokens,
            systemPrompt: $systemPrompt,
        ));
        return null;
    }

    /**
     * Register a roots/list request; returns the raw ListRootsResult
     * array once supplied, null while pending.
     *
     * @return array<string, mixed>|null
     *
     * @deprecated The Roots feature is deprecated as of protocol revision
     *             2026-07-28 (SEP-2577); migration: pass directories or files
     *             via tool parameters, resource URIs, or server configuration.
     *             See the deprecated features registry.
     */
    public function wantRoots(string $key): ?array
    {
        $this->session->warnDeprecatedFeature(\Mcp\Shared\FeatureLifecycle::ROOTS);
        $raw = $this->exchangeOrFail()->resolve($key);
        if (is_array($raw) && isset($raw['roots']) && is_array($raw['roots'])) {
            $this->exchangeOrFail()->accept($key, $raw);
            return $raw;
        }
        $this->exchangeOrFail()->queue($key, new ListRootsRequest());
        return null;
    }

    /**
     * Finish the gathering phase: suspends the invocation with every
     * still-pending request (the client answers them all in one retry);
     * once everything is resolved, returns the raw results keyed by
     * request name.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $exchange = $this->exchangeOrFail();
        if ($exchange->hasPending()) {
            $exchange->suspend();
        }
        return $exchange->consumedResults();
    }

    private function exchangeOrFail(): InputExchange
    {
        if ($this->exchange === null) {
            throw new \BadMethodCallException(
                'InputContext requires the 2026-07-28 multi-round-trip mechanism; this request '
                . 'negotiated a legacy revision. Guard with $input->isAvailable() or use '
                . 'ElicitationContext/SamplingContext, which support the legacy patterns.'
            );
        }
        return $this->exchange;
    }
}
