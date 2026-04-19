<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Client\Transport\SseEventParser;
use Mcp\Server\Transport\Http\Sse\SseFrame;
use Mcp\Server\Transport\Http\Sse\SseSessionState;
use Mcp\Server\Transport\Http\Sse\StreamId;
use Mcp\Server\Transport\Http\Sse\StreamRegistry;
use Mcp\Server\Transport\HttpServerTransport;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for GET + Last-Event-ID resumption and standalone GET.
 *
 * The spec invariant this test file anchors: when a client reconnects via
 * GET with Last-Event-ID, the server MAY replay events on the stream that
 * was disconnected and MUST NOT replay events from a different stream.
 */
final class HttpSseResumeTest extends TestCase
{
    private function initBody(int $id = 1): string
    {
        return (string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ]);
    }

    private function postRequest(string $body): HttpMessage
    {
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', 'application/json, text/event-stream');
        return $request;
    }

    /**
     * Simulate the side-effect the HttpServerRunner normally persists after
     * initialize completes: mark the transport's session as initialized so
     * follow-up GET/DELETE requests pass the spec §5.8.2 strict-session gate.
     * Tests that talk directly to the transport (bypassing the runner) need
     * this helper because the runner is where the mcp_server_session
     * metadata normally lands.
     */
    private function markSessionInitialized(HttpServerTransport $transport): void
    {
        $session = $transport->getLastUsedSession();
        if ($session === null) {
            return;
        }
        $session->setMetadata('mcp_server_session', [
            'initializationState' => 3, // InitializationState::Initialized
        ]);
        $transport->saveSession($session);
    }

    private function getRequest(string $sessionId, ?string $lastEventId = null): HttpMessage
    {
        $request = new HttpMessage();
        $request->setMethod('GET');
        $request->setUri('/');
        $request->setHeader('Accept', 'text/event-stream');
        $request->setHeader('Mcp-Session-Id', $sessionId);
        if ($lastEventId !== null) {
            $request->setHeader('Last-Event-ID', $lastEventId);
        }
        return $request;
    }

    /**
     * Simulate a POST that emitted N events, then a GET reconnect with a
     * cursor mid-stream. The reply MUST contain only the events with
     * seq > cursor, in order, from the SAME stream id.
     */
    public function testGetWithLastEventIdReplaysEventsPastCursor(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        // Kick off a POST so we have a session + SSE stream.
        $postResp = $transport->handleRequest($this->postRequest($this->initBody(10)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $session = $transport->getLastUsedSession();
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($sessionId);
        $this->assertNotNull($session);
        $this->assertNotNull($streamId);
        $this->markSessionInitialized($transport);

        // Manually seed the event log with several post-priming events.
        $state = SseSessionState::loadFrom($session);
        // Make sure the registry has this stream recorded (emitSseResponse
        // would do this; we skip to test the replay path in isolation).
        $state->getRegistry()->open($streamId, StreamRegistry::KIND_POST, 10);
        $state->getLog()->append($streamId, 0, new SseFrame("{$streamId}:0", null, 1500, ''));
        $state->getLog()->append($streamId, 1, new SseFrame("{$streamId}:1", null, null, '{"event":"one"}'));
        $state->getLog()->append($streamId, 2, new SseFrame("{$streamId}:2", null, null, '{"event":"two"}'));
        $state->getLog()->append($streamId, 3, new SseFrame("{$streamId}:3", null, null, '{"event":"three"}'));
        $state->saveTo($session);
        $transport->saveSession($session);

        // GET with Last-Event-ID = cursor 1 → expect events seq 2 and 3.
        $resp = $transport->handleRequest($this->getRequest($sessionId, "{$streamId}:1"));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('text/event-stream', $resp->getHeader('Content-Type'));

        $body = $resp->getBody() ?? '';
        $parser = new SseEventParser();
        $events = $parser->parseStreaming($body);

        $this->assertCount(2, $events);
        $this->assertSame("{$streamId}:2", $events[0]['id']);
        $this->assertStringContainsString('two', $events[0]['data']);
        $this->assertSame("{$streamId}:3", $events[1]['id']);
        $this->assertStringContainsString('three', $events[1]['data']);
    }

    /**
     * Critical spec invariant: events from stream A MUST NOT leak into a
     * replay triggered for stream B. StreamEventLog::replaySince enforces
     * this at the storage layer; this test anchors the transport-level
     * contract against future regressions.
     */
    public function testReplayDoesNotLeakOtherStreams(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $postResp = $transport->handleRequest($this->postRequest($this->initBody(20)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $session = $transport->getLastUsedSession();
        $this->assertNotNull($session);
        $this->markSessionInitialized($transport);

        $streamA = 'streamA';
        $streamB = 'streamB';
        $state = SseSessionState::loadFrom($session);
        $state->getRegistry()->open($streamA, StreamRegistry::KIND_POST, 1);
        $state->getRegistry()->open($streamB, StreamRegistry::KIND_POST, 2);
        $state->getLog()->append($streamA, 1, new SseFrame("$streamA:1", null, null, '{"from":"a"}'));
        $state->getLog()->append($streamB, 1, new SseFrame("$streamB:1", null, null, '{"from":"b"}'));
        $state->getLog()->append($streamA, 2, new SseFrame("$streamA:2", null, null, '{"from":"a2"}'));
        $state->getLog()->append($streamB, 2, new SseFrame("$streamB:2", null, null, '{"from":"b2"}'));
        $state->saveTo($session);
        $transport->saveSession($session);

        $resp = $transport->handleRequest($this->getRequest($sessionId, "$streamA:0"));

        $body = $resp->getBody() ?? '';
        $this->assertStringContainsString('"from":"a"', $body);
        $this->assertStringContainsString('"from":"a2"', $body);
        $this->assertStringNotContainsString('"from":"b"', $body);
        $this->assertStringNotContainsString('"from":"b2"', $body);
    }

    /**
     * An unknown stream id (or one from a different session) returns 404 so
     * the client knows to reinitialize, not silently succeed with zero events.
     */
    public function testReplayUnknownStreamReturns404(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $postResp = $transport->handleRequest($this->postRequest($this->initBody(30)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $this->assertNotNull($sessionId);
        $this->markSessionInitialized($transport);

        $resp = $transport->handleRequest($this->getRequest($sessionId, 'unknown-stream:5'));
        $this->assertSame(404, $resp->getStatusCode());
    }

    /**
     * A malformed Last-Event-ID (missing separator, non-numeric seq, etc.)
     * yields 400 rather than being silently ignored. Catching the garbage
     * early means clients can notice their error instead of waiting for
     * an empty SSE response.
     */
    public function testReplayMalformedLastEventIdReturns400(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $postResp = $transport->handleRequest($this->postRequest($this->initBody(40)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $this->assertNotNull($sessionId);
        $this->markSessionInitialized($transport);

        $resp = $transport->handleRequest($this->getRequest($sessionId, 'not-a-cursor'));
        $this->assertSame(400, $resp->getStatusCode());
    }

    /**
     * GET with no Last-Event-ID opens a standalone stream: priming event,
     * retry hint, stream is recorded in the registry, connection closes.
     * PHP-FPM has no background worker to keep idle GET streams alive.
     */
    public function testStandaloneGetEmitsPrimingAndClosesImmediately(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true, 'sse_retry_ms' => 2500]);
        $transport->start();

        $postResp = $transport->handleRequest($this->postRequest($this->initBody(50)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $this->assertNotNull($sessionId);
        $this->markSessionInitialized($transport);

        $resp = $transport->handleRequest($this->getRequest($sessionId, null));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('text/event-stream', $resp->getHeader('Content-Type'));

        $body = $resp->getBody() ?? '';
        $this->assertStringContainsString('retry: 2500', $body);

        // Parse to confirm exactly one event (the priming empty-data event)
        // was emitted and the standalone GET closed without further output.
        $parser = new SseEventParser();
        $events = $parser->parseStreaming($body);
        $this->assertCount(1, $events);
        $this->assertSame('', $events[0]['data']);
        $this->assertSame(2500, $events[0]['retry']);

        // The standalone stream is persisted in the registry as KIND_GET.
        $session = $transport->getLastUsedSession();
        $this->assertNotNull($session);
        $state = SseSessionState::loadFrom($session);
        $records = $state->getRegistry()->all();
        $hasGetStream = false;
        foreach ($records as $rec) {
            if ($rec['kind'] === StreamRegistry::KIND_GET) {
                $hasGetStream = true;
                break;
            }
        }
        $this->assertTrue($hasGetStream, 'Standalone GET should be registered as KIND_GET');
    }

    /**
     * With sse_standalone_get_idle_ms > 0, the standalone GET holds the
     * request open for that window, drains any server-initiated messages
     * queued for the session, and emits them as SSE events alongside the
     * priming frame. Guards against the option being silently ignored.
     */
    public function testStandaloneGetHonorsIdleWindowAndEmitsQueuedMessages(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
            'sse_standalone_get_idle_ms' => 300,
        ]);
        $transport->start();

        $postResp = $transport->handleRequest($this->postRequest($this->initBody(80)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $this->assertNotNull($sessionId);
        $this->markSessionInitialized($transport);

        // Seed an outgoing message for this session before the GET fires.
        // writeMessage routes to the current session id set by the POST.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(80),
            result: ['queued' => 'during-idle'],
        )));

        $start = \hrtime(true);
        $resp = $transport->handleRequest($this->getRequest($sessionId, null));
        $elapsedMs = (\hrtime(true) - $start) / 1_000_000;

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('text/event-stream', $resp->getHeader('Content-Type'));

        $body = $resp->getBody() ?? '';
        $parser = new SseEventParser();
        $events = $parser->parseStreaming($body);

        // Priming + the queued message: two events on the same stream id.
        $this->assertCount(2, $events);
        $this->assertSame('', $events[0]['data']);
        $this->assertStringContainsString('"queued":"during-idle"', $events[1]['data']);

        [$streamId, $primingSeq] = \explode(':', $events[0]['id'], 2);
        $this->assertSame('0', $primingSeq);
        $this->assertSame("{$streamId}:1", $events[1]['id']);

        // The loop should break within a few ticks of the deadline; well under
        // a second even on slow CI. Guards against runaway sleeps.
        $this->assertLessThan(1000.0, $elapsedMs, 'Idle window should terminate promptly');

        // The queued message is appended to the log so a later Last-Event-ID
        // reconnect on this stream can replay it.
        $session = $transport->getLastUsedSession();
        $this->assertNotNull($session);
        $state = SseSessionState::loadFrom($session);
        $replayed = [];
        foreach ($state->getLog()->replaySince($streamId, 0) as $entry) {
            $replayed[] = $entry;
        }
        $this->assertCount(1, $replayed);
        $this->assertStringContainsString('"queued":"during-idle"', $replayed[0]['frame']->data);
    }

    /**
     * GET with SSE disabled returns 405 with an Allow header listing the
     * methods the endpoint actually supports — POST and DELETE.
     */
    public function testGetWithSseDisabledReturns405WithAllowHeader(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => false]);
        $transport->start();

        // Establish a session via a JSON-mode POST.
        $postResp = $transport->handleRequest($this->postRequest($this->initBody(60)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $this->assertNotNull($sessionId);

        $resp = $transport->handleRequest($this->getRequest($sessionId, "streamX:0"));
        $this->assertSame(405, $resp->getStatusCode());
        $this->assertSame('POST, DELETE', $resp->getHeader('Allow'));
    }

    /**
     * A completed stream's replay contains only the events emitted before
     * completion. The final JSON-RPC response is part of that log, so a
     * client that reconnects after missing it will pick it up.
     */
    public function testReplayOfCompletedStreamIncludesFinalResponse(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $postResp = $transport->handleRequest($this->postRequest($this->initBody(70)));
        $sessionId = $postResp->getHeader('Mcp-Session-Id');
        $session = $transport->getLastUsedSession();
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($session);
        $this->assertNotNull($streamId);
        $this->markSessionInitialized($transport);

        // Emit a real response through the normal path so the log is populated.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(70),
            result: ['ok' => true],
        )));
        $transport->emitSseResponse($session);
        $transport->saveSession($session);

        // Client "missed" the whole POST and reconnects from cursor -1
        // (equivalent to Last-Event-ID: <streamId>:0 — past priming only).
        $resp = $transport->handleRequest($this->getRequest($sessionId, "{$streamId}:0"));

        $this->assertSame(200, $resp->getStatusCode());
        $body = $resp->getBody() ?? '';
        $this->assertStringContainsString('"ok":true', $body);
    }
}
