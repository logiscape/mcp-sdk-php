<?php

declare(strict_types=1);

namespace Mcp\Tests\Client\Transport;

use PHPUnit\Framework\TestCase;
use Mcp\Client\Transport\HttpConfiguration;
use Mcp\Client\Transport\StreamableHttpTransport;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
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
     * Invoke the private processSseResponse() via reflection.
     *
     * @return array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool, lastRetryMs: ?int, lastEventId: ?string, gotResponseForRequest: bool}
     */
    private function invokeProcessSseResponse(
        StreamableHttpTransport $transport,
        string $body,
        JsonRpcMessage $outbound
    ): array {
        $method = new ReflectionMethod($transport, 'processSseResponse');
        $method->setAccessible(true);
        /** @var array{statusCode: int, headers: array<string, string>, body: string, isEventStream: bool, lastRetryMs: ?int, lastEventId: ?string, gotResponseForRequest: bool} $result */
        $result = $method->invoke($transport, $body, [], 200, $outbound);
        return $result;
    }
}
