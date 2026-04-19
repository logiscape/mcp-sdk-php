<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport\Http\Sse;

use Mcp\Server\Transport\Http\Sse\SseFrame;
use Mcp\Server\Transport\Http\Sse\StreamEventLog;
use PHPUnit\Framework\TestCase;

final class StreamEventLogTest extends TestCase
{
    private function frame(string $id, string $data): SseFrame
    {
        return new SseFrame($id, null, null, $data);
    }

    /**
     * replaySince must return only entries whose seq > cursor, in order.
     */
    public function testAppendAndReplaySinceReturnsFramesPastCursor(): void
    {
        $log = new StreamEventLog();
        $log->append('A', 1, $this->frame('A:1', 'one'));
        $log->append('A', 2, $this->frame('A:2', 'two'));
        $log->append('A', 3, $this->frame('A:3', 'three'));

        $out = $log->replaySince('A', 1);
        $this->assertCount(2, $out);
        $this->assertSame(2, $out[0]['seq']);
        $this->assertSame('two', $out[0]['frame']->data);
        $this->assertSame(3, $out[1]['seq']);
        $this->assertSame('three', $out[1]['frame']->data);
    }

    /**
     * Cursor 0 means "replay everything" for that stream.
     */
    public function testReplaySinceZeroReturnsAll(): void
    {
        $log = new StreamEventLog();
        $log->append('A', 1, $this->frame('A:1', 'x'));
        $log->append('A', 2, $this->frame('A:2', 'y'));
        $this->assertCount(2, $log->replaySince('A', 0));
    }

    public function testReplaySinceUnknownStreamReturnsEmpty(): void
    {
        $log = new StreamEventLog();
        $log->append('A', 1, $this->frame('A:1', 'x'));
        $this->assertSame([], $log->replaySince('B', 0));
    }

    /**
     * Critical spec invariant: replay of stream A MUST NOT leak events
     * from stream B, even when both are in the same log.
     */
    public function testReplaySinceDoesNotLeakOtherStreams(): void
    {
        $log = new StreamEventLog();
        $log->append('A', 1, $this->frame('A:1', 'a1'));
        $log->append('B', 1, $this->frame('B:1', 'b1'));
        $log->append('A', 2, $this->frame('A:2', 'a2'));
        $log->append('B', 2, $this->frame('B:2', 'b2'));

        $aOut = $log->replaySince('A', 0);
        $this->assertCount(2, $aOut);
        foreach ($aOut as $entry) {
            $this->assertStringStartsWith('a', $entry['frame']->data);
        }

        $bOut = $log->replaySince('B', 1);
        $this->assertCount(1, $bOut);
        $this->assertSame('b2', $bOut[0]['frame']->data);
    }

    /**
     * When the log exceeds its capacity the oldest entry is evicted.
     * This bounds the serialized metadata size on disk.
     */
    public function testCapacityEvictsOldest(): void
    {
        $log = new StreamEventLog(3);
        $log->append('A', 1, $this->frame('A:1', 'one'));
        $log->append('A', 2, $this->frame('A:2', 'two'));
        $log->append('A', 3, $this->frame('A:3', 'three'));
        $this->assertSame(3, $log->totalCount());

        $log->append('A', 4, $this->frame('A:4', 'four'));
        $this->assertSame(3, $log->totalCount());

        $out = $log->replaySince('A', 0);
        $this->assertCount(3, $out);
        $this->assertSame(2, $out[0]['seq']);
        $this->assertSame(4, $out[2]['seq']);
    }

    /**
     * pruneStream drops all entries for that stream; others untouched.
     */
    public function testPruneStreamRemovesEntriesForStream(): void
    {
        $log = new StreamEventLog();
        $log->append('A', 1, $this->frame('A:1', 'a'));
        $log->append('B', 1, $this->frame('B:1', 'b'));
        $log->append('A', 2, $this->frame('A:2', 'a2'));

        $log->pruneStream('A');
        $this->assertSame(0, $log->countForStream('A'));
        $this->assertSame(1, $log->countForStream('B'));
    }

    /**
     * Serialized round-trip preserves capacity, frame payload, and
     * per-stream seq ordering.
     */
    public function testToArrayFromArrayRoundtrip(): void
    {
        $log = new StreamEventLog(10);
        $log->append('A', 1, new SseFrame('A:1', 'custom', 2500, "line1\nline2"));
        $log->append('B', 1, new SseFrame('B:1', null, null, 'simple'));

        $roundtripped = StreamEventLog::fromArray($log->toArray());
        $this->assertSame(10, $roundtripped->getCapacity());

        $out = $roundtripped->replaySince('A', 0);
        $this->assertCount(1, $out);
        $frame = $out[0]['frame'];
        $this->assertSame('A:1', $frame->id);
        $this->assertSame('custom', $frame->event);
        $this->assertSame(2500, $frame->retryMs);
        $this->assertSame("line1\nline2", $frame->data);
    }

    public function testCapacityClampsToOneAtMinimum(): void
    {
        $log = new StreamEventLog(0);
        $this->assertSame(1, $log->getCapacity());
    }

    public function testDefaultCapacityIs64(): void
    {
        $log = new StreamEventLog();
        $this->assertSame(StreamEventLog::DEFAULT_CAPACITY, $log->getCapacity());
        $this->assertSame(64, $log->getCapacity());
    }
}
