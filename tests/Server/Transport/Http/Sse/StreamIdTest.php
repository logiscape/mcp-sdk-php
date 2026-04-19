<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport\Http\Sse;

use Mcp\Server\Transport\Http\Sse\StreamId;
use PHPUnit\Framework\TestCase;

final class StreamIdTest extends TestCase
{
    /**
     * Minted ids must be non-empty base64url (A-Z a-z 0-9 - _) so they
     * are safe for HTTP headers and SSE event ids.
     */
    public function testMintReturnsNonEmptyBase64Url(): void
    {
        $id = StreamId::mint();
        $this->assertNotSame('', $id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $id);
    }

    /**
     * Mints should be unique enough that 100 draws produce no collisions.
     * Not a cryptographic proof, just a smoke check.
     */
    public function testMintProducesUniqueValues(): void
    {
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $id = StreamId::mint();
            $this->assertArrayNotHasKey($id, $seen);
            $seen[$id] = true;
        }
    }

    /**
     * formatEventId concatenates streamId and seq with a single separator.
     */
    public function testFormatEventId(): void
    {
        $this->assertSame('abc:5', StreamId::formatEventId('abc', 5));
        $this->assertSame('xyz:0', StreamId::formatEventId('xyz', 0));
    }

    /**
     * A well-formed `<streamId>:<seq>` parses into its components.
     */
    public function testParseValid(): void
    {
        $r = StreamId::parse('abc:5');
        $this->assertSame(['streamId' => 'abc', 'seq' => 5], $r);
    }

    /**
     * Base64url stream ids (with - and _) are valid in the streamId half.
     */
    public function testParseEventIdWithBase64UrlStreamId(): void
    {
        $r = StreamId::parse('ab-_xY:42');
        $this->assertSame(['streamId' => 'ab-_xY', 'seq' => 42], $r);
    }

    public function testParseReturnsNullForMissingSeparator(): void
    {
        $this->assertNull(StreamId::parse('abc'));
    }

    public function testParseReturnsNullForEmptyStreamId(): void
    {
        $this->assertNull(StreamId::parse(':5'));
    }

    public function testParseReturnsNullForEmptySeq(): void
    {
        $this->assertNull(StreamId::parse('abc:'));
    }

    public function testParseReturnsNullForNonNumericSeq(): void
    {
        $this->assertNull(StreamId::parse('abc:xyz'));
    }

    /**
     * Negative seqs are rejected — ctype_digit doesn't accept a leading
     * minus, which matches the invariant that seq is a non-negative counter.
     */
    public function testParseReturnsNullForNegativeSeq(): void
    {
        $this->assertNull(StreamId::parse('abc:-5'));
    }

    /**
     * Minted ids paired with formatEventId round-trip through parse.
     */
    public function testMintFormatAndParseRoundtrip(): void
    {
        $sid = StreamId::mint();
        $eventId = StreamId::formatEventId($sid, 7);
        $parsed = StreamId::parse($eventId);
        $this->assertNotNull($parsed);
        $this->assertSame($sid, $parsed['streamId']);
        $this->assertSame(7, $parsed['seq']);
    }
}
