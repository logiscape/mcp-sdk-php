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
 * Filename: Server/Transport/Http/Sse/SseSessionState.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http\Sse;

use Mcp\Server\Transport\Http\HttpSession;

/**
 * Aggregates the SSE event log and stream registry for one HTTP session,
 * persisted via HttpSession metadata under two sibling keys.
 *
 * These are deliberately kept OUT of HttpServerSession::toArray() so that
 * stdio sessions (which share that serializer) are unaffected, and so
 * transport concerns stay at the transport layer.
 */
final class SseSessionState
{
    public const META_KEY_EVENTS  = 'mcp_stream_events';
    public const META_KEY_STREAMS = 'mcp_streams';

    public function __construct(
        private StreamEventLog $log,
        private StreamRegistry $registry,
    ) {
    }

    public static function createEmpty(int $capacity = StreamEventLog::DEFAULT_CAPACITY): self
    {
        return new self(new StreamEventLog($capacity), new StreamRegistry());
    }

    /**
     * Load state from an HttpSession. When the metadata keys are absent or
     * malformed, a fresh empty state is returned — this mirrors how
     * HttpServerRunner treats `mcp_server_session` today.
     */
    public static function loadFrom(HttpSession $session, int $capacity = StreamEventLog::DEFAULT_CAPACITY): self
    {
        $rawLog = $session->getMetadata(self::META_KEY_EVENTS);
        $rawStreams = $session->getMetadata(self::META_KEY_STREAMS);

        $log = \is_array($rawLog)
            ? StreamEventLog::fromArray($rawLog)
            : new StreamEventLog($capacity);

        $registry = \is_array($rawStreams)
            ? StreamRegistry::fromArray($rawStreams)
            : new StreamRegistry();

        return new self($log, $registry);
    }

    /**
     * Persist the current state into the session's metadata dictionary.
     * Does not itself save the session — the caller (HttpServerRunner)
     * already owns `$sessionStore->save(...)` at the request boundary.
     */
    public function saveTo(HttpSession $session): void
    {
        $session->setMetadata(self::META_KEY_EVENTS, $this->log->toArray());
        $session->setMetadata(self::META_KEY_STREAMS, $this->registry->toArray());
    }

    public function getLog(): StreamEventLog
    {
        return $this->log;
    }

    public function getRegistry(): StreamRegistry
    {
        return $this->registry;
    }

    /**
     * Convenience: drop a stream's registry entry and its log entries.
     * Called when a completed stream is no longer useful to keep around
     * (typically: client has reconnected and acknowledged events past the
     * final response, or the stream has aged out).
     */
    public function forgetStream(string $streamId): void
    {
        $this->log->pruneStream($streamId);
        $this->registry->remove($streamId);
    }
}
