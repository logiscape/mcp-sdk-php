<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Client\Transport\SseEventParser;
use Mcp\Server\Transport\Http\Sse\SseSessionState;
use Mcp\Server\Transport\Http\Sse\StreamId;
use Mcp\Server\Transport\Http\Sse\StreamRegistry;
use Mcp\Server\Transport\HttpServerTransport;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the POST SSE response path wired in HttpServerTransport.
 *
 * The tests cover the two halves of the POST SSE flow:
 *  - mode selection (handleRequest detecting Accept + enable_sse + capability
 *    and flipping lastResponseMode to 'sse'),
 *  - emission (emitSseResponse draining the outgoing queue into a
 *    priming + N events SSE body that the client's SseEventParser can read).
 */
final class HttpSseEmitTest extends TestCase
{
    private function initBody(int|string $id = 1): string
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

    private function postRequest(string $body, string $accept = 'application/json, text/event-stream'): HttpMessage
    {
        $request = new HttpMessage($body);
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('Accept', $accept);
        return $request;
    }

    /**
     * When enable_sse is true, canSupportSse passes (CLI env), and the client
     * sent Accept: text/event-stream on a POST carrying a JSON-RPC request,
     * the transport flips into SSE mode and mints a stream id.
     */
    public function testHandleRequestSelectsSseMode(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $resp = $transport->handleRequest($this->postRequest($this->initBody()));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('sse', $transport->lastResponseMode());
        $this->assertNotNull($transport->currentStreamId());
    }

    /**
     * Without text/event-stream in Accept the transport stays on the JSON
     * path regardless of enable_sse, preserving backwards compatibility.
     */
    public function testHandleRequestWithoutSseAcceptStaysInJsonMode(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $resp = $transport->handleRequest($this->postRequest($this->initBody(), 'application/json'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNull($transport->lastResponseMode());
        $this->assertNull($transport->currentStreamId());
    }

    /**
     * With enable_sse=false, SSE is never selected even if the client asks
     * for it — the flag is the gate, not the client preference.
     */
    public function testHandleRequestWithoutEnableSseStaysInJsonMode(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => false]);
        $transport->start();

        $resp = $transport->handleRequest($this->postRequest($this->initBody()));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNull($transport->lastResponseMode());
    }

    /**
     * A POST whose body contains only notifications (no requests) continues
     * to take the 202 Accepted path — there's nothing to respond to, so
     * upgrading to SSE would be gratuitous. A session id from a prior
     * initialize is needed to get past the session-management gate.
     */
    public function testNotificationOnlyPostDoesNotSelectSse(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        // Establish a session first via an initialize POST (JSON mode, to
        // isolate this test from SSE selection on the initialize leg).
        $init = $transport->handleRequest($this->postRequest($this->initBody(), 'application/json'));
        $sessionId = $init->getHeader('Mcp-Session-Id');
        $this->assertNotNull($sessionId);

        // Now send a notification-only POST with the session id.
        $body = (string) \json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progressToken' => 't', 'progress' => 0.5],
        ]);
        $notif = $this->postRequest($body);
        $notif->setHeader('Mcp-Session-Id', $sessionId);

        $resp = $transport->handleRequest($notif);

        $this->assertSame(202, $resp->getStatusCode());
        $this->assertNull($transport->lastResponseMode());
    }

    /**
     * emitSseResponse produces a priming event (seq 0, empty data, retry),
     * followed by one event per queued outgoing message with incrementing
     * seq. The SSE body parses cleanly with the SDK's own client parser.
     */
    public function testEmitSseResponseDrainsQueueAsOrderedEvents(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true, 'sse_retry_ms' => 2000]);
        $transport->start();

        // Drive handleRequest once so the transport mints a stream id and
        // remembers session + originating request.
        $transport->handleRequest($this->postRequest($this->initBody(42)));
        $session = $transport->getLastUsedSession();
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($session);
        $this->assertNotNull($streamId);

        // Simulate handler output: one notification + one final JSON-RPC response.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/progress',
            params: null,
        )));
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(42),
            result: ['ok' => true],
        )));

        $response = $transport->emitSseResponse($session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeader('Content-Type'));
        $this->assertSame('no-cache, no-transform', $response->getHeader('Cache-Control'));
        $this->assertSame('no', $response->getHeader('X-Accel-Buffering'));

        $body = $response->getBody();
        $this->assertNotNull($body);
        $this->assertStringContainsString('retry: 2000', $body);
        $this->assertStringContainsString("id: {$streamId}:0", $body);
        $this->assertStringContainsString("id: {$streamId}:1", $body);
        $this->assertStringContainsString("id: {$streamId}:2", $body);
        $this->assertStringContainsString('"ok":true', $body);

        // Parse the body with the client-side parser to confirm wire compatibility.
        $parser = new SseEventParser();
        $events = $parser->parseStreaming($body);
        // Expect: priming (empty data), progress notification, final response.
        $this->assertCount(3, $events);
        $this->assertSame("{$streamId}:0", $events[0]['id']);
        $this->assertSame('', $events[0]['data']);
        $this->assertSame("{$streamId}:1", $events[1]['id']);
        $this->assertStringContainsString('notifications/progress', $events[1]['data']);
        $this->assertSame("{$streamId}:2", $events[2]['id']);
        $this->assertStringContainsString('"ok":true', $events[2]['data']);
        $this->assertSame(2000, $events[0]['retry']);
    }

    /**
     * When an emitted event carries the JSON-RPC response for the stream's
     * originating request, the stream is marked completed in the registry.
     * This is what stops the emitter from appending further events and lets
     * future GET-based replay know the response was already delivered.
     */
    public function testEmitSseResponseMarksStreamCompletedOnFinalResponse(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $transport->handleRequest($this->postRequest($this->initBody(99)));
        $session = $transport->getLastUsedSession();
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($session);
        $this->assertNotNull($streamId);

        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(99),
            result: ['done' => true],
        )));

        $transport->emitSseResponse($session);

        $state = SseSessionState::loadFrom($session);
        $record = $state->getRegistry()->find($streamId);
        $this->assertNotNull($record);
        $this->assertSame(StreamRegistry::STATUS_COMPLETED, $record['status']);
        $this->assertSame(1, $record['lastSeq']);
    }

    /**
     * Emitted frames are appended to the session's StreamEventLog so that a
     * subsequent GET + Last-Event-ID can replay them.
     */
    public function testEmitSseResponsePersistsFramesToEventLog(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $transport->handleRequest($this->postRequest($this->initBody(7)));
        $session = $transport->getLastUsedSession();
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($session);
        $this->assertNotNull($streamId);

        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(7),
            result: ['x' => 1],
        )));

        $transport->emitSseResponse($session);

        $state = SseSessionState::loadFrom($session);
        $replayed = $state->getLog()->replaySince($streamId, -1);
        // Priming (seq 0) + response (seq 1) = 2 entries
        $this->assertCount(2, $replayed);
        $this->assertSame(0, $replayed[0]['seq']);
        $this->assertSame(1, $replayed[1]['seq']);
        $this->assertStringContainsString('"x":1', $replayed[1]['frame']->data);
    }

    /**
     * If handlePostRequest never set SSE mode (e.g. direct call path misuse),
     * emitSseResponse falls back to the JSON response path rather than
     * emitting a malformed stream with no id. This is defensive — the runner
     * always honors lastResponseMode, so in practice this branch is unused.
     */
    public function testEmitSseResponseFallsBackToJsonWhenStreamIdMissing(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        // Go through a non-SSE request to get a session; do NOT trigger SSE mode.
        $transport->handleRequest($this->postRequest($this->initBody(), 'application/json'));
        $session = $transport->getLastUsedSession();
        $this->assertNotNull($session);
        $this->assertNull($transport->currentStreamId());

        $response = $transport->emitSseResponse($session);
        // Content-Type falls back to JSON (or empty for 202).
        $this->assertNotSame('text/event-stream', $response->getHeader('Content-Type'));
    }
}
