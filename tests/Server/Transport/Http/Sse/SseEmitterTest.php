<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport\Http\Sse;

use Mcp\Server\Transport\Http\Sse\SseEmitter;
use Mcp\Server\Transport\Http\Sse\SseFrame;
use PHPUnit\Framework\TestCase;

/**
 * SseEmitter is a thin writer over a sink + abort callback. Tests use
 * in-memory captures so there is no real socket I/O.
 */
final class SseEmitterTest extends TestCase
{
    /**
     * emit() writes the formatted frame to the sink in full.
     */
    public function testEmitWritesFormattedFrameToSink(): void
    {
        $buffer = '';
        $emitter = new SseEmitter(
            function (string $s) use (&$buffer): void {
                $buffer .= $s;
            },
            static fn (): bool => false,
        );

        $alive = $emitter->emit(new SseFrame('a:1', null, null, 'hello'));
        $this->assertTrue($alive);
        $this->assertSame("id: a:1\ndata: hello\n\n", $buffer);
        $this->assertTrue($emitter->hasEmitted());
    }

    /**
     * prime() is sugar for a frame with an event id and empty data,
     * matching the spec-recommended priming event shape.
     */
    public function testPrimeEmitsIdAndEmptyDataWithOptionalRetry(): void
    {
        $buffer = '';
        $emitter = new SseEmitter(
            function (string $s) use (&$buffer): void {
                $buffer .= $s;
            },
            static fn (): bool => false,
        );

        $emitter->prime('a:0', 1500);
        $this->assertSame("retry: 1500\nid: a:0\ndata: \n\n", $buffer);
    }

    /**
     * When the abort callback reports the connection is gone, emit()
     * returns false so the caller can stop writing further events.
     * The current frame is still written — we don't second-guess mid-emit.
     */
    public function testEmitReturnsFalseWhenConnectionAborted(): void
    {
        $buffer = '';
        $emitter = new SseEmitter(
            function (string $s) use (&$buffer): void {
                $buffer .= $s;
            },
            static fn (): bool => true,
        );

        $alive = $emitter->emit(new SseFrame('a:1', null, null, 'x'));
        $this->assertFalse($alive);
        $this->assertStringContainsString('data: x', $buffer);
    }

    /**
     * hasEmitted is false until the first emit; useful for deciding
     * whether to emit a priming event on demand.
     */
    public function testHasEmittedStartsFalse(): void
    {
        $emitter = new SseEmitter(
            static function (string $s): void {
            },
            static fn (): bool => false,
        );
        $this->assertFalse($emitter->hasEmitted());
    }
}
