<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\InitializationOptions;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\Version;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\Role;
use Mcp\Types\SamplingMessage;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end verification of the stdio sampling round-trip.
 *
 * In stdio mode, `sendSamplingRequest()` blocks on the matching response via
 * `BaseSession::sendRequest()`. Covers:
 *   - Serialization: the outgoing sampling/createMessage request carries the
 *     expected method, messages, and maxTokens in its params body.
 *   - Response parsing: the client's JSON result populates a typed
 *     `CreateMessageResult` with the correct role, model, stopReason, and
 *     content.
 *   - Capability gating: returns null cleanly when the client didn't declare
 *     sampling, and again when the client declared plain `sampling` but the
 *     request asks for tool-enabled sampling (`sampling.tools` absent).
 */
final class ServerSessionSamplingStdioTest extends TestCase
{
    public function testSamplingRoundTripReturnsTypedResult(): void
    {
        $session = $this->buildInitializedSession($transport);

        $transport->enqueue(new JsonRpcMessage(
            new JSONRPCResponse(
                jsonrpc: '2.0',
                id: new RequestId(0),
                result: [
                    'role' => 'assistant',
                    'content' => ['type' => 'text', 'text' => 'The capital is Paris.'],
                    'model' => 'test-model',
                    'stopReason' => 'endTurn',
                ],
            ),
        ));

        $result = $session->sendSamplingRequest(
            messages: [
                new SamplingMessage(
                    role: Role::USER,
                    content: new TextContent(text: 'What is the capital of France?'),
                ),
            ],
            maxTokens: 100,
        );

        $this->assertInstanceOf(CreateMessageResult::class, $result);
        $this->assertSame('test-model', $result->model);
        $this->assertSame('endTurn', $result->stopReason);
        $this->assertSame(Role::ASSISTANT, $result->role);
        $this->assertInstanceOf(TextContent::class, $result->content);
        $this->assertSame('The capital is Paris.', $result->content->text);

        // The wire request should contain the full payload, not an empty body.
        $this->assertNotEmpty($transport->written);
        $outgoing = $transport->written[0]->message;
        $this->assertInstanceOf(JSONRPCRequest::class, $outgoing);
        $this->assertSame('sampling/createMessage', $outgoing->method);
        $params = $outgoing->params;
        $this->assertNotNull($params, 'sampling/createMessage must have params');
        $serialized = $params->jsonSerialize();
        $serialized = is_array($serialized) ? $serialized : (array) $serialized;
        $this->assertSame(100, $serialized['maxTokens'] ?? null);
        $this->assertNotEmpty($serialized['messages'] ?? null, 'messages must be present in the outgoing request');
    }

    public function testSamplingReturnsNullWhenClientDidNotAdvertiseCapability(): void
    {
        $session = $this->buildInitializedSession($transport, includeSampling: false);

        $result = $session->sendSamplingRequest(
            messages: [
                new SamplingMessage(
                    role: Role::USER,
                    content: new TextContent(text: 'anything'),
                ),
            ],
            maxTokens: 50,
        );

        $this->assertNull($result);
        // No request should have been written — we bailed before sending.
        $this->assertEmpty($transport->written);
    }

    public function testToolsRequestedWithoutSamplingToolsCapabilityRefused(): void
    {
        // Client declares plain `sampling` but not `sampling.tools`. Tool-enabled
        // sampling returns null without writing to the transport so the caller
        // can choose a fallback explicitly.
        $session = $this->buildInitializedSession($transport, includeSampling: true);

        $fakeTool = new \Mcp\Types\Tool(
            name: 'lookup',
            inputSchema: new \Mcp\Types\ToolInputSchema(),
            description: 'test',
        );

        $result = $session->sendSamplingRequest(
            messages: [new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi'))],
            maxTokens: 10,
            tools: [$fakeTool],
        );

        $this->assertNull($result, 'sendSamplingRequest must refuse tool-enabled sampling without sampling.tools');
        $this->assertEmpty($transport->written, 'no sampling request should go out when refused');
    }

    /**
     * @param-out SamplingQueueTransport $transport
     */
    private function buildInitializedSession(?Transport &$transport, bool $includeSampling = true): ServerSession
    {
        $transport = new SamplingQueueTransport();
        $session = new ServerSession(
            $transport,
            new InitializationOptions(
                serverName: 'sampling-test',
                serverVersion: '1.0.0',
                capabilities: new ServerCapabilities(),
            ),
        );
        $session->registerHandlers([]);
        $session->registerNotificationHandlers([]);

        $capabilities = $includeSampling ? ['sampling' => []] : [];

        $transport->enqueue(new JsonRpcMessage(
            new JSONRPCRequest(
                jsonrpc: '2.0',
                id: new RequestId(1),
                method: 'initialize',
                params: new RawInitializeParamsWithSampling(
                    protocolVersion: Version::LATEST_PROTOCOL_VERSION,
                    capabilities: $capabilities,
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

        // Drain handshake messages so the next sendRequest() is what we're testing.
        $handle->invoke($session, $transport->readMessage());
        $handle->invoke($session, $transport->readMessage());

        // Clear the written queue so test assertions only see sampling traffic.
        $transport->written = [];

        return $session;
    }
}

final class SamplingQueueTransport implements Transport
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

final class RawInitializeParamsWithSampling extends RequestParams
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
