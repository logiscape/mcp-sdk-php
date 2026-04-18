<?php

declare(strict_types=1);

namespace Mcp\Tests\Client\Transport;

use PHPUnit\Framework\TestCase;
use Mcp\Client\Transport\HttpConfiguration;
use Mcp\Client\Transport\StreamableHttpTransport;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCNotification;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use ReflectionMethod;

/**
 * Behavioral tests for the SSE POST response handling in the Streamable HTTP
 * transport.
 *
 * These tests call the private processSseResponse() via reflection because the
 * SSE parser and cursor-tracking semantics must be verified independently of
 * the curl I/O layer, which is exercised end-to-end by the conformance tests.
 */
final class StreamableHttpTransportSseTest extends TestCase
{
    /**
     * When a POST SSE response carries event ids (priming events for
     * resumption), the transport MUST keep those ids in method-local state
     * only. Writing them into the shared session manager causes
     * getRequestHeaders() to emit `Last-Event-ID` on every subsequent
     * request - including unrelated POSTs and DELETEs - which can mislead a
     * compliant server into replaying messages on the wrong stream.
     */
    public function testProcessSseResponseDoesNotWriteCursorIntoSessionManager(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );
        $outbound = $this->buildRequestMessage(42);

        $sseBody = "id: event-1\nretry: 500\ndata: \n\n";
        $result = $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $this->assertSame('event-1', $result['lastEventId'], 'cursor should be reported back to caller');
        $this->assertSame(500, $result['lastRetryMs']);
        $this->assertFalse($result['gotResponseForRequest']);
        $this->assertNull(
            $transport->getSessionManager()->getLastEventId(),
            'POST SSE event ids must not be written into the shared session manager'
        );
    }

    /**
     * When the POST SSE body contains the JSON-RPC response for our request
     * inline (no reconnect needed), gotResponseForRequest must be true and
     * the cursor still must NOT leak into the session manager.
     */
    public function testInlineResponseIsRecognizedWithoutPollutingSessionManager(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );
        $outbound = $this->buildRequestMessage(7);

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['tools' => []],
        ]);
        $sseBody = "event: message\nid: evt-99\ndata: {$payload}\n\n";
        $result = $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $this->assertTrue($result['gotResponseForRequest']);
        $this->assertSame('evt-99', $result['lastEventId']);
        $this->assertNull($transport->getSessionManager()->getLastEventId());
    }

    /**
     * A priming event that omits `retry:` must still surface lastEventId so
     * the caller can resume. The retry fallback is applied by the caller
     * (awaitResponseViaReconnect) from HttpConfiguration; processSseResponse
     * simply reports lastRetryMs=null.
     */
    public function testPrimingWithoutRetryReportsNullRetryButKeepsCursor(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );
        $outbound = $this->buildRequestMessage(1);

        $sseBody = "id: event-a\ndata: \n\n";
        $result = $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $this->assertSame('event-a', $result['lastEventId']);
        $this->assertNull($result['lastRetryMs']);
        $this->assertFalse($result['gotResponseForRequest']);
    }

    /**
     * Per the MCP Streamable HTTP spec (revision 2025-11-25), the server MAY
     * send JSON-RPC notifications interleaved with — and before — the final
     * response on the POST SSE stream. The transport must surface those
     * notifications to the read loop, not silently discard them.
     */
    public function testProcessSseResponseEnqueuesNotificationBeforeResponse(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );
        $outbound = $this->buildRequestMessage(11);

        $progress = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progressToken' => 'tok-1', 'progress' => 0.5],
        ]);
        $response = json_encode([
            'jsonrpc' => '2.0',
            'id' => 11,
            'result' => ['ok' => true],
        ]);
        $sseBody =
            "event: message\nid: evt-a\ndata: {$progress}\n\n" .
            "event: message\nid: evt-b\ndata: {$response}\n\n";

        $result = $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $this->assertTrue($result['gotResponseForRequest']);

        $first = $transport->receiveFromHttp();
        $this->assertNotNull($first);
        $this->assertInstanceOf(JSONRPCNotification::class, $first->message);
        $this->assertSame('notifications/progress', $first->message->method);

        $second = $transport->receiveFromHttp();
        $this->assertNotNull($second);
        $this->assertInstanceOf(JSONRPCResponse::class, $second->message);
        $this->assertSame(11, $second->message->id->getValue());
    }

    /**
     * The server MAY also send server-initiated requests (e.g.
     * sampling/createMessage, elicitation/create, ping) on the POST SSE
     * stream. They must be enqueued as JSONRPCRequest, and they MUST NOT
     * trigger the in-flight-response signal because they are not the
     * response to our outbound request.
     */
    public function testProcessSseResponseEnqueuesServerInitiatedRequest(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );
        $outbound = $this->buildRequestMessage(20);

        $serverRequest = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'srv-1',
            'method' => 'sampling/createMessage',
            'params' => ['messages' => []],
        ]);
        $sseBody = "event: message\nid: evt-1\ndata: {$serverRequest}\n\n";

        $result = $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $this->assertFalse($result['gotResponseForRequest']);
        $this->assertSame('evt-1', $result['lastEventId']);

        $msg = $transport->receiveFromHttp();
        $this->assertNotNull($msg);
        $this->assertInstanceOf(JSONRPCRequest::class, $msg->message);
        $this->assertSame('sampling/createMessage', $msg->message->method);
        $this->assertSame('srv-1', $msg->message->id->getValue());
    }

    /**
     * If a server-initiated request happens to carry an id that numerically
     * matches our outbound request id, the transport must NOT treat it as the
     * response — otherwise the SEP-1699 reconnect loop would terminate
     * prematurely and the real response would never be observed.
     */
    public function testServerRequestWithCollidingIdDoesNotCountAsResponse(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );
        $outbound = $this->buildRequestMessage(7);

        // Server sends a request that happens to use id=7 too — same numeric
        // value as our outbound id, but it is a request (has `method`), not
        // a response (no `result` / `error`).
        $serverRequest = json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'ping',
        ]);
        $sseBody = "event: message\nid: evt-x\ndata: {$serverRequest}\n\n";

        $result = $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $this->assertFalse(
            $result['gotResponseForRequest'],
            'a server-initiated request with a colliding id must not satisfy the in-flight wait'
        );

        $msg = $transport->receiveFromHttp();
        $this->assertNotNull($msg);
        $this->assertInstanceOf(JSONRPCRequest::class, $msg->message);
        $this->assertSame('ping', $msg->message->method);
    }

    /**
     * When a synchronous message dispatcher is registered, server-initiated
     * requests parsed off the SSE stream MUST be dispatched immediately
     * rather than queued for the read loop. Otherwise BaseSession would not
     * be able to service a sampling/elicitation request that the server is
     * holding the POST SSE stream open to wait for.
     */
    public function testRegisteredDispatcherReceivesServerInitiatedRequestImmediately(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );

        /** @var list<JsonRpcMessage> $dispatched */
        $dispatched = [];
        $transport->setMessageDispatcher(static function (JsonRpcMessage $msg) use (&$dispatched): void {
            $dispatched[] = $msg;
        });

        $outbound = $this->buildRequestMessage(33);
        $serverRequest = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'srv-9',
            'method' => 'sampling/createMessage',
            'params' => ['messages' => []],
        ]);
        $sseBody = "event: message\nid: evt-z\ndata: {$serverRequest}\n\n";

        $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $this->assertCount(1, $dispatched, 'request must be dispatched, not queued');
        $this->assertInstanceOf(JSONRPCRequest::class, $dispatched[0]->message);
        $this->assertSame('sampling/createMessage', $dispatched[0]->message->method);
        $this->assertNull(
            $transport->receiveFromHttp(),
            'dispatched request must not also land in the pending queue'
        );
    }

    /**
     * Notifications follow the same dispatch path as requests when a
     * dispatcher is registered.
     */
    public function testRegisteredDispatcherReceivesNotificationImmediately(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );

        /** @var list<JsonRpcMessage> $dispatched */
        $dispatched = [];
        $transport->setMessageDispatcher(static function (JsonRpcMessage $msg) use (&$dispatched): void {
            $dispatched[] = $msg;
        });

        $outbound = $this->buildRequestMessage(34);
        $progress = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progressToken' => 't', 'progress' => 0.25],
        ]);
        $sseBody = "event: message\nid: evt-1\ndata: {$progress}\n\n";

        $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(JSONRPCNotification::class, $dispatched[0]->message);
        $this->assertSame('notifications/progress', $dispatched[0]->message->method);
        $this->assertNull($transport->receiveFromHttp());
    }

    /**
     * Responses to the in-flight request must continue to be queued for the
     * BaseSession waitForResponse loop to consume — they are not delivered
     * via the synchronous dispatch path because the response handler is
     * already wired into the response-id table.
     */
    public function testDispatcherIsNotInvokedForResponses(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );

        $dispatched = 0;
        $transport->setMessageDispatcher(static function (JsonRpcMessage $msg) use (&$dispatched): void {
            $dispatched++;
        });

        $outbound = $this->buildRequestMessage(50);
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 50,
            'result' => ['ok' => true],
        ]);
        $sseBody = "event: message\nid: evt-r\ndata: {$payload}\n\n";

        $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $this->assertSame(0, $dispatched, 'responses must NOT be sent through the synchronous dispatcher');
        $msg = $transport->receiveFromHttp();
        $this->assertNotNull($msg);
        $this->assertInstanceOf(JSONRPCResponse::class, $msg->message);
    }

    /**
     * If the dispatcher itself throws, the message must fall back to the
     * pending queue rather than being lost. The error is logged.
     */
    public function testDispatcherFailureFallsBackToPendingQueue(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );

        $transport->setMessageDispatcher(static function (JsonRpcMessage $msg): void {
            throw new \RuntimeException('handler exploded');
        });

        $outbound = $this->buildRequestMessage(60);
        $progress = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progressToken' => 't', 'progress' => 1.0],
        ]);
        $sseBody = "event: message\nid: evt-q\ndata: {$progress}\n\n";

        $this->invokeProcessSseResponse($transport, $sseBody, $outbound);

        $msg = $transport->receiveFromHttp();
        $this->assertNotNull($msg, 'message must not be lost when dispatcher throws');
        $this->assertInstanceOf(JSONRPCNotification::class, $msg->message);
    }

    /**
     * Even if a cursor exists in the shared session manager, normal request
     * headers must not include Last-Event-ID. That header is only valid on an
     * explicit SSE resumption GET.
     */
    public function testPrepareRequestHeadersNeverEmitsLastEventIdFromSharedState(): void
    {
        $transport = new StreamableHttpTransport(
            config: new HttpConfiguration(endpoint: 'http://localhost/mcp'),
            autoSse: false
        );
        $sessionManager = $transport->getSessionManager();
        $sessionManager->processResponseHeaders(
            ['mcp-session-id' => 'session-abc'],
            200,
            true
        );
        $sessionManager->updateLastEventId('cursor-from-some-other-stream');

        $prepare = new ReflectionMethod($transport, 'prepareRequestHeaders');
        $prepare->setAccessible(true);
        /** @var list<string> $curlHeaders */
        $curlHeaders = $prepare->invoke($transport, []);

        foreach ($curlHeaders as $header) {
            $this->assertStringStartsNotWith(
                'Last-Event-ID:',
                $header,
                'Last-Event-ID must never leak into default request headers'
            );
        }
    }

    /**
     * A resumed GET is NOT required to deliver a fresh priming event on
     * every attempt. Once the server has primed the stream (event id on the
     * POST SSE), subsequent GETs may close/time out with zero events and
     * the client must simply reconnect again with the same Last-Event-ID
     * and the current retry interval. The loop terminates only when the
     * response arrives or the wall-clock budget is exhausted.
     */
    public function testAwaitResponseViaReconnectKeepsPollingWhenGetsMakeNoProgress(): void
    {
        // Script three mock GETs: two close without any event, the third
        // delivers the matching response.
        $mockedGets = [
            [null, null, null],
            [null, null, null],
            [[
                'statusCode' => 200,
                'headers' => [],
                'body' => '',
                'isEventStream' => true,
            ], null, null],
        ];

        $transport = $this->buildTransportWithScriptedReconnect($mockedGets);
        $result = $this->invokeAwaitReconnect($transport, 1, 10, 'event-0');

        $this->assertSame(200, $result['statusCode']);
        // All three attempts used the same cursor because the server never
        // sent a fresh id — the original POST-SSE cursor carried through.
        $this->assertSame(['event-0', 'event-0', 'event-0'], $transport->cursorsSeen);
        $this->assertCount(3, $transport->cursorsSeen, 'loop should continue across no-progress GETs');
    }

    /**
     * When the server does send a new priming event id on a resumed GET, the
     * cursor advances and the next attempt uses the newer id. This confirms
     * the spec-described "server may disconnect and re-prime" flow works.
     */
    public function testAwaitResponseViaReconnectAdoptsUpdatedCursorAndRetry(): void
    {
        $mockedGets = [
            // First GET: server sends a new priming event but no response.
            [null, 250, 'event-1'],
            // Second GET: response arrives.
            [[
                'statusCode' => 200,
                'headers' => [],
                'body' => '',
                'isEventStream' => true,
            ], null, null],
        ];

        $transport = $this->buildTransportWithScriptedReconnect($mockedGets);
        $result = $this->invokeAwaitReconnect($transport, 42, 10, 'event-0');

        $this->assertSame(200, $result['statusCode']);
        $this->assertSame(['event-0', 'event-1'], $transport->cursorsSeen);
        // The retry interval used for the second sleep was updated from 10 to 250.
        $this->assertSame([10, 250], $transport->retriesSeen);
    }

    /**
     * With a tight wall-clock budget and a server that never delivers, the
     * loop terminates with a descriptive RuntimeException rather than
     * polling forever.
     */
    public function testAwaitResponseViaReconnectHonorsWallClockBudget(): void
    {
        // Scripted responses are infinite "no progress". Budget exhaustion
        // is the bound; the exact attempt count depends on timing.
        $transport = $this->buildTransportWithScriptedReconnect(
            scripted: [],
            defaultResponse: [null, null, null],
            // Budget set via HttpConfiguration below.
            budgetSeconds: 0.05
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/reconnect budget.*exhausted/');
        $this->invokeAwaitReconnect($transport, 99, 10, 'event-0');
    }

    /**
     * Build an anonymous subclass of StreamableHttpTransport that replaces
     * performReconnectGet() with a scripted stub, so the loop logic can be
     * tested without real HTTP I/O.
     *
     * @param list<array{0: ?array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool}, 1: ?int, 2: ?string}> $scripted
     * @param array{0: ?array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool}, 1: ?int, 2: ?string}|null $defaultResponse
     */
    private function buildTransportWithScriptedReconnect(
        array $scripted,
        ?array $defaultResponse = null,
        float $budgetSeconds = 10.0
    ): StreamableHttpTransport {
        $config = new HttpConfiguration(
            endpoint: 'http://localhost/mcp',
            sseDefaultRetryDelay: 0.01,
            sseReconnectBudget: $budgetSeconds
        );

        return new class ($config, $scripted, $defaultResponse) extends StreamableHttpTransport {
            /** @var list<string> */
            public array $cursorsSeen = [];
            /** @var list<int> */
            public array $retriesSeen = [];

            /**
             * @param list<array{0: ?array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool}, 1: ?int, 2: ?string}> $scripted
             * @param array{0: ?array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool}, 1: ?int, 2: ?string}|null $defaultResponse
             */
            public function __construct(
                HttpConfiguration $config,
                private array $scripted,
                private ?array $defaultResponse
            ) {
                parent::__construct(config: $config, autoSse: false);
            }

            protected function performReconnectGet(
                int|string $requestId,
                string $cursor,
                int $retryMs,
                int $remainingBudgetMs
            ): array {
                $this->cursorsSeen[] = $cursor;
                $this->retriesSeen[] = $retryMs;
                if (!empty($this->scripted)) {
                    return array_shift($this->scripted);
                }
                if ($this->defaultResponse !== null) {
                    return $this->defaultResponse;
                }
                throw new \LogicException('scripted transport ran out of responses');
            }
        };
    }

    /**
     * @return array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool}
     */
    private function invokeAwaitReconnect(
        StreamableHttpTransport $transport,
        int|string $requestId,
        ?int $retryMs,
        string $lastEventId
    ): array {
        $method = new ReflectionMethod($transport, 'awaitResponseViaReconnect');
        $method->setAccessible(true);
        /** @var array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool} $result */
        $result = $method->invoke($transport, $requestId, $retryMs, $lastEventId);
        return $result;
    }

    /**
     * Build a JSONRPCRequest wrapped in a JsonRpcMessage for testing.
     */
    private function buildRequestMessage(int $id): JsonRpcMessage
    {
        $inner = new JSONRPCRequest(
            jsonrpc: '2.0',
            id: new RequestId($id),
            params: null,
            method: 'tools/list'
        );
        return new JsonRpcMessage($inner);
    }

    /**
     * Drive the shared SSE chunk consumer with a fully-buffered SSE body, as
     * if the entire response had landed in one cURL write callback. Returns
     * the final cursor state so tests can assert on lastEventId / lastRetryMs
     * / gotResponseForRequest.
     *
     * @return array{lastEventId: ?string, lastRetryMs: ?int, gotResponseForRequest: bool}
     */
    private function invokeProcessSseResponse(
        StreamableHttpTransport $transport,
        string $body,
        JsonRpcMessage $outbound
    ): array {
        $method = new ReflectionMethod($transport, 'consumeSseChunk');
        $method->setAccessible(true);
        $extract = new ReflectionMethod($transport, 'extractOutboundRequestId');
        $extract->setAccessible(true);
        $outboundRequestId = $extract->invoke($transport, $outbound);

        $buffer = '';
        $cursor = [
            'lastEventId' => null,
            'lastRetryMs' => null,
            'gotResponseForRequest' => false,
        ];
        $args = [$body, &$buffer, &$cursor, $outboundRequestId];
        $method->invokeArgs($transport, $args);

        return $cursor;
    }
}
