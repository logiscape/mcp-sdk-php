<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Mcp\Server\HttpServerSession;
use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\Sampling\PendingSampling;
use Mcp\Server\Sampling\SamplingContext;
use Mcp\Server\Sampling\SamplingSuspendException;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\RequestResponder;
use Mcp\Types\CallToolRequest;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ClientRequest;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\Role;
use Mcp\Types\SamplingCapability;
use Mcp\Types\SamplingMessage;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\TextContent;
use Psr\Log\NullLogger;

/**
 * Integration test for HTTP suspend/resume of sampling/createMessage.
 *
 * Exercises the same surface the conformance `tools-call-sampling` scenario
 * hits in HTTP mode:
 *   1. Tool handler throws SamplingSuspendException (via SamplingContext).
 *   2. HttpServerSession writes the sampling/createMessage request to the
 *      transport and records a PendingSampling entry.
 *   3. A subsequent message carrying the client's CreateMessageResult is
 *      matched against the pending state; the tool handler is re-invoked with
 *      preloaded results and its response is written back under the original
 *      tools/call request id.
 */
final class SamplingHttpIntegrationTest extends TestCase
{
    private function createInitOptions(): InitializationOptions
    {
        return new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
    }

    private function createInitializedSession(SamplingHttpTestTransport $transport): HttpServerSession
    {
        $session = new HttpServerSession(
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );

        $ref = new \ReflectionClass($session);

        $initStateField = $ref->getProperty('initializationState');
        $initStateField->setValue($session, InitializationState::Initialized);

        $clientParams = new InitializeRequestParams(
            protocolVersion: '2025-11-25',
            capabilities: new ClientCapabilities(sampling: new SamplingCapability()),
            clientInfo: new Implementation(name: 'test-client', version: '1.0')
        );
        $clientParamsField = $ref->getProperty('clientParams');
        $clientParamsField->setValue($session, $clientParams);

        $versionField = $ref->getProperty('negotiatedProtocolVersion');
        $versionField->setValue($session, '2025-11-25');

        return $session;
    }

    public function testHandleRequestCatchesSamplingSuspend(): void
    {
        $transport = new SamplingHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        $session->registerHandlers([
            'tools/call' => function ($params) {
                throw new SamplingSuspendException(
                    request: new CreateMessageRequest(
                        messages: [
                            new SamplingMessage(
                                role: Role::USER,
                                content: new TextContent(text: 'Test prompt'),
                            ),
                        ],
                        maxTokens: 100,
                    ),
                    toolName: 'test_sampling',
                    toolArguments: ['prompt' => 'Test prompt'],
                    originalRequestId: 0,
                    samplingSequence: 0,
                    previousResults: [],
                );
            },
        ]);

        $requestId = new RequestId(1);
        $clientRequest = new ClientRequest(new CallToolRequest('test_sampling', ['prompt' => 'Test prompt']));
        $responder = new RequestResponder($requestId, ['name' => 'test_sampling'], $clientRequest, $session);

        $session->handleRequest($responder);

        $this->assertNotEmpty($transport->writtenMessages);
        $outgoing = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCRequest::class, $outgoing);
        $this->assertSame('sampling/createMessage', $outgoing->method);

        $pending = $session->getPendingSamplings();
        $this->assertCount(1, $pending);
        $first = reset($pending);
        $this->assertSame('test_sampling', $first->toolName);
        $this->assertSame(1, $first->originalRequestId);

        // Original tools/call was NOT answered — only the outbound sampling request.
        $this->assertCount(1, $transport->writtenMessages);
    }

    public function testSamplingResponseResumesToolHandlerAndSendsResult(): void
    {
        $transport = new SamplingHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        // A tool handler that uses SamplingContext — just like the real flow.
        $session->registerHandlers([
            'tools/call' => function ($params) use ($session) {
                $samplingResults = [];
                if (is_object($params) && isset($params->_samplingResults)) {
                    $samplingResults = (array) $params->_samplingResults;
                }

                $context = new SamplingContext(
                    session: $session,
                    httpMode: true,
                    preloadedResults: $samplingResults,
                    toolName: $params->name ?? 'unknown',
                    toolArguments: [],
                    originalRequestId: 0,
                );

                $response = $context->prompt('Test prompt for sampling', maxTokens: 100);
                $this->assertNotNull($response);
                $content = $response->content;
                $this->assertInstanceOf(TextContent::class, $content);

                return new \Mcp\Types\CallToolResult(
                    content: [new TextContent(text: "LLM response: {$content->text}")],
                );
            },
        ]);

        // Seed a pending sampling as if round 1 already happened.
        $ref = new \ReflectionClass($session);
        $pendingField = $ref->getProperty('pendingSamplings');
        $pendingField->setValue($session, [100 => new PendingSampling(
            toolName: 'test_sampling',
            toolArguments: [],
            originalRequestId: 1,
            serverRequestId: 100,
            samplingSequence: 0,
            previousResults: [],
            createdAt: microtime(true),
        )]);

        // Enqueue the client's sampling response.
        $samplingResponse = new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(100),
            result: [
                'role' => 'assistant',
                'content' => ['type' => 'text', 'text' => 'This is a test response from the client'],
                'model' => 'test-model',
                'stopReason' => 'endTurn',
            ],
        );
        $transport->enqueue(new JsonRpcMessage($samplingResponse));

        // Drive the session to consume the response.
        $startMethod = $ref->getMethod('startMessageProcessing');
        $startMethod->invoke($session);

        // Verify the final tools/call response came out, matching the original request id.
        $this->assertNotEmpty($transport->writtenMessages);
        $resultMsg = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCResponse::class, $resultMsg);
        $this->assertSame(1, $resultMsg->id->value);

        $resultJson = json_encode($resultMsg->result);
        $this->assertStringContainsString('This is a test response from the client', $resultJson);

        // Pending state is cleared.
        $this->assertEmpty($session->getPendingSamplings());
    }

    public function testStringRequestIdRoundTripsThroughSuspendAndResume(): void
    {
        // JSON-RPC request ids are `string | number`. A tools/call issued
        // with a string id (e.g. "req-abc") must suspend and resume on that
        // same id so the tool result is delivered to the originating caller.
        $transport = new SamplingHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        $session->registerHandlers([
            'tools/call' => function ($params) use ($session) {
                $samplingResults = [];
                if (is_object($params) && isset($params->_samplingResults)) {
                    $samplingResults = (array) $params->_samplingResults;
                }

                $context = new SamplingContext(
                    session: $session,
                    httpMode: true,
                    preloadedResults: $samplingResults,
                    toolName: $params->name ?? 'unknown',
                    toolArguments: [],
                    originalRequestId: 0,
                );

                $response = $context->prompt('ping', maxTokens: 10);
                $this->assertNotNull($response);

                return new \Mcp\Types\CallToolResult(
                    content: [new TextContent(text: 'ok')],
                );
            },
        ]);

        // Round 1: drive a tools/call with a STRING request id through handleRequest.
        $stringId = 'req-abc-123';
        $requestId = new RequestId($stringId);
        $clientRequest = new ClientRequest(new CallToolRequest('test_sampling', []));
        $responder = new RequestResponder($requestId, ['name' => 'test_sampling'], $clientRequest, $session);

        $session->handleRequest($responder);

        // The suspend should have recorded the string id verbatim.
        $pendings = $session->getPendingSamplings();
        $this->assertCount(1, $pendings);
        $pending = reset($pendings);
        $this->assertSame($stringId, $pending->originalRequestId);

        $serverRequestId = $pending->serverRequestId;

        // Round 2: client answers the sampling request — expect the tool result
        // to be sent back under the string id, not 0.
        $samplingResponse = new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId($serverRequestId),
            result: [
                'role' => 'assistant',
                'content' => ['type' => 'text', 'text' => 'pong'],
                'model' => 'test-model',
                'stopReason' => 'endTurn',
            ],
        );
        $transport->enqueue(new JsonRpcMessage($samplingResponse));

        $ref = new \ReflectionClass($session);
        $startMethod = $ref->getMethod('startMessageProcessing');
        $startMethod->invoke($session);

        // The very last message written should be a response keyed to the string id.
        $finalMsg = end($transport->writtenMessages)->message;
        $this->assertInstanceOf(JSONRPCResponse::class, $finalMsg);
        $this->assertSame($stringId, $finalMsg->id->value, 'resumed response must carry the original string request id');
    }

    public function testPendingSamplingSerializedStringIdSurvivesRestore(): void
    {
        // toArray/fromArray preserves the string id verbatim across a session
        // rehydration cycle (file-backed SessionStore round-trip).
        $transport = new SamplingHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        $ref = new \ReflectionClass($session);
        $pendingField = $ref->getProperty('pendingSamplings');
        $pendingField->setValue($session, [42 => new PendingSampling(
            toolName: 'tool',
            toolArguments: [],
            originalRequestId: 'req-xyz',
            serverRequestId: 42,
            samplingSequence: 0,
            previousResults: [],
            createdAt: 1.0,
        )]);

        $data = $session->toArray();
        $restored = HttpServerSession::fromArray($data, $transport, $this->createInitOptions(), new NullLogger());

        $pending = $restored->getPendingSamplings()[42];
        $this->assertSame('req-xyz', $pending->originalRequestId);
    }

    public function testHttpSessionRefusesBlockingSendSamplingRequest(): void
    {
        // The blocking send isn't usable on HTTP transports; HttpServerSession
        // overrides it to throw before anything is written, directing callers
        // to SamplingContext (which uses the suspend/resume path).
        $transport = new SamplingHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        try {
            $session->sendSamplingRequest(
                messages: [new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi'))],
                maxTokens: 10,
            );
            $this->fail('Expected BadMethodCallException from HttpServerSession::sendSamplingRequest');
        } catch (\BadMethodCallException $e) {
            $this->assertStringContainsString('SamplingContext', $e->getMessage());
            $this->assertStringContainsString('HTTP', $e->getMessage());
        }

        // Nothing should have been written — the guard triggers before sendRequest.
        $this->assertEmpty($transport->writtenMessages);
    }

    public function testHttpSessionRefusesBlockingSendElicitationRequest(): void
    {
        $transport = new SamplingHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        try {
            $session->sendElicitationRequest(
                message: 'Need something',
                requestedSchema: ['type' => 'object', 'properties' => []],
            );
            $this->fail('Expected BadMethodCallException from HttpServerSession::sendElicitationRequest');
        } catch (\BadMethodCallException $e) {
            $this->assertStringContainsString('ElicitationContext', $e->getMessage());
        }

        $this->assertEmpty($transport->writtenMessages);
    }

    public function testPendingSamplingsSurviveToArrayRoundTrip(): void
    {
        $transport = new SamplingHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        $ref = new \ReflectionClass($session);
        $pendingField = $ref->getProperty('pendingSamplings');
        $pendingField->setValue($session, [42 => new PendingSampling(
            toolName: 'test_sampling',
            toolArguments: ['prompt' => 'hi'],
            originalRequestId: 7,
            serverRequestId: 42,
            samplingSequence: 0,
            previousResults: [],
            createdAt: 1.0,
            originalRequestParams: ['_meta' => ['progressToken' => 'abc']],
        )]);

        $data = $session->toArray();
        $this->assertArrayHasKey('pendingSamplings', $data);
        $this->assertArrayHasKey(42, $data['pendingSamplings']);
        $this->assertSame('test_sampling', $data['pendingSamplings'][42]['toolName']);

        $restored = HttpServerSession::fromArray($data, $transport, $this->createInitOptions(), new NullLogger());
        $pending = $restored->getPendingSamplings();
        $this->assertArrayHasKey(42, $pending);
        $this->assertSame(7, $pending[42]->originalRequestId);
        $this->assertSame(['prompt' => 'hi'], $pending[42]->toolArguments);
    }
}

final class SamplingHttpTestTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    private array $incomingQueue = [];

    /** @var JsonRpcMessage[] */
    public array $writtenMessages = [];

    public function enqueue(JsonRpcMessage $message): void
    {
        $this->incomingQueue[] = $message;
    }

    public function start(): void {}
    public function stop(): void {}

    public function readMessage(): ?JsonRpcMessage
    {
        return array_shift($this->incomingQueue);
    }

    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->writtenMessages[] = $message;
    }
}
