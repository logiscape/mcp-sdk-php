<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
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
 * Filename: Server/Transport/Http/Sse/StreamRegistry.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http\Sse;

/**
 * Per-session registry of SSE streams.
 *
 * Each SSE-responding HTTP request (POST or GET) registers a stream here
 * so that subsequent reconnects can be validated (streamId belongs to
 * this session) and the emitter can recognize the final JSON-RPC
 * response for a POST (via originatingRequestId) and mark the stream
 * completed.
 */
final class StreamRegistry
{
    public const KIND_POST = 'post';
    public const KIND_GET  = 'get';

    public const STATUS_OPEN      = 'open';
    public const STATUS_COMPLETED = 'completed';

    /**
     * @var array<string, array{streamId: string, kind: string, originatingRequestId: string|int|null, status: string, lastSeq: int, createdAt: float}>
     */
    private array $records = [];

    /**
     * Register a new stream.
     *
     * @param string|int|null $originatingRequestId JSON-RPC request id this
     *     stream responds to (POST only). Null for GET streams.
     */
    public function open(string $streamId, string $kind, string|int|null $originatingRequestId = null): void
    {
        if ($kind !== self::KIND_POST && $kind !== self::KIND_GET) {
            throw new \InvalidArgumentException("Invalid stream kind: $kind");
        }

        $this->records[$streamId] = [
            'streamId' => $streamId,
            'kind' => $kind,
            'originatingRequestId' => $originatingRequestId,
            'status' => self::STATUS_OPEN,
            'lastSeq' => 0,
            'createdAt' => \microtime(true),
        ];
    }

    public function markCompleted(string $streamId): void
    {
        if (isset($this->records[$streamId])) {
            $this->records[$streamId]['status'] = self::STATUS_COMPLETED;
        }
    }

    public function setLastSeq(string $streamId, int $seq): void
    {
        if (isset($this->records[$streamId])) {
            $this->records[$streamId]['lastSeq'] = $seq;
        }
    }

    /**
     * @return array{streamId: string, kind: string, originatingRequestId: string|int|null, status: string, lastSeq: int, createdAt: float}|null
     */
    public function find(string $streamId): ?array
    {
        return $this->records[$streamId] ?? null;
    }

    /**
     * Find the OPEN stream whose originating request matches the given id,
     * if any. Used by the transport to route a resumed-handler response
     * back to the stream the original request arrived on so a Last-Event-ID
     * reconnect can deliver it.
     *
     * Only `STATUS_OPEN` streams are considered — a stream that was already
     * terminated should not accept further frames.
     *
     * @return array{streamId: string, kind: string, originatingRequestId: string|int|null, status: string, lastSeq: int, createdAt: float}|null
     */
    public function findOpenByOriginatingRequestId(string|int $requestId): ?array
    {
        foreach ($this->records as $record) {
            if ($record['status'] !== self::STATUS_OPEN) {
                continue;
            }
            if ($record['originatingRequestId'] === $requestId) {
                return $record;
            }
        }
        return null;
    }

    public function has(string $streamId): bool
    {
        return isset($this->records[$streamId]);
    }

    public function remove(string $streamId): void
    {
        unset($this->records[$streamId]);
    }

    /**
     * @return array<string, array{streamId: string, kind: string, originatingRequestId: string|int|null, status: string, lastSeq: int, createdAt: float}>
     */
    public function all(): array
    {
        return $this->records;
    }

    /**
     * @return array<string, array{streamId: string, kind: string, originatingRequestId: string|int|null, status: string, lastSeq: int, createdAt: float}>
     */
    public function toArray(): array
    {
        return $this->records;
    }

    /**
     * @param array<string, array{streamId?: string, kind?: string, originatingRequestId?: string|int|null, status?: string, lastSeq?: int, createdAt?: float}> $data
     */
    public static function fromArray(array $data): self
    {
        $registry = new self();
        foreach ($data as $streamId => $record) {
            $streamId = (string) $streamId;
            $registry->records[$streamId] = [
                'streamId' => (string) ($record['streamId'] ?? $streamId),
                'kind' => (string) ($record['kind'] ?? self::KIND_POST),
                'originatingRequestId' => $record['originatingRequestId'] ?? null,
                'status' => (string) ($record['status'] ?? self::STATUS_OPEN),
                'lastSeq' => (int) ($record['lastSeq'] ?? 0),
                'createdAt' => (float) ($record['createdAt'] ?? \microtime(true)),
            ];
        }
        return $registry;
    }
}
