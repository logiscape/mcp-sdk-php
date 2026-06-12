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
 * Filename: Server/InputRequired/InputExchange.php
 */

declare(strict_types=1);

namespace Mcp\Server\InputRequired;

use Mcp\Types\Request;

/**
 * Per-invocation bookkeeping for the SEP-2322 multi-round-trip mechanism.
 *
 * One exchange exists per modern tools/call / prompts/get invocation. It
 * holds the input responses available this round (results carried in the
 * verified `requestState` from earlier rounds merged with the retry's
 * fresh `inputResponses`), records which of them the handler actually
 * consumed (so they ride into the next round's state), and queues the
 * input requests an unresolved round must send back. Unknown response
 * keys are simply never resolved — the spec says servers SHOULD ignore
 * unexpected entries.
 */
final class InputExchange
{
    /** @var array<string, mixed> Raw responses consumed by the handler. */
    private array $consumed = [];

    /** @var array<string, Request> Input requests queued for the next round. */
    private array $pending = [];

    /**
     * @param array<string, mixed> $available Raw responses available this
     *        round, keyed by input-request name
     */
    public function __construct(
        private readonly array $available = [],
    ) {}

    /**
     * The raw response for a key, or null when the client has not supplied
     * one (yet). Does NOT mark it consumed — callers validate the shape
     * first and call accept(), so an invalid value is re-requested rather
     * than carried forward.
     */
    public function resolve(string $key): mixed
    {
        return $this->available[$key] ?? null;
    }

    /**
     * Record a validated response so it survives into the next round's
     * requestState.
     */
    public function accept(string $key, mixed $raw): void
    {
        $this->consumed[$key] = $raw;
    }

    /**
     * Queue an input request for the next round.
     */
    public function queue(string $key, Request $request): void
    {
        $this->pending[$key] = $request;
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Throw the suspend signal carrying every queued request and every
     * consumed result.
     *
     * @throws InputRequiredSuspendException Always.
     */
    public function suspend(): never
    {
        throw new InputRequiredSuspendException($this->pending, $this->consumed);
    }

    /** @return array<string, Request> */
    public function pendingRequests(): array
    {
        return $this->pending;
    }

    /** @return array<string, mixed> */
    public function consumedResults(): array
    {
        return $this->consumed;
    }
}
