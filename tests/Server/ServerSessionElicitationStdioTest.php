<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\StdioServerTransport;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\Version;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\Meta;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\Result;
use Mcp\Types\ServerCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the server-side elicitation round-trip.
 *
 * Server transports parse incoming JSON-RPC responses and hand them to
 * BaseSession via JsonRpcMessage. Prior to the fix:
 *   - Both server transports wrapped the decoded result in a generic
 *     Mcp\Types\Result object, which BaseSession::sendRequest() then passed
 *     straight into $resultType::fromResponseData() — a method typed
 *     `array $data`. That caused a hard TypeError on every elicitation
 *     response a real stdio server received.
 *   - HttpServerTransport additionally assigned a raw decoded array to
 *     Result::$_meta, a typed `?Meta` property, raising a TypeError on any
 *     response that carried metadata.
 *   - StdioServerTransport silently dropped _meta from incoming responses.
 *
 * The fix passes the raw decoded array through as JSONRPCResponse::result
 * (with a defensive normalizer in BaseSession that still flattens any
 * Result-wrapped legacy shape). These tests lock that contract in place.
 */
final class ServerSessionElicitationStdioTest extends TestCase
{
    /**
     * The happy path: transport passes the raw decoded array (matching the
     * post-fix StdioServerTransport / HttpServerTransport behavior). The
     * response carries an _meta block, which must round-trip through to the
     * typed ElicitationCreateResult.
     */
    public function testElicitationResponseAsArrayRoundTripsWithMeta(): void
    {
        $session = $this->buildInitializedSession($transport);

        $rawResult = [
            'action' => 'accept',
            'content' => ['name' => 'Alice'],
            '_meta' => ['requestTraceId' => 'abc-123'],
        ];

        $transport->enqueue(new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(0),
                result: $rawResult,
            ),
        ));

        $result = $session->sendElicitationRequest(
            message: 'What is your name?',
            requestedSchema: [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
        );

        $this->assertInstanceOf(ElicitationCreateResult::class, $result);
        $this->assertSame('accept', $result->action);
        $this->assertSame(['name' => 'Alice'], $result->content);
        $this->assertNotNull($result->_meta, '_meta payload must round-trip into the typed result');
        $this->assertSame('abc-123', $result->_meta->requestTraceId);
    }

    /**
     * Legacy compatibility: any pre-existing transport (or test double) that
     * still wraps the result in a generic Result object must keep working
     * because BaseSession's normalizer flattens it. Verifies that protected
     * extraFields and a typed Meta object are both recovered.
     */
    public function testElicitationResponseWrappedInResultObjectIsHandled(): void
    {
        $session = $this->buildInitializedSession($transport);

        $resultObj = new Result(_meta: $this->buildMeta(['requestTraceId' => 'abc-123']));
        $resultObj->action = 'accept';
        $resultObj->content = ['name' => 'Alice'];

        $transport->enqueue(new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(0),
                result: $resultObj,
            ),
        ));

        $result = $session->sendElicitationRequest(
            message: 'What is your name?',
            requestedSchema: [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
        );

        $this->assertInstanceOf(ElicitationCreateResult::class, $result);
        $this->assertSame('accept', $result->action);
        $this->assertSame(['name' => 'Alice'], $result->content);
        $this->assertNotNull($result->_meta);
        $this->assertSame('abc-123', $result->_meta->requestTraceId);
    }

    /**
     * Drive a real elicitation response through StdioServerTransport's
     * actual JSON parser, not a hand-built JsonRpcMessage. This catches any
     * future regression where the transport reverts to wrapping the result
     * in a Result object (and incidentally proves _meta survives the round
     * trip end-to-end).
     */
    public function testStdioServerTransportPreservesMetaOnIncomingResponse(): void
    {
        $transport = StdioServerTransport::create();

        $payload = [
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => [
                'action' => 'accept',
                'content' => ['name' => 'Alice'],
                '_meta' => ['requestTraceId' => 'abc-123'],
            ],
        ];

        $ref = new \ReflectionClass(StdioServerTransport::class);
        $method = $ref->getMethod('instantiateSingleMessage');
        $method->setAccessible(true);

        /** @var JsonRpcMessage $message */
        $message = $method->invoke($transport, $payload);
        $inner = $message->message;

        $this->assertInstanceOf(JSONRPCResponse::class, $inner);
        $this->assertIsArray($inner->result, 'result must be an array, not a wrapped Result object');
        $this->assertSame('accept', $inner->result['action']);
        $this->assertSame(['name' => 'Alice'], $inner->result['content']);
        $this->assertSame(['requestTraceId' => 'abc-123'], $inner->result['_meta']);
    }

    /**
     * Set up a fully-initialized ServerSession with a queue-backed transport
     * and an already-completed initialize handshake. The session is ready
     * to call sendElicitationRequest().
     *
     * @param-out QueueingTransport $transport
     */
    private function buildInitializedSession(?Transport &$transport): ServerSession
    {
        $transport = new QueueingTransport();
        $session = new ServerSession(
            $transport,
            new InitializationOptions(
                serverName: 'elicit-test',
                serverVersion: '1.0.0',
                capabilities: new ServerCapabilities(),
            ),
        );
        $session->registerHandlers([]);
        $session->registerNotificationHandlers([]);

        $transport->enqueue(new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(1),
                method: 'initialize',
                params: new RawInitializeParamsWithElicitation(
                    protocolVersion: Version::LATEST_PROTOCOL_VERSION,
                    capabilities: ['elicitation' => []],
                    clientInfo: ['name' => 'test-client', 'version' => '1.0.0'],
                ),
            ),
        ));
        $transport->enqueue(new JsonRpcMessage(
            new \Mcp\Types\JSONRPCNotification(
                jsonrpc: '2.0',
                method: 'notifications/initialized',
                params: null,
            ),
        ));

        $ref = new \ReflectionClass(ServerSession::class);
        $handle = $ref->getMethod('handleIncomingMessage');
        $handle->setAccessible(true);

        // Drain the two handshake messages so allowClientResponses is the
        // only thing gating the next sendRequest() call.
        $handle->invoke($session, $transport->readMessage());
        $handle->invoke($session, $transport->readMessage());

        return $session;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function buildMeta(array $values): Meta
    {
        $meta = new Meta();
        foreach ($values as $k => $v) {
            $meta->$k = $v;
        }
        return $meta;
    }
}

/**
 * Queue-backed transport: returns pre-enqueued messages from readMessage()
 * in FIFO order and captures writes.
 */
final class QueueingTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    public array $written = [];

    /** @var JsonRpcMessage[] */
    private array $incoming = [];

    public function enqueue(JsonRpcMessage $message): void
    {
        $this->incoming[] = $message;
    }

    public function start(): void {}
    public function stop(): void {}

    public function readMessage(): ?JsonRpcMessage
    {
        return array_shift($this->incoming);
    }

    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->written[] = $message;
    }
}

/**
 * Raw initialize params that serialize to wire-format arrays, with an
 * elicitation capability so the server accepts sendElicitationRequest().
 */
final class RawInitializeParamsWithElicitation extends RequestParams
{
    /**
     * @param array<string, mixed> $capabilities
     * @param array<string, string> $clientInfo
     */
    public function __construct(
        private readonly string $protocolVersion,
        private readonly array $capabilities,
        private readonly array $clientInfo,
    ) {
        parent::__construct();
    }

    public function validate(): void {}

    public function jsonSerialize(): mixed
    {
        return [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities,
            'clientInfo' => $this->clientInfo,
        ];
    }
}
