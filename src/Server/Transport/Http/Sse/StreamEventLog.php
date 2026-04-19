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
 * Filename: Server/Transport/Http/Sse/StreamEventLog.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http\Sse;

/**
 * Per-session append-only log of SSE frames, capped at a fixed capacity.
 *
 * The log is the substrate that enables GET + Last-Event-ID resumption: a
 * stream that closes early (or whose connection drops) can be replayed
 * from any seq boundary, and the spec requires that events be replayed
 * only on the stream they originated from — so entries carry an explicit
 * streamId and replaySince() filters by it.
 *
 * Capacity is tuned low (default 64) because the log is serialized into
 * FileSessionStore session metadata on every request; prune completed
 * streams aggressively to keep JSON file size small on shared hosting.
 */
final class StreamEventLog
{
    public const DEFAULT_CAPACITY = 64;

    /**
     * @var list<array{streamId: string, seq: int, frame: SseFrame}>
     */
    private array $entries = [];

    public function __construct(
        private int $capacity = self::DEFAULT_CAPACITY,
    ) {
        if ($this->capacity < 1) {
            $this->capacity = 1;
        }
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * Append a frame. When the log exceeds capacity, the oldest entry is
     * evicted. Callers that care about replay completeness should prune
     * completed streams rather than relying on eviction.
     */
    public function append(string $streamId, int $seq, SseFrame $frame): void
    {
        $this->entries[] = [
            'streamId' => $streamId,
            'seq' => $seq,
            'frame' => $frame,
        ];

        while (\count($this->entries) > $this->capacity) {
            \array_shift($this->entries);
        }
    }

    /**
     * Return frames for `$streamId` whose seq > $lastSeq, in append order.
     *
     * @return list<array{seq: int, frame: SseFrame}>
     */
    public function replaySince(string $streamId, int $lastSeq): array
    {
        $out = [];
        foreach ($this->entries as $e) {
            if ($e['streamId'] === $streamId && $e['seq'] > $lastSeq) {
                $out[] = ['seq' => $e['seq'], 'frame' => $e['frame']];
            }
        }
        return $out;
    }

    /**
     * Drop all entries for the given stream. Called when a stream is
     * marked completed and the client has no reason to replay it further
     * (e.g. the final JSON-RPC response was emitted and acknowledged by a
     * fresh request from the same session).
     */
    public function pruneStream(string $streamId): void
    {
        $this->entries = \array_values(\array_filter(
            $this->entries,
            static fn (array $e): bool => $e['streamId'] !== $streamId,
        ));
    }

    public function countForStream(string $streamId): int
    {
        $n = 0;
        foreach ($this->entries as $e) {
            if ($e['streamId'] === $streamId) {
                $n++;
            }
        }
        return $n;
    }

    public function totalCount(): int
    {
        return \count($this->entries);
    }

    /**
     * @return array{capacity: int, entries: list<array{streamId: string, seq: int, frame: array{id: ?string, event: ?string, retryMs: ?int, data: string}}>}
     */
    public function toArray(): array
    {
        $serialized = [];
        foreach ($this->entries as $e) {
            $serialized[] = [
                'streamId' => $e['streamId'],
                'seq' => $e['seq'],
                'frame' => $e['frame']->toArray(),
            ];
        }
        return [
            'capacity' => $this->capacity,
            'entries' => $serialized,
        ];
    }

    /**
     * @param array{capacity?: int, entries?: list<array{streamId: string, seq: int, frame: array{id?: ?string, event?: ?string, retryMs?: ?int, data?: string}}>} $data
     */
    public static function fromArray(array $data): self
    {
        $capacity = isset($data['capacity']) ? (int) $data['capacity'] : self::DEFAULT_CAPACITY;
        $log = new self($capacity);
        foreach ($data['entries'] ?? [] as $entry) {
            $log->entries[] = [
                'streamId' => (string) $entry['streamId'],
                'seq' => (int) $entry['seq'],
                'frame' => SseFrame::fromArray($entry['frame']),
            ];
        }
        return $log;
    }
}
