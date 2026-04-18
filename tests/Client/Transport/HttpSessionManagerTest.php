<?php

declare(strict_types=1);

namespace Mcp\Tests\Client\Transport;

use PHPUnit\Framework\TestCase;
use Mcp\Client\Transport\HttpSessionManager;

/**
 * Tests for HttpSessionManager serialization (toArray/fromArray).
 *
 * Validates that session state can be serialized and restored for
 * persistence across PHP requests, enabling MCP session reuse.
 */
final class HttpSessionManagerTest extends TestCase
{
    /**
     * Test round-trip serialization preserves all fields.
     *
     * Sets all state fields on a manager, serializes to array via toArray(),
     * restores via fromArray(), and verifies all fields are preserved.
     */
    public function testRoundTripSerialization(): void
    {
        $manager = new HttpSessionManager();

        // Simulate initialization: process response headers with a session ID
        $manager->processResponseHeaders(
            ['mcp-session-id' => 'test-session-123'],
            200,
            true
        );
        $manager->updateLastEventId('event-42');
        $manager->setProtocolVersion('2025-11-25');

        // Serialize
        $data = $manager->toArray();

        // Verify array structure
        $this->assertArrayHasKey('sessionId', $data);
        $this->assertArrayHasKey('lastEventId', $data);
        $this->assertArrayHasKey('protocolVersion', $data);
        $this->assertArrayHasKey('initialized', $data);
        $this->assertArrayHasKey('invalidated', $data);

        $this->assertSame('test-session-123', $data['sessionId']);
        $this->assertSame('event-42', $data['lastEventId']);
        $this->assertSame('2025-11-25', $data['protocolVersion']);
        $this->assertTrue($data['initialized']);
        $this->assertFalse($data['invalidated']);

        // Restore
        $restored = HttpSessionManager::fromArray($data);

        // Verify restored state matches original
        $this->assertSame('test-session-123', $restored->getSessionId());
        $this->assertSame('event-42', $restored->getLastEventId());
        $this->assertSame('2025-11-25', $restored->getProtocolVersion());
        $this->assertTrue($restored->isInitialized());
        $this->assertFalse($restored->isInvalidated());
        $this->assertTrue($restored->isValid());
    }

    /**
     * Test that restored manager produces correct request headers.
     *
     * The Mcp-Session-Id header must be included in all requests after
     * session initialization. Last-Event-ID, by contrast, is a per-stream
     * resumption cursor and MUST NOT be emitted on session-wide request
     * headers — per the MCP Streamable HTTP transport spec it only belongs
     * on the specific GET that is resuming its originating stream. The
     * restored lastEventId is still preserved on the manager for anyone
     * who wants to read it via getLastEventId(), but it never leaks into
     * getRequestHeaders().
     */
    public function testRestoredManagerReturnsCorrectRequestHeaders(): void
    {
        $data = [
            'sessionId' => 'restored-session-456',
            'lastEventId' => 'evt-99',
            'initialized' => true,
            'invalidated' => false,
        ];

        $restored = HttpSessionManager::fromArray($data);
        $headers = $restored->getRequestHeaders();

        $this->assertArrayHasKey('Mcp-Session-Id', $headers);
        $this->assertSame('restored-session-456', $headers['Mcp-Session-Id']);
        $this->assertArrayNotHasKey(
            'Last-Event-ID',
            $headers,
            'Last-Event-ID must not be emitted on session-wide request headers'
        );
        $this->assertSame('evt-99', $restored->getLastEventId(), 'cursor is still tracked, just not in headers');
    }

    /**
     * Even after updateLastEventId() is called on a live manager, the cursor
     * must not appear in getRequestHeaders(). The cursor API remains available
     * for explicit SSE resume flows, but default request headers stay scoped to
     * session-wide state.
     */
    public function testUpdateLastEventIdDoesNotLeakIntoRequestHeaders(): void
    {
        $manager = new HttpSessionManager();
        $manager->processResponseHeaders(
            ['mcp-session-id' => 'session-abc'],
            200,
            true
        );

        $manager->updateLastEventId('event-17');

        $this->assertSame('event-17', $manager->getLastEventId());
        $headers = $manager->getRequestHeaders();
        $this->assertArrayNotHasKey('Last-Event-ID', $headers);
        $this->assertArrayHasKey('Mcp-Session-Id', $headers);
    }

    /**
     * Test fromArray handles missing/null fields gracefully.
     *
     * When deserializing data that may be incomplete (e.g., from an older
     * version), missing fields should default to safe values.
     */
    public function testFromArrayHandlesMissingFields(): void
    {
        $restored = HttpSessionManager::fromArray([]);

        $this->assertNull($restored->getSessionId());
        $this->assertNull($restored->getLastEventId());
        $this->assertNull($restored->getProtocolVersion());
        $this->assertFalse($restored->isInitialized());
        $this->assertFalse($restored->isInvalidated());
    }

    /**
     * Test that protocol version is included in request headers when set.
     *
     * Per the 2025-11-25 MCP spec, clients must include an MCP-Protocol-Version
     * header on all HTTP requests after initialization.
     */
    public function testProtocolVersionIncludedInHeaders(): void
    {
        $manager = new HttpSessionManager();
        $manager->setProtocolVersion('2025-11-25');

        $headers = $manager->getRequestHeaders();

        $this->assertArrayHasKey('MCP-Protocol-Version', $headers);
        $this->assertSame('2025-11-25', $headers['MCP-Protocol-Version']);
    }

    /**
     * Test that protocol version is preserved through toArray/fromArray serialization.
     */
    public function testProtocolVersionPreservedInSerialization(): void
    {
        $manager = new HttpSessionManager();
        $manager->setProtocolVersion('2025-11-25');

        $data = $manager->toArray();
        $this->assertSame('2025-11-25', $data['protocolVersion']);

        $restored = HttpSessionManager::fromArray($data);
        $this->assertSame('2025-11-25', $restored->getProtocolVersion());

        // Verify it also appears in headers after restoration
        $headers = $restored->getRequestHeaders();
        $this->assertArrayHasKey('MCP-Protocol-Version', $headers);
        $this->assertSame('2025-11-25', $headers['MCP-Protocol-Version']);
    }

    /**
     * Standalone GET stream cursor is tracked independently from the POST
     * stream cursor. Both must round-trip through toArray / fromArray so a
     * resumed session can resume both streams from their correct positions
     * without aliasing event ids that live in separate server-side
     * namespaces.
     */
    public function testStandaloneCursorRoundTrip(): void
    {
        $manager = new HttpSessionManager();
        $manager->processResponseHeaders(
            ['mcp-session-id' => 'session-xyz'],
            200,
            true
        );
        $manager->updateLastEventId('post-evt-3');
        $manager->updateStandaloneLastEventId('standalone-evt-9');

        $this->assertSame('post-evt-3', $manager->getLastEventId());
        $this->assertSame('standalone-evt-9', $manager->getStandaloneLastEventId());

        $snapshot = $manager->toArray();
        $this->assertSame('standalone-evt-9', $snapshot['standaloneLastEventId']);
        $this->assertSame('post-evt-3', $snapshot['lastEventId']);

        $restored = HttpSessionManager::fromArray($snapshot);
        $this->assertSame('post-evt-3', $restored->getLastEventId());
        $this->assertSame('standalone-evt-9', $restored->getStandaloneLastEventId());

        // Neither cursor leaks onto default request headers — both belong
        // only on an explicit resumption GET for their originating stream.
        $headers = $restored->getRequestHeaders();
        $this->assertArrayNotHasKey('Last-Event-ID', $headers);
    }

    /**
     * fromArray on a payload that predates the standalone cursor field
     * (e.g. persisted before this change landed) must default the cursor
     * to null rather than error, so older persisted sessions continue to
     * resume cleanly.
     */
    public function testFromArrayHandlesMissingStandaloneCursor(): void
    {
        $restored = HttpSessionManager::fromArray([
            'sessionId' => 'legacy-session',
            'lastEventId' => 'post-evt-1',
            'initialized' => true,
            'invalidated' => false,
        ]);

        $this->assertNull($restored->getStandaloneLastEventId());
    }

    /**
     * Test that invalidated state is preserved through serialization.
     */
    public function testInvalidatedStatePreserved(): void
    {
        $data = [
            'sessionId' => 'old-session',
            'lastEventId' => null,
            'initialized' => true,
            'invalidated' => true,
        ];

        $restored = HttpSessionManager::fromArray($data);

        $this->assertTrue($restored->isInvalidated());
        $this->assertFalse($restored->isValid());
        $this->assertTrue($restored->isInitialized());
    }
}
