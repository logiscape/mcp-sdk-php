<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Transport\Http\Sse;

use Mcp\Server\Transport\Http\Sse\StreamRegistry;
use PHPUnit\Framework\TestCase;

final class StreamRegistryTest extends TestCase
{
    public function testOpenRecordsStream(): void
    {
        $r = new StreamRegistry();
        $r->open('s1', StreamRegistry::KIND_POST, 42);
        $rec = $r->find('s1');
        $this->assertNotNull($rec);
        $this->assertSame('s1', $rec['streamId']);
        $this->assertSame(StreamRegistry::KIND_POST, $rec['kind']);
        $this->assertSame(42, $rec['originatingRequestId']);
        $this->assertSame(StreamRegistry::STATUS_OPEN, $rec['status']);
        $this->assertSame(0, $rec['lastSeq']);
    }

    /**
     * Standalone GET streams have no originating request id because they
     * are initiated by the client without a prior POST.
     */
    public function testOpenGetStreamOriginatingIsNull(): void
    {
        $r = new StreamRegistry();
        $r->open('g1', StreamRegistry::KIND_GET);
        $rec = $r->find('g1');
        $this->assertNotNull($rec);
        $this->assertSame(StreamRegistry::KIND_GET, $rec['kind']);
        $this->assertNull($rec['originatingRequestId']);
    }

    public function testOpenRejectsInvalidKind(): void
    {
        $r = new StreamRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $r->open('s', 'bogus');
    }

    /**
     * markCompleted flips status so the emitter stops writing to a finished
     * stream and the replay branch knows to terminate after draining.
     */
    public function testMarkCompleted(): void
    {
        $r = new StreamRegistry();
        $r->open('s1', StreamRegistry::KIND_POST, 1);
        $r->markCompleted('s1');
        $rec = $r->find('s1');
        $this->assertNotNull($rec);
        $this->assertSame(StreamRegistry::STATUS_COMPLETED, $rec['status']);
    }

    /**
     * markCompleted on an unknown id is a no-op — the caller never has to
     * check existence first.
     */
    public function testMarkCompletedUnknownIsNoop(): void
    {
        $r = new StreamRegistry();
        $r->markCompleted('missing');
        $this->assertNull($r->find('missing'));
    }

    public function testSetLastSeq(): void
    {
        $r = new StreamRegistry();
        $r->open('s1', StreamRegistry::KIND_POST, 1);
        $r->setLastSeq('s1', 7);
        $rec = $r->find('s1');
        $this->assertNotNull($rec);
        $this->assertSame(7, $rec['lastSeq']);
    }

    public function testFindUnknown(): void
    {
        $r = new StreamRegistry();
        $this->assertNull($r->find('nope'));
        $this->assertFalse($r->has('nope'));
    }

    public function testRemoveDropsRecord(): void
    {
        $r = new StreamRegistry();
        $r->open('s1', StreamRegistry::KIND_POST, 1);
        $r->remove('s1');
        $this->assertNull($r->find('s1'));
    }

    /**
     * JSON-RPC request ids may be strings (common with UUIDs) — the
     * registry must preserve that type through open/find and round-trip.
     */
    public function testStringRequestIdPreservedThroughRoundtrip(): void
    {
        $r = new StreamRegistry();
        $r->open('s1', StreamRegistry::KIND_POST, 'abc-uuid');
        $this->assertSame('abc-uuid', $r->find('s1')['originatingRequestId']);

        $restored = StreamRegistry::fromArray($r->toArray());
        $this->assertSame('abc-uuid', $restored->find('s1')['originatingRequestId']);
    }

    public function testToArrayFromArrayRoundtrip(): void
    {
        $r = new StreamRegistry();
        $r->open('s1', StreamRegistry::KIND_POST, 42);
        $r->setLastSeq('s1', 3);
        $r->open('g1', StreamRegistry::KIND_GET);
        $r->markCompleted('s1');

        $restored = StreamRegistry::fromArray($r->toArray());
        $s1 = $restored->find('s1');
        $this->assertNotNull($s1);
        $this->assertSame(42, $s1['originatingRequestId']);
        $this->assertSame(StreamRegistry::STATUS_COMPLETED, $s1['status']);
        $this->assertSame(3, $s1['lastSeq']);

        $g1 = $restored->find('g1');
        $this->assertNotNull($g1);
        $this->assertSame(StreamRegistry::KIND_GET, $g1['kind']);
        $this->assertNull($g1['originatingRequestId']);
    }
}
