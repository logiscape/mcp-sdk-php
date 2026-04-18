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

    /**
     * Cursor scope 'standalone' seeds from the standalone cursor and writes
     * back to the standalone slot only. The POST stream's cursor must remain
     * untouched — each stream has its own server-side event-id namespace, so
     * aliasing the two would cause a compliant server to replay events on
     * the wrong stream on resume.
     */
    public function testStandaloneCursorScopeIsolatesFromPostCursor(): void
    {
        $manager = HttpSessionManager::fromArray([
            'sessionId' => 'sess-1',
            'lastEventId' => 'post-evt-1',
            'standaloneLastEventId' => 'standalone-evt-1',
            'initialized' => true,
            'invalidated' => false,
        ]);

        $connection = new SseConnection(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            sessionManager: $manager,
            cursorScope: 'standalone'
        );

        // Seeded from the standalone slot, not the POST slot.
        $this->assertSame('standalone-evt-1', $connection->getLastEventId());

        // Simulate an incoming event on the standalone stream.
        $processEvent = new ReflectionMethod($connection, 'processEvent');
        $processEvent->setAccessible(true);
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/message',
            'params' => [],
        ]);
        $processEvent->invoke($connection, "id: standalone-evt-2\nevent: message\ndata: {$payload}", false);

        $this->assertSame('standalone-evt-2', $manager->getStandaloneLastEventId());
        // POST cursor is untouched.
        $this->assertSame('post-evt-1', $manager->getLastEventId());
    }

    /**
     * With a message dispatcher configured, server-initiated requests on
     * the stream are handed to the dispatcher synchronously. This is the
     * path the standalone GET stream uses so an elicitation/create fired
     * by the server while a POST is in flight reaches the session without
     * waiting for the read loop's next idle tick.
     */
    public function testForegroundMessageDispatcherReceivesParsedMessage(): void
    {
        $manager = new HttpSessionManager();
        $received = [];
        $dispatcher = static function ($message) use (&$received): void {
            $received[] = $message;
        };

        $connection = new SseConnection(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            sessionManager: $manager,
            messageDispatcher: $dispatcher,
            cursorScope: 'standalone'
        );

        $processEvent = new ReflectionMethod($connection, 'processEvent');
        $processEvent->setAccessible(true);
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 42,
            'method' => 'elicitation/create',
            'params' => [
                'message' => 'test',
                'requestedSchema' => ['type' => 'object', 'properties' => []],
            ],
        ]);
        $processEvent->invoke($connection, "id: evt-1\nevent: message\ndata: {$payload}", false);

        $this->assertCount(1, $received, 'dispatcher should be called exactly once');
        $this->assertInstanceOf(\Mcp\Types\JsonRpcMessage::class, $received[0]);
    }

    /**
     * The standalone stream constructor surfaces the Mcp-Session-Id +
     * MCP-Protocol-Version headers via extraHeaders so the GET carries
     * enough session context to pass the spec's post-init request
     * requirements.
     */
    public function testStandaloneStreamMergesExtraSessionHeaders(): void
    {
        $manager = new HttpSessionManager();
        $manager->processResponseHeaders(
            ['mcp-session-id' => 'session-xyz'],
            200,
            true
        );
        $manager->setProtocolVersion('2025-11-25');

        $connection = new SseConnection(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            sessionManager: $manager,
            extraHeaders: ['Last-Event-ID' => 'resume-evt'],
            cursorScope: 'standalone'
        );

        $prepare = new ReflectionMethod($connection, 'prepareRequestHeaders');
        $prepare->setAccessible(true);
        /** @var list<string> $headers */
        $headers = $prepare->invoke($connection);

        $this->assertContains('Mcp-Session-Id: session-xyz', $headers);
        $this->assertContains('MCP-Protocol-Version: 2025-11-25', $headers);
        $this->assertContains('Last-Event-ID: resume-evt', $headers);
        $this->assertContains('Accept: text/event-stream', $headers);
    }

    /**
     * When the server declines the standalone stream (e.g. 405 Method Not
     * Allowed, the spec-sanctioned way to say "I don't offer this"),
     * responseStatusIndicatesLiveStream must surface it so the connection
     * marks itself declined and the transport can tear it down instead of
     * spinning.
     */
    public function testResponseStatusIndicatesLiveStreamRejectsNon2xx(): void
    {
        $connection = new SseConnection(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            sessionManager: new HttpSessionManager(),
            cursorScope: 'standalone'
        );

        $status = new \ReflectionProperty($connection, 'responseStatus');
        $status->setAccessible(true);
        $ct = new \ReflectionProperty($connection, 'responseContentType');
        $ct->setAccessible(true);
        $indicates = new ReflectionMethod($connection, 'responseStatusIndicatesLiveStream');
        $indicates->setAccessible(true);

        $status->setValue($connection, 405);
        $this->assertFalse($indicates->invoke($connection));

        $status->setValue($connection, 200);
        $ct->setValue($connection, 'application/json');
        $this->assertFalse($indicates->invoke($connection), '200 with wrong content-type is not a live stream');

        $status->setValue($connection, 200);
        $ct->setValue($connection, 'text/event-stream; charset=utf-8');
        $this->assertTrue($indicates->invoke($connection));
    }
}
