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
 * Filename: Server/Transport/Http/Sse/SseEmitter.php
 */

declare(strict_types=1);

namespace Mcp\Server\Transport\Http\Sse;

/**
 * Writes SSE frames to an output sink.
 *
 * The emitter is deliberately I/O-agnostic: construction takes a write
 * callback and an abort-check callback. The production HTTP runner wires
 * these to `echo`/`flush()` and `connection_aborted()`; unit tests inject
 * capture arrays.
 *
 * Header/buffer preparation for the live request (Content-Type,
 * Cache-Control, `ob_end_clean` loop, `zlib.output_compression` override,
 * `ignore_user_abort(true)`) is the caller's responsibility — this class
 * only emits frames once the response body phase has begun.
 */
final class SseEmitter
{
    /** @var callable(string): void */
    private $sink;

    /** @var callable(): bool */
    private $isAborted;

    private SseFormatter $formatter;

    /**
     * Whether any frame has been emitted yet. Useful for deciding whether
     * to short-circuit if the connection aborted before priming.
     */
    private bool $anyEmitted = false;

    /**
     * @param callable(string): void $sink      Receives formatted frame text.
     * @param callable(): bool       $isAborted Returns true if the HTTP client has disconnected.
     */
    public function __construct(
        callable $sink,
        callable $isAborted,
        ?SseFormatter $formatter = null,
    ) {
        $this->sink = $sink;
        $this->isAborted = $isAborted;
        $this->formatter = $formatter ?? new SseFormatter();
    }

    /**
     * Emit a frame. Returns false if the connection is known to be aborted
     * AFTER this write; the caller should stop emitting further frames.
     *
     * The frame is still written in that case — the spec allows the server
     * to close its end at any time, but we shouldn't suppress the current
     * frame just because a subsequent flush might fail.
     */
    public function emit(SseFrame $frame): bool
    {
        $text = $this->formatter->format($frame);
        ($this->sink)($text);
        $this->anyEmitted = true;

        return !($this->isAborted)();
    }

    public function hasEmitted(): bool
    {
        return $this->anyEmitted;
    }

    /**
     * Convenience: emit a priming frame (id + empty data) per spec.
     */
    public function prime(string $eventId, ?int $retryMs = null): bool
    {
        return $this->emit(new SseFrame(
            id: $eventId,
            event: null,
            retryMs: $retryMs,
            data: '',
        ));
    }
}
