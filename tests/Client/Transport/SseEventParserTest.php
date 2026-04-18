<?php

declare(strict_types=1);

namespace Mcp\Tests\Client\Transport;

use PHPUnit\Framework\TestCase;
use Mcp\Client\Transport\SseEventParser;

/**
 * Tests for the event-aware SSE parser.
 *
 * Validates the WHATWG HTML SSE parsing rules used by the Streamable HTTP
 * transport: line-ending normalization, comment handling, single-space strip,
 * multi-line data joining, retry-field validation, and streaming partials.
 */
final class SseEventParserTest extends TestCase
{
    /**
     * A priming-only event (empty data buffer) must still be emitted so the
     * caller can record id and retry for reconnection.
     */
    public function testPrimingEventWithEmptyData(): void
    {
        $raw = "id: event-1\nretry: 500\ndata: \n\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(1, $events);
        $this->assertSame('event-1', $events[0]['id']);
        $this->assertSame(500, $events[0]['retry']);
        $this->assertSame('', $events[0]['data']);
        $this->assertSame('message', $events[0]['event']);
    }

    /**
     * Two back-to-back events in a single buffer must both be returned, in order.
     */
    public function testMultipleEventsInOneBuffer(): void
    {
        $raw = "id: 1\ndata: first\n\nid: 2\ndata: second\n\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(2, $events);
        $this->assertSame('1', $events[0]['id']);
        $this->assertSame('first', $events[0]['data']);
        $this->assertSame('2', $events[1]['id']);
        $this->assertSame('second', $events[1]['data']);
    }

    /**
     * Multiple data fields in the same event must be joined with a single LF
     * between them. This is the only legal multi-line form in SSE.
     */
    public function testMultilineDataJoinsWithLf(): void
    {
        $raw = "data: line1\ndata: line2\ndata: line3\n\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(1, $events);
        $this->assertSame("line1\nline2\nline3", $events[0]['data']);
    }

    /**
     * Lines starting with ':' are comments and must be silently ignored.
     */
    public function testCommentLinesAreIgnored(): void
    {
        $raw = ":keepalive\ndata: payload\n\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(1, $events);
        $this->assertSame('payload', $events[0]['data']);
    }

    /**
     * A keepalive-only event (only comments) must not produce an event.
     */
    public function testKeepaliveOnlyYieldsNoEvent(): void
    {
        $raw = ":keepalive\n\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(0, $events);
    }

    /**
     * retry values must be decimal digits; anything else is ignored (retry stays null).
     */
    public function testNonNumericRetryIsIgnored(): void
    {
        $raw = "retry: abc\ndata: x\n\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(1, $events);
        $this->assertNull($events[0]['retry']);
    }

    /**
     * CRLF line endings must be normalized to LF and parsed the same way.
     */
    public function testCrlfLineEndings(): void
    {
        $raw = "id: e\r\nretry: 250\r\ndata: hi\r\n\r\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(1, $events);
        $this->assertSame('e', $events[0]['id']);
        $this->assertSame(250, $events[0]['retry']);
        $this->assertSame('hi', $events[0]['data']);
    }

    /**
     * Lone CR line endings (legacy) must also normalize to LF.
     */
    public function testLoneCrLineEndings(): void
    {
        $raw = "id: e\rdata: hi\r\r";
        $events = SseEventParser::parse($raw);

        $this->assertCount(1, $events);
        $this->assertSame('e', $events[0]['id']);
        $this->assertSame('hi', $events[0]['data']);
    }

    /**
     * A line containing no colon is a field with empty value; field name is
     * the whole line. Unknown fields are ignored, but the event still exists.
     */
    public function testFieldWithoutColon(): void
    {
        $raw = "data\n\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(1, $events);
        $this->assertSame('', $events[0]['data']);
    }

    /**
     * Per WHATWG: exactly one leading space is stripped from the field value.
     * A second space is part of the value.
     */
    public function testSingleLeadingSpaceStrip(): void
    {
        $raw = "data:  x\n\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(1, $events);
        $this->assertSame(' x', $events[0]['data']);
    }

    /**
     * parseStreaming must consume whole events and leave any trailing partial
     * in the caller's buffer for the next chunk.
     */
    public function testStreamingLeavesTrailingPartial(): void
    {
        $buffer = "id: 1\ndata: first\n\nid: 2\ndata: half";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertCount(1, $events);
        $this->assertSame('first', $events[0]['data']);
        $this->assertSame("id: 2\ndata: half", $buffer);

        // Feed the rest and parse again — second event should now complete.
        $buffer .= "-done\n\n";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertCount(1, $events);
        $this->assertSame('half-done', $events[0]['data']);
        $this->assertSame('', $buffer);
    }

    /**
     * Regression: when a CRLF line terminator is split across cURL chunks
     * (chunk ends with \r, next chunk starts with \n), the parser must treat
     * the sequence as a single line terminator rather than two — otherwise
     * id/retry fields get torn off the event they belong to.
     */
    public function testStreamingCrlfSplitAcrossChunks(): void
    {
        $buffer = "data: x\r";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertSame([], $events);
        $this->assertSame("data: x\r", $buffer);

        $buffer .= "\nid: 1\r\n\r\n";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertCount(1, $events);
        $this->assertSame('x', $events[0]['data']);
        $this->assertSame('1', $events[0]['id']);
        $this->assertSame('', $buffer);
    }

    /**
     * Regression: a CRLF split across chunks must not strand the retry field
     * into a separate empty event, which would corrupt the reconnect delay.
     */
    public function testStreamingCrlfSplitPreservesRetryField(): void
    {
        $buffer = "data: x\r";
        SseEventParser::parseStreaming($buffer);
        $buffer .= "\nretry: 500\r\n\r\n";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertCount(1, $events);
        $this->assertSame('x', $events[0]['data']);
        $this->assertSame(500, $events[0]['retry']);
    }

    /**
     * A held-back CR must still act as a line terminator once a non-LF byte
     * follows it in the next chunk (lone-CR legacy line ending).
     */
    public function testStreamingLoneCrAtChunkBoundaryStillTerminates(): void
    {
        $buffer = "data: x\r";
        SseEventParser::parseStreaming($buffer);
        $buffer .= "data: y\r\n\r\n";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertCount(1, $events);
        $this->assertSame("x\ny", $events[0]['data']);
    }

    /**
     * Regression: a JSON-RPC payload delivered as a single SSE event must not
     * be split when the chunk boundary falls between \r and \n of the trailing
     * CRLF. The reassembled data string must match the original payload byte
     * for byte.
     */
    public function testStreamingDataSplitMidJsonPayload(): void
    {
        $payload = '{"jsonrpc":"2.0","id":7,"result":{"ok":true}}';
        $buffer = "id: evt-7\r\ndata: {$payload}\r";
        $events = SseEventParser::parseStreaming($buffer);
        $this->assertSame([], $events);

        $buffer .= "\n\r\n";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertCount(1, $events);
        $this->assertSame('evt-7', $events[0]['id']);
        $this->assertSame($payload, $events[0]['data']);
        $this->assertSame('', $buffer);
    }

    /**
     * A complete lone-CR-terminated event (data: x\r\r) inside a single
     * streaming chunk must be emitted immediately. Previously a naive trailing
     * CR holdback would defer the second CR forever, stranding the event if
     * the stream ended or paused.
     */
    public function testStreamingCompleteLoneCrEventEmitsImmediately(): void
    {
        $buffer = "data: x\r\r";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertCount(1, $events);
        $this->assertSame('x', $events[0]['data']);
        $this->assertSame('', $buffer);
    }

    /**
     * A buffer ending in \n\r is two consecutive line terminators (LF + CR =
     * blank line). The trailing CR must not be deferred when the prefix
     * already ends in a line terminator — both \n\r and \n\r\n dispatch the
     * same event per spec.
     */
    public function testStreamingMixedLfCrTerminatorEmitsImmediately(): void
    {
        $buffer = "data: x\n\r";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertCount(1, $events);
        $this->assertSame('x', $events[0]['data']);
    }

    /**
     * Lone-CR event split across chunks: "data: x\r" arriving alone must
     * defer (the CR could still be the start of CRLF), but a follow-up "\r"
     * completes the blank-line terminator and the event must dispatch.
     */
    public function testStreamingLoneCrEventCompletedByLaterCr(): void
    {
        $buffer = "data: x\r";
        $events = SseEventParser::parseStreaming($buffer);
        $this->assertSame([], $events);

        $buffer .= "\r";
        $events = SseEventParser::parseStreaming($buffer);

        $this->assertCount(1, $events);
        $this->assertSame('x', $events[0]['data']);
        $this->assertSame('', $buffer);
    }

    /**
     * An empty input must yield no events and must not throw.
     */
    public function testEmptyInput(): void
    {
        $this->assertSame([], SseEventParser::parse(''));

        $buffer = '';
        $this->assertSame([], SseEventParser::parseStreaming($buffer));
        $this->assertSame('', $buffer);
    }

    /**
     * A priming event used for post-close resume must parse id, retry, and
     * empty data correctly.
     */
    public function testConformancePrimingEventShape(): void
    {
        $raw = "id: event-3\nretry: 500\ndata: \n\n";
        $events = SseEventParser::parse($raw);

        $this->assertCount(1, $events);
        $this->assertSame('event-3', $events[0]['id']);
        $this->assertSame(500, $events[0]['retry']);
        $this->assertSame('', $events[0]['data']);
        $this->assertSame('message', $events[0]['event']);
    }
}
