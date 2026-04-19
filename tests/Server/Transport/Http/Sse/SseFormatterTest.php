<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport\Http\Sse;

use Mcp\Server\Transport\Http\Sse\SseFormatter;
use Mcp\Server\Transport\Http\Sse\SseFrame;
use PHPUnit\Framework\TestCase;

/**
 * Golden-frame tests for the SSE wire formatter. The exact byte shape matters
 * because the SDK's own client parser (src/Client/Transport/SseEventParser.php)
 * reads these frames and must round-trip them cleanly.
 */
final class SseFormatterTest extends TestCase
{
    private SseFormatter $fmt;

    protected function setUp(): void
    {
        $this->fmt = new SseFormatter();
    }

    /**
     * A frame with only data emits a single data line and a trailing blank
     * line — no id, event, or retry prefixes.
     */
    public function testFormatDataOnly(): void
    {
        $out = $this->fmt->format(new SseFrame(null, null, null, 'hello'));
        $this->assertSame("data: hello\n\n", $out);
    }

    /**
     * When an id is set it is emitted before data.
     */
    public function testFormatWithId(): void
    {
        $out = $this->fmt->format(new SseFrame('abc:1', null, null, 'hello'));
        $this->assertSame("id: abc:1\ndata: hello\n\n", $out);
    }

    /**
     * A non-empty event name is emitted as `event: <name>` before data.
     */
    public function testFormatWithEvent(): void
    {
        $out = $this->fmt->format(new SseFrame(null, 'custom', null, 'hello'));
        $this->assertSame("event: custom\ndata: hello\n\n", $out);
    }

    /**
     * `retry` is a reconnect hint; when set it must precede id/event/data.
     */
    public function testFormatWithRetry(): void
    {
        $out = $this->fmt->format(new SseFrame(null, null, 1500, 'hello'));
        $this->assertSame("retry: 1500\ndata: hello\n\n", $out);
    }

    /**
     * When all four fields are present the order is retry, id, event, data.
     */
    public function testFormatAllFields(): void
    {
        $out = $this->fmt->format(new SseFrame('abc:1', 'custom', 1500, 'hello'));
        $this->assertSame("retry: 1500\nid: abc:1\nevent: custom\ndata: hello\n\n", $out);
    }

    /**
     * Each \n in data introduces a new `data:` line; the WHATWG parser
     * rejoins them with \n on the receiving end.
     */
    public function testFormatMultilineData(): void
    {
        $out = $this->fmt->format(new SseFrame('abc:1', null, null, "line1\nline2"));
        $this->assertSame("id: abc:1\ndata: line1\ndata: line2\n\n", $out);
    }

    /**
     * Empty data must still emit a `data:` line so the parser knows an
     * event was dispatched — this is what the priming event relies on.
     */
    public function testFormatEmptyDataProducesSingleEmptyDataLine(): void
    {
        $out = $this->fmt->format(new SseFrame('abc:0', null, null, ''));
        $this->assertSame("id: abc:0\ndata: \n\n", $out);
    }

    /**
     * \r\n and bare \r inside data are normalized to \n before splitting
     * so Windows-origin payloads don't produce garbled frames.
     */
    public function testFormatNormalizesCrlfInData(): void
    {
        $out = $this->fmt->format(new SseFrame(null, null, null, "a\r\nb\rc"));
        $this->assertSame("data: a\ndata: b\ndata: c\n\n", $out);
    }

    /**
     * id and event name are single-line fields; embedded CR/LF/NUL would
     * confuse the parser, so the formatter strips them.
     */
    public function testFormatStripsNewlinesFromIdAndEvent(): void
    {
        $out = $this->fmt->format(new SseFrame("a\nb", "c\nd", null, 'x'));
        $this->assertSame("id: ab\nevent: cd\ndata: x\n\n", $out);
    }

    /**
     * An empty event string is equivalent to no event and must not emit
     * `event:` (which would override the parser's default of "message").
     */
    public function testFormatOmitsEmptyEvent(): void
    {
        $out = $this->fmt->format(new SseFrame(null, '', null, 'x'));
        $this->assertSame("data: x\n\n", $out);
    }

    /**
     * Negative retry values are nonsensical and are omitted entirely.
     */
    public function testFormatOmitsNegativeRetry(): void
    {
        $out = $this->fmt->format(new SseFrame(null, null, -1, 'x'));
        $this->assertSame("data: x\n\n", $out);
    }

    /**
     * retry: 0 is a valid instruction ("try immediately") and must be emitted.
     */
    public function testFormatEmitsZeroRetry(): void
    {
        $out = $this->fmt->format(new SseFrame(null, null, 0, 'x'));
        $this->assertSame("retry: 0\ndata: x\n\n", $out);
    }
}
