<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Client\Transport\SseEventParser;
use Mcp\Server\Transport\Http\Config;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Http\Sse\SseSessionState;
use Mcp\Server\Transport\Http\Sse\StreamRegistry;
use Mcp\Server\Transport\HttpServerTransport;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use PHPUnit\Framework\TestCase;

/**
 * Anchors the streaming-SSE path added for MCP spec §5.6 alignment.
 *
 * The buffered emitSseResponse path already produces spec-correct wire bytes
 * but accumulates the body in memory AFTER the handler returns. Streaming
 * mode flushes each frame to the sink as writeMessage is called, so clients
 * observe progress notifications mid-handler — which is what spec §5.6.1's
 * "SHOULD immediately send" priming event implies.
 *
 * These tests inject a test sink so the emitted bytes are captured without
 * touching php://output or real flush() calls.
 */
final class HttpSseStreamingTest extends TestCase
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

    private function toolsCallBody(int $id, bool $withProgress = true): string
    {
        $params = ['name' => 'longtool', 'arguments' => (object) []];
        if ($withProgress) {
            $params['_meta'] = ['progressToken' => 'p-' . $id];
        }
        return (string) \json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => $params,
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
     * Drive an initialize handshake against the transport and simulate the
     * side-effect the runner normally persists on completion (marking the
     * session as `initializationState = Initialized`). Required because the
     * streaming tests bypass HttpServerRunner and follow-up POSTs carrying a
     * session id must be treated as belonging to a fully-initialized
     * session, otherwise the spec §5.8.2 gate rejects them.
     */
    private function openInitializedSession(HttpServerTransport $transport): \Mcp\Server\Transport\Http\HttpSession
    {
        $transport->handleRequest($this->postRequest($this->initBody(1)));
        $session = $transport->getLastUsedSession();
        if ($session === null) {
            throw new \RuntimeException('Transport did not open a session for initialize');
        }
        $session->setMetadata('mcp_server_session', [
            'initializationState' => 3, // InitializationState::Initialized
        ]);
        $transport->saveSession($session);
        return $session;
    }

    /**
     * Test sink that captures bytes written by the streaming path so the
     * exact SSE wire bytes are observable. emit() uses this instead of
     * php://output so the test doesn't need to shell out to a PHP server.
     */
    private function makeSink(): object
    {
        return new class {
            public string $buffer = '';
            public int $writes = 0;
            public function write(string $s): void
            {
                $this->buffer .= $s;
                $this->writes++;
            }
            public function aborted(): bool
            {
                return false;
            }
        };
    }

    /**
     * Config::shouldStream returns true only when SSE mode is auto/streaming
     * AND the POST body references a progressToken. Short roundtrips stay
     * on the buffered path to avoid header-flush overhead.
     */
    public function testShouldStreamOnlyForRequestsWithProgressToken(): void
    {
        $config = new Config(['enable_sse' => true, 'sse_mode' => 'auto']);

        $withToken = (string) \json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 't', '_meta' => ['progressToken' => 'abc']],
        ]);
        $withoutToken = (string) \json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list', 'params' => (object) [],
        ]);

        $this->assertTrue($config->shouldStream($withToken));
        $this->assertFalse($config->shouldStream($withoutToken));
    }

    /**
     * sse_mode=buffered short-circuits regardless of progressToken — the
     * legacy behavior is the fallback contract for constrained hosting.
     */
    public function testShouldStreamHonorsBufferedOverride(): void
    {
        $config = new Config(['enable_sse' => true, 'sse_mode' => 'buffered']);
        $body = (string) \json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['_meta' => ['progressToken' => 'x']],
        ]);

        $this->assertFalse($config->shouldStream($body));
    }

    /**
     * sse_mode=streaming forces streaming regardless of progressToken — but
     * still requires Environment::canStreamSse() (PHPUnit CLI env passes).
     */
    public function testShouldStreamHonorsStreamingOverride(): void
    {
        $config = new Config(['enable_sse' => true, 'sse_mode' => 'streaming']);
        $body = (string) \json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list',
        ]);

        $this->assertTrue($config->shouldStream($body));
    }

    /**
     * beginStreamingSseOutput flushes the priming frame (id + retry + empty
     * data) to the sink BEFORE any handler output. This is the observable
     * guarantee spec §5.6.1 asks for — the client has a Last-Event-ID in
     * hand before any payload events arrive.
     */
    public function testBeginStreamingEmitsPrimingFrameBeforeHandler(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
            'sse_mode' => 'streaming',
            'sse_retry_ms' => 3500,
        ]);
        $transport->start();

        $session = $this->openInitializedSession($transport);
        $toolsCall = $this->postRequest($this->toolsCallBody(5));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($streamId);

        $sink = $this->makeSink();
        $transport->beginStreamingSseOutput($session, $sink);

        // The priming frame is on the wire before any progress notification.
        $this->assertStringContainsString("id: {$streamId}:0", $sink->buffer);
        $this->assertStringContainsString('retry: 3500', $sink->buffer);
        $parser = new SseEventParser();
        $events = $parser->parseStreaming($sink->buffer);
        $this->assertCount(1, $events);
        $this->assertSame('', $events[0]['data']);
        $this->assertSame(3500, $events[0]['retry']);
    }

    /**
     * writeMessage calls during streaming mode flush a new frame to the sink
     * on every call instead of queuing for later buffered emission. This is
     * what makes progress notifications visible to the client while the
     * tool handler is still running.
     */
    public function testWriteMessageFlushesFramesIncrementallyWhileStreaming(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
            'sse_mode' => 'streaming',
        ]);
        $transport->start();

        $session = $this->openInitializedSession($transport);
        $toolsCall = $this->postRequest($this->toolsCallBody(11));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($streamId);

        $sink = $this->makeSink();
        $transport->beginStreamingSseOutput($session, $sink);
        $afterPriming = $sink->buffer;

        // First progress notification.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/progress',
            params: null,
        )));
        $afterFirst = $sink->buffer;
        $this->assertNotSame($afterPriming, $afterFirst, 'Progress 1 should flush to sink immediately');

        // Second progress notification.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/progress',
            params: null,
        )));
        $afterSecond = $sink->buffer;
        $this->assertNotSame($afterFirst, $afterSecond, 'Progress 2 should flush to sink immediately');

        // Final response.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(11),
            result: ['ok' => true],
        )));

        $response = $transport->finalizeStreamingSse($session);
        $this->assertSame('1', $response->getHeader('X-Mcp-Already-Emitted'));
        // Sentinel body is empty: all bytes already went to the sink.
        $this->assertSame('', $response->getBody() ?? '');

        // Parse the captured sink and verify order: priming, 2 progress, 1 final.
        $parser = new SseEventParser();
        $events = $parser->parseStreaming($sink->buffer);
        $this->assertCount(4, $events);
        $this->assertSame("{$streamId}:0", $events[0]['id']);
        $this->assertSame('', $events[0]['data']);
        $this->assertSame("{$streamId}:1", $events[1]['id']);
        $this->assertStringContainsString('notifications/progress', $events[1]['data']);
        $this->assertSame("{$streamId}:2", $events[2]['id']);
        $this->assertStringContainsString('notifications/progress', $events[2]['data']);
        $this->assertSame("{$streamId}:3", $events[3]['id']);
        $this->assertStringContainsString('"ok":true', $events[3]['data']);
    }

    /**
     * Each streamed frame is appended to the session's event log and the
     * session state is persisted per-frame, so a client reconnecting via
     * Last-Event-ID while the handler is still running can see the frames
     * that already reached the wire (requires a shared session store).
     */
    public function testStreamingPersistsFramesToEventLogPerFrame(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
            'sse_mode' => 'streaming',
        ]);
        $transport->start();

        $session = $this->openInitializedSession($transport);
        $toolsCall = $this->postRequest($this->toolsCallBody(21));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($streamId);

        $sink = $this->makeSink();
        $transport->beginStreamingSseOutput($session, $sink);

        // After priming, the log has one entry (seq 0).
        $state = SseSessionState::loadFrom($session);
        $replay = [];
        foreach ($state->getLog()->replaySince($streamId, -1) as $entry) {
            $replay[] = $entry;
        }
        $this->assertCount(1, $replay);

        $transport->writeMessage(new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/progress',
            params: null,
        )));

        // After one progress frame, the log has two entries (seq 0, 1).
        $state = SseSessionState::loadFrom($session);
        $replay = [];
        foreach ($state->getLog()->replaySince($streamId, -1) as $entry) {
            $replay[] = $entry;
        }
        $this->assertCount(2, $replay);
        $this->assertStringContainsString('notifications/progress', $replay[1]['frame']->data);
    }

    /**
     * finalizeStreamingSse synthesizes a JSON-RPC error frame when the
     * handler never emitted a final response — clients must not hang
     * waiting for a terminating event that will never come.
     */
    public function testFinalizeSynthesizesErrorFrameWhenHandlerSkippedResponse(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
            'sse_mode' => 'streaming',
        ]);
        $transport->start();

        $session = $this->openInitializedSession($transport);
        $toolsCall = $this->postRequest($this->toolsCallBody(31));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($streamId);

        $sink = $this->makeSink();
        $transport->beginStreamingSseOutput($session, $sink);

        // Handler emitted progress but no final response.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/progress',
            params: null,
        )));

        $transport->finalizeStreamingSse($session);

        $parser = new SseEventParser();
        $events = $parser->parseStreaming($sink->buffer);
        // Priming + progress + synthesized error frame.
        $this->assertCount(3, $events);
        $this->assertStringContainsString('-32603', $events[2]['data']);
        $this->assertStringContainsString('handler terminated without a response', $events[2]['data']);

        // Stream is marked completed so a client that reconnects via
        // Last-Event-ID will not be told to wait for more events.
        $state = SseSessionState::loadFrom($session);
        $record = $state->getRegistry()->find($streamId);
        $this->assertNotNull($record);
        $this->assertSame(StreamRegistry::STATUS_COMPLETED, $record['status']);
    }

    /**
     * Elicitation (and future sampling) handlers suspend by emitting a
     * server→client JSONRPCRequest and NOT sending a final response for the
     * originating tools/call. finalizeStreamingSse MUST detect this pattern
     * and leave the stream open rather than synthesizing a -32603 — the
     * real response will arrive on the follow-up POST that carries the
     * client's elicitation answer. This is the regression guard for the
     * bug raised during code review.
     */
    public function testFinalizePreservesSuspendFlowWhenHandlerEmitsServerRequest(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
            'sse_mode' => 'streaming',
        ]);
        $transport->start();

        $session = $this->openInitializedSession($transport);
        $toolsCall = $this->postRequest($this->toolsCallBody(51));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($streamId);

        $sink = $this->makeSink();
        $transport->beginStreamingSseOutput($session, $sink);

        // Simulate the suspend flow: a progress notification followed by a
        // server→client elicitation/create request with a SERVER-generated
        // request id (not the originating tools/call id). The handler then
        // returns without a final response for tools/call — that is the
        // documented suspend contract, not a failure.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCNotification(
            jsonrpc: '2.0',
            method: 'notifications/progress',
            params: null,
        )));
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(9001),
            method: 'elicitation/create',
            params: null,
        )));

        $transport->finalizeStreamingSse($session);

        // Wire: priming + progress + elicitation/create request. NO synthesized
        // error, NO terminating response for request id 51.
        $parser = new SseEventParser();
        $events = $parser->parseStreaming($sink->buffer);
        $this->assertCount(3, $events);
        $this->assertStringNotContainsString('-32603', $sink->buffer);
        $this->assertStringNotContainsString('"id":51', $sink->buffer);
        $this->assertStringContainsString('elicitation/create', $events[2]['data']);

        // Registry: stream left OPEN so the follow-up POST (carrying the
        // client's elicitation response) can continue appending frames
        // on the same stream id.
        $state = SseSessionState::loadFrom($session);
        $record = $state->getRegistry()->find($streamId);
        $this->assertNotNull($record);
        $this->assertSame(StreamRegistry::STATUS_OPEN, $record['status']);
    }

    /**
     * After a suspended stream is persisted, the follow-up POST (carrying
     * the client's elicitation response) causes the resumed handler to emit
     * a tools/call response. That response MUST be appended to the original
     * stream's log — not lost into the outgoing JSON-RPC queue — so a
     * Last-Event-ID reconnect can deliver it to the client. Regression
     * guard for the second code-review concern: "the follow-up POST
     * contains only a JSON-RPC response, so handlePostRequest returns 202
     * and does not select SSE/currentStreamId; after the runner resumes
     * the tool, the final sendResponse is delivered through the ordinary
     * response queue/createJsonResponse path instead of this open stream."
     */
    public function testResumedResponseRoutesToOriginalOpenStreamLog(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
            'sse_mode' => 'streaming',
        ]);
        $transport->start();

        $session = $this->openInitializedSession($transport);

        // Phase 1: original tools/call arrives on a streaming SSE POST,
        // handler suspends for elicitation (we simulate by writing the
        // elicitation/create request and NOT the final response).
        $toolsCall = $this->postRequest($this->toolsCallBody(601));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($streamId);

        $sink = $this->makeSink();
        $transport->beginStreamingSseOutput($session, $sink);
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(9101),
            method: 'elicitation/create',
            params: null,
        )));
        $transport->finalizeStreamingSse($session);

        // Stream is OPEN, originatingRequestId=601, lastSeq=1 (priming was seq 0).
        $state = SseSessionState::loadFrom($session);
        $record = $state->getRegistry()->find($streamId);
        $this->assertNotNull($record);
        $this->assertSame(StreamRegistry::STATUS_OPEN, $record['status']);
        $this->assertSame(601, $record['originatingRequestId']);

        // Phase 2: simulate the resumed handler emitting the final tools/call
        // response. In real flow this is called from start() during the
        // elicitation-response POST. The transport is NOT in streaming mode
        // on that follow-up POST (response-only POST takes the 202 path).
        // writeMessage must detect the matching open stream and append to
        // ITS log rather than queuing for createJsonResponse.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(601),
            result: ['resumed' => true],
        )));

        // The outgoing queue for this session stays empty — the response
        // was routed to the stream's log, not the queue. createJsonResponse
        // will therefore correctly return 202 on the elicitation-response
        // POST (spec §5.5.4 requires 202 for response-only POSTs).
        $jsonResponse = $transport->createJsonResponse($session);
        $this->assertSame(202, $jsonResponse->getStatusCode());

        // The original stream's log now contains the final response, and
        // the stream is marked completed so future replays know it's done.
        $state = SseSessionState::loadFrom($session);
        $record = $state->getRegistry()->find($streamId);
        $this->assertNotNull($record);
        $this->assertSame(StreamRegistry::STATUS_COMPLETED, $record['status']);
        $this->assertSame(2, $record['lastSeq']);

        $replayed = [];
        foreach ($state->getLog()->replaySince($streamId, 1) as $entry) {
            $replayed[] = $entry;
        }
        $this->assertCount(1, $replayed);
        $this->assertStringContainsString('"resumed":true', $replayed[0]['frame']->data);
        $this->assertSame("{$streamId}:2", $replayed[0]['frame']->id);
    }

    /**
     * During a resumed handler, progress notifications and chained
     * server→client requests MUST also route to the original open stream
     * — not just the final response. The spec ties notifications and
     * requests to the originating client request (§5.6.5), and if they
     * strand in the outgoing queue the response-only POST will either
     * return them on the wrong HTTP body or drop them entirely.
     *
     * This test simulates HttpServerSession::handleElicitationResponse
     * by driving setResumeContext() + writeMessage() directly, exercising
     * the context-based routing on all three message shapes.
     */
    public function testResumeContextRoutesNotificationsAndChainedRequestsToOpenStream(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
            'sse_mode' => 'streaming',
        ]);
        $transport->start();

        $session = $this->openInitializedSession($transport);

        // Phase 1: original tools/call, handler suspends after emitting
        // a progress notification + the elicitation/create request.
        $toolsCall = $this->postRequest($this->toolsCallBody(701));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($streamId);

        $sink = $this->makeSink();
        $transport->beginStreamingSseOutput($session, $sink);
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId(9201),
            method: 'elicitation/create',
            params: null,
        )));
        $transport->finalizeStreamingSse($session);

        // Phase 2: simulate handleElicitationResponse. The session sets a
        // resume context identifying the originating request, then the
        // resumed handler emits TWO things before its final response:
        //   - a progress notification (no id at all)
        //   - a chained elicitation/create request (server-generated id,
        //     NOT the originating tools/call id)
        // Both must route to the original stream's log.
        $transport->setResumeContext(701);
        try {
            // Progress notification during resumption.
            $transport->writeMessage(new JsonRpcMessage(new JSONRPCNotification(
                jsonrpc: '2.0',
                method: 'notifications/progress',
                params: null,
            )));
            // Chained server→client request during resumption.
            $transport->writeMessage(new JsonRpcMessage(new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(9202),
                method: 'elicitation/create',
                params: null,
            )));
            // Eventual final response for the original tools/call.
            $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(701),
                result: ['done' => true],
            )));
        } finally {
            $transport->setResumeContext(null);
        }

        // Outgoing queue must be empty so createJsonResponse returns 202
        // on the response-only POST (spec §5.5.4).
        $jsonResponse = $transport->createJsonResponse($session);
        $this->assertSame(202, $jsonResponse->getStatusCode());

        // Stream log: priming (0) + elicitation/create (1) from phase 1,
        // then during resume: progress (2), chained elicitation (3),
        // final response (4).
        $state = SseSessionState::loadFrom($session);
        $record = $state->getRegistry()->find($streamId);
        $this->assertNotNull($record);
        $this->assertSame(StreamRegistry::STATUS_COMPLETED, $record['status']);
        $this->assertSame(4, $record['lastSeq']);

        $replayed = [];
        foreach ($state->getLog()->replaySince($streamId, 1) as $entry) {
            $replayed[] = $entry;
        }
        $this->assertCount(3, $replayed, 'Resume phase should add 3 frames');
        $this->assertStringContainsString('notifications/progress', $replayed[0]['frame']->data);
        $this->assertStringContainsString('elicitation/create', $replayed[1]['frame']->data);
        $this->assertStringContainsString('"done":true', $replayed[2]['frame']->data);
        $this->assertSame("{$streamId}:2", $replayed[0]['frame']->id);
        $this->assertSame("{$streamId}:3", $replayed[1]['frame']->id);
        $this->assertSame("{$streamId}:4", $replayed[2]['frame']->id);
    }

    /**
     * Resume context clears cleanly even when the resumed handler throws.
     * If it didn't, subsequent requests' writes would incorrectly route
     * to a stale stream. HttpServerSession wraps the handler call in a
     * finally block; this test pins that contract at the transport level.
     */
    public function testResumeContextClearsAfterUseEvenOnFailure(): void
    {
        $transport = new HttpServerTransport(['enable_sse' => true]);
        $transport->start();

        $session = $this->openInitializedSession($transport);

        $transport->setResumeContext(42);
        // Writing outside any open stream with context set: the routing
        // tries appendToResumeStream, finds no matching open stream, and
        // falls through to the outgoing queue — so ordinary behavior is
        // preserved when the context doesn't match.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(42),
            result: ['ok' => true],
        )));
        $transport->setResumeContext(null);

        // After clearing, a subsequent write must behave normally (queue).
        $response = $transport->createJsonResponse($session);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"ok":true', (string) $response->getBody());
    }

    /**
     * A response whose id doesn't match any open stream still goes through
     * the outgoing queue (existing behavior) — this protects pure JSON-mode
     * flows that never opened an SSE stream in the first place.
     */
    public function testResponseWithNoMatchingStreamFallsBackToQueue(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
        ]);
        $transport->start();

        // Open a JSON-mode session (no SSE stream created).
        $init = $this->postRequest($this->initBody(1));
        $init->setHeader('Accept', 'application/json');
        $transport->handleRequest($init);
        $session = $transport->getLastUsedSession();
        $this->assertNotNull($session);
        $session->setMetadata('mcp_server_session', [
            'initializationState' => 3,
        ]);
        $transport->saveSession($session);

        // Write a response. No registry entry matches its id, so it should
        // flow through the outgoing queue and surface via createJsonResponse.
        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(1),
            result: ['ok' => true],
        )));

        $response = $transport->createJsonResponse($session);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"ok":true', (string) $response->getBody());
    }

    /**
     * When the final response IS emitted normally, finalizeStreamingSse
     * does not synthesize an error. The registry is marked completed with
     * the actual response's sequence number.
     */
    public function testFinalizeMarksCompletedOnNormalResponse(): void
    {
        $transport = new HttpServerTransport([
            'enable_sse' => true,
            'sse_mode' => 'streaming',
        ]);
        $transport->start();

        $session = $this->openInitializedSession($transport);
        $toolsCall = $this->postRequest($this->toolsCallBody(41));
        $toolsCall->setHeader('Mcp-Session-Id', $session->getId());
        $transport->handleRequest($toolsCall);
        $streamId = $transport->currentStreamId();
        $this->assertNotNull($streamId);

        $sink = $this->makeSink();
        $transport->beginStreamingSseOutput($session, $sink);

        $transport->writeMessage(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(41),
            result: ['ok' => true],
        )));

        $transport->finalizeStreamingSse($session);

        $parser = new SseEventParser();
        $events = $parser->parseStreaming($sink->buffer);
        $this->assertCount(2, $events); // priming + response; no synthesized error

        $state = SseSessionState::loadFrom($session);
        $record = $state->getRegistry()->find($streamId);
        $this->assertNotNull($record);
        $this->assertSame(StreamRegistry::STATUS_COMPLETED, $record['status']);
        $this->assertSame(1, $record['lastSeq']);
    }
}
