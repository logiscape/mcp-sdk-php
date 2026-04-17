<?php

declare(strict_types=1);

namespace Mcp\Tests\Client\Transport;

use PHPUnit\Framework\TestCase;
use Mcp\Client\Transport\HttpConfiguration;
use Mcp\Client\Transport\HttpSessionManager;
use Mcp\Client\Transport\SseConnection;
use ReflectionMethod;

/**
 * Tests that a restored session cursor flows into SseConnection's connection-local
 * state so the first SSE GET can resume via Last-Event-ID.
 *
 * Background: HttpSessionManager preserves lastEventId through toArray/fromArray
 * for session restore, but getRequestHeaders() deliberately excludes the cursor
 * so it does not leak onto unrelated POSTs / DELETEs. That means SseConnection
 * must seed its own $lastEventId from the session manager at construction —
 * otherwise restored state cannot reach the actual SSE GET.
 */
final class SseConnectionResumeTest extends TestCase
{
    /**
     * When the session manager carries a restored cursor (from a prior
     * fromArray() call), a newly constructed SseConnection must inherit it
     * so its first resume GET includes Last-Event-ID.
     */
    public function testConstructorSeedsCursorFromSessionManager(): void
    {
        $manager = HttpSessionManager::fromArray([
            'sessionId' => 'restored-session',
            'lastEventId' => 'restored-event-17',
            'initialized' => true,
            'invalidated' => false,
        ]);

        $connection = new SseConnection(
            new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            $manager
        );

        $this->assertSame('restored-event-17', $connection->getLastEventId());
    }

    /**
     * The GET headers built by SseConnection::prepareRequestHeaders() must
     * include Last-Event-ID once the connection has a restored cursor, so
     * the server can replay events from the correct position.
     */
    public function testSeededCursorFlowsIntoSsePrepareRequestHeaders(): void
    {
        $manager = HttpSessionManager::fromArray([
            'sessionId' => 'restored-session',
            'lastEventId' => 'evt-99',
            'initialized' => true,
            'invalidated' => false,
        ]);

        $connection = new SseConnection(
            new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            $manager
        );

        $prepare = new ReflectionMethod($connection, 'prepareRequestHeaders');
        $prepare->setAccessible(true);
        /** @var list<string> $curlHeaders */
        $curlHeaders = $prepare->invoke($connection);

        $lastEventIdHeaders = array_values(array_filter(
            $curlHeaders,
            static fn(string $h) => str_starts_with($h, 'Last-Event-ID:')
        ));

        $this->assertCount(1, $lastEventIdHeaders, 'exactly one Last-Event-ID header should be set');
        $this->assertSame('Last-Event-ID: evt-99', $lastEventIdHeaders[0]);
    }

    /**
     * A session manager with no restored cursor must result in an SseConnection
     * that also has no cursor — i.e. the initial GET omits Last-Event-ID, as
     * expected for a fresh stream.
     */
    public function testNullSessionCursorResultsInNullConnectionCursor(): void
    {
        $manager = new HttpSessionManager();

        $connection = new SseConnection(
            new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            $manager
        );

        $this->assertNull($connection->getLastEventId());
    }

    /**
     * When SseConnection processes an event in foreground mode, the event id
     * MUST be written back to the shared session manager so that a subsequent
     * toArray() captures the latest cursor (not the stale one that was present
     * at construction). This is the flow a webclient relies on to serialize
     * session state across requests and then resume via resumeHttpSession()
     * from the most recent event.
     *
     * Safety: the write-back does not reintroduce the earlier header leak
     * because HttpSessionManager::getRequestHeaders() deliberately excludes
     * Last-Event-ID from session-wide request headers — verified below.
     */
    public function testEventProcessingUpdatesSessionManagerForToArray(): void
    {
        $manager = new HttpSessionManager();
        $manager->processResponseHeaders(
            ['mcp-session-id' => 'session-abc'],
            200,
            true
        );

        $connection = new SseConnection(
            new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            $manager
        );

        // Simulate the server pushing a real message event through the parser.
        $processEvent = new ReflectionMethod($connection, 'processEvent');
        $processEvent->setAccessible(true);
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/message',
            'params' => [],
        ]);
        $processEvent->invoke($connection, "id: evt-42\nevent: message\ndata: {$payload}", false);

        $this->assertSame('evt-42', $connection->getLastEventId(), 'connection-local cursor advances');
        $this->assertSame(
            'evt-42',
            $manager->getLastEventId(),
            'session manager cursor must also advance so toArray() captures the latest position'
        );

        // toArray round-trip preserves the updated cursor.
        $snapshot = $manager->toArray();
        $this->assertSame('evt-42', $snapshot['lastEventId']);
        $restored = HttpSessionManager::fromArray($snapshot);
        $this->assertSame('evt-42', $restored->getLastEventId());

        // And the write-back must NOT reintroduce the earlier header leak —
        // getRequestHeaders() still excludes Last-Event-ID.
        $headers = $manager->getRequestHeaders();
        $this->assertArrayNotHasKey('Last-Event-ID', $headers);
    }
}
