<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\Elicitation\ElicitationSuspendException;
use Mcp\Server\Elicitation\PendingElicitation;
use Mcp\Server\HttpServerSession;
use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\RequestResponder;
use Mcp\Types\CallToolRequest;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ClientRequest;
use Mcp\Types\ElicitationCapability;
use Mcp\Types\ElicitationCreateResult;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Types\RequestParams;
use Mcp\Types\ServerCapabilities;
use Psr\Log\NullLogger;

/**
 * Transport that allows feeding messages and capturing output.
 */
class ElicitationHttpTestTransport implements Transport
{
    /** @var JsonRpcMessage[] Messages to be read by the session */
    private array $incomingQueue = [];

    /** @var JsonRpcMessage[] Messages written by the session */
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

final class ElicitationHttpIntegrationTest extends TestCase
{
    private function createInitOptions(): InitializationOptions
    {
        return new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
    }

    /**
     * Create a pre-initialized HttpServerSession with elicitation capability.
     */
    private function createInitializedSession(
        ElicitationHttpTestTransport $transport,
        ?ElicitationCapability $elicitation = null,
    ): HttpServerSession {
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
            capabilities: new ClientCapabilities(
                elicitation: $elicitation ?? new ElicitationCapability(form: true, url: true)
            ),
            clientInfo: new Implementation(name: 'test-client', version: '1.0')
        );
        $clientParamsField = $ref->getProperty('clientParams');
        $clientParamsField->setValue($session, $clientParams);

        $versionField = $ref->getProperty('negotiatedProtocolVersion');
        $versionField->setValue($session, '2025-11-25');

        return $session;
    }

    /**
     * Test the handleRequest / handleElicitationSuspend flow directly.
     *
     * Simulates: tool handler throws ElicitationSuspendException -> session writes
     * elicitation/create to outgoing -> saves pending state.
     */
    public function testHandleRequestCatchesSuspendException(): void
    {
        $transport = new ElicitationHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        // Register a tools/call handler that always throws ElicitationSuspendException
        $session->registerHandlers([
            'tools/call' => function ($params) {
                throw new ElicitationSuspendException(
                    request: new \Mcp\Types\ElicitationCreateRequest(
                        message: 'What is your name?',
                        mode: 'form',
                        requestedSchema: [
                            'type' => 'object',
                            'properties' => ['name' => ['type' => 'string']],
                        ],
                    ),
                    toolName: 'greet',
                    toolArguments: [],
                    originalRequestId: 0, // will be set by handleRequest
                    elicitationSequence: 0,
                    previousResults: [],
                );
            },
        ]);

        // Create a request responder for the tools/call
        $requestId = new RequestId(1);
        $clientRequest = new ClientRequest(new CallToolRequest('greet', []));
        $responder = new RequestResponder($requestId, ['name' => 'greet'], $clientRequest, $session);

        // Call handleRequest — it should catch the suspend exception
        $session->handleRequest($responder);

        // Verify: elicitation request was written to transport
        $this->assertNotEmpty($transport->writtenMessages);
        $elicitMsg = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCRequest::class, $elicitMsg);
        $this->assertSame('elicitation/create', $elicitMsg->method);

        // Verify: pending elicitation state is set
        $pending = $session->getPendingElicitation();
        $this->assertNotNull($pending);
        $this->assertSame('greet', $pending->toolName);
        $this->assertSame(1, $pending->originalRequestId);

        // Verify: only 1 message written (the elicitation request, NOT a tools/call response)
        $this->assertCount(1, $transport->writtenMessages);
    }

    /**
     * Test the handleElicitationResponse flow.
     *
     * Simulates: session has pending elicitation, receives response -> re-invokes tool
     * handler -> tool completes -> sends tools/call response.
     */
    public function testHandleElicitationResponse(): void
    {
        $transport = new ElicitationHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        // Register a handler that uses preloaded results
        $session->registerHandlers([
            'tools/call' => function ($params) use ($session) {
                $elicitationResults = [];
                if (is_object($params) && isset($params->_elicitationResults)) {
                    $elicitationResults = (array) $params->_elicitationResults;
                }

                $context = new ElicitationContext(
                    session: $session,
                    httpMode: true,
                    preloadedResults: $elicitationResults,
                    toolName: $params->name ?? 'unknown',
                    toolArguments: [],
                    originalRequestId: 0,
                );

                $result = $context->form('What is your name?', [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string']],
                ]);

                $name = $result->content['name'] ?? 'stranger';
                return new \Mcp\Types\CallToolResult(
                    content: [new \Mcp\Types\TextContent(text: "Hello, {$name}!")]
                );
            },
        ]);

        // Set up pending elicitation state (as if round 1 already happened)
        $ref = new \ReflectionClass($session);
        $pendingField = $ref->getProperty('pendingElicitations');
        $pendingField->setValue($session, [100 => new PendingElicitation(
            toolName: 'greet',
            toolArguments: [],
            originalRequestId: 1,
            serverRequestId: 100,
            elicitationSequence: 0,
            previousResults: [],
            createdAt: microtime(true),
        )]);

        // Enqueue the client's elicitation response
        $elicitResponse = new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(100),
            result: ['action' => 'accept', 'content' => ['name' => 'Alice']],
        );
        $transport->enqueue(new JsonRpcMessage($elicitResponse));

        // Process messages — should handle the elicitation response and send the tool result
        $startMethod = $ref->getMethod('startMessageProcessing');
        $startMethod->invoke($session);

        // Verify: tool result was sent
        $this->assertNotEmpty($transport->writtenMessages);
        $resultMsg = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCResponse::class, $resultMsg);
        $this->assertSame(1, $resultMsg->id->value); // Responds to original request ID 1

        // Verify result contains the greeting
        $resultJson = json_encode($resultMsg->result);
        $this->assertStringContainsString('Alice', $resultJson);

        // Verify: pending elicitation is cleared
        $this->assertNull($session->getPendingElicitation());
    }

    /**
     * Test pending elicitation state survives serialization round-trip.
     */
    public function testPendingElicitationStatePersistence(): void
    {
        $transport = new ElicitationHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        // Set pending elicitation
        $ref = new \ReflectionClass($session);
        $pendingField = $ref->getProperty('pendingElicitations');
        $pendingField->setValue($session, [100 => new PendingElicitation(
            toolName: 'test-tool',
            toolArguments: ['x' => 1],
            originalRequestId: 5,
            serverRequestId: 100,
            elicitationSequence: 0,
            createdAt: 12345.6,
        )]);

        // Serialize
        $data = $session->toArray();
        $this->assertArrayHasKey('pendingElicitations', $data);
        $this->assertSame('test-tool', $data['pendingElicitations'][100]['toolName']);

        // Restore
        $transport2 = new ElicitationHttpTestTransport();
        $restored = HttpServerSession::fromArray(
            $data,
            $transport2,
            $this->createInitOptions(),
            new NullLogger()
        );

        $restoredPending = $restored->getPendingElicitation();
        $this->assertNotNull($restoredPending);
        $this->assertSame('test-tool', $restoredPending->toolName);
        $this->assertSame(100, $restoredPending->serverRequestId);
        $this->assertSame(5, $restoredPending->originalRequestId);
    }

    /**
     * Test session without pending elicitation serializes cleanly.
     */
    public function testNoPendingElicitationSerialization(): void
    {
        $transport = new ElicitationHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        $data = $session->toArray();
        $this->assertArrayNotHasKey('pendingElicitations', $data);

        $restored = HttpServerSession::fromArray(
            $data,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );
        $this->assertNull($restored->getPendingElicitation());
    }

    /**
     * Test that next request ID is persisted and restored.
     */
    public function testRequestIdPersistence(): void
    {
        $transport = new ElicitationHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        // Set the request ID counter higher
        $ref = new \ReflectionClass($session);
        $setIdMethod = $ref->getMethod('setNextRequestId');
        $setIdMethod->invoke($session, 42);

        $data = $session->toArray();
        $this->assertSame(42, $data['nextRequestId']);

        $restored = HttpServerSession::fromArray(
            $data,
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );

        $getIdMethod = (new \ReflectionClass($restored))->getMethod('getNextRequestId');
        $this->assertSame(42, $getIdMethod->invoke($restored));
    }

    /**
     * Two tools/call requests in the same HTTP POST both suspend for
     * elicitation.  Each must get its own pending slot; the second must
     * not overwrite the first.
     */
    public function testConcurrentSuspendsBothSurvive(): void
    {
        $transport = new ElicitationHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        // Register a handler that always suspends
        $session->registerHandlers([
            'tools/call' => function ($params) {
                throw new \Mcp\Server\Elicitation\ElicitationSuspendException(
                    request: new \Mcp\Types\ElicitationCreateRequest(
                        message: "Info for {$params->name}",
                        mode: 'form',
                        requestedSchema: ['type' => 'object', 'properties' => []],
                    ),
                    toolName: $params->name,
                    toolArguments: [],
                    originalRequestId: 0,
                    elicitationSequence: 0,
                );
            },
        ]);

        // Fire two tools/call requests
        $req1 = new RequestResponder(
            new RequestId(1),
            ['name' => 'tool-a'],
            new ClientRequest(new CallToolRequest('tool-a', null)),
            $session,
        );
        $req2 = new RequestResponder(
            new RequestId(2),
            ['name' => 'tool-b'],
            new ClientRequest(new CallToolRequest('tool-b', null)),
            $session,
        );

        $session->handleRequest($req1);
        $session->handleRequest($req2);

        // Both must be pending
        $all = $session->getPendingElicitations();
        $this->assertCount(2, $all, 'Both suspends must be stored');

        // Each pending must reference the correct original request
        $origIds = array_map(fn(PendingElicitation $p) => $p->originalRequestId, $all);
        $this->assertContains(1, $origIds);
        $this->assertContains(2, $origIds);

        // Each must have a distinct server request ID
        $serverIds = array_keys($all);
        $this->assertCount(2, array_unique($serverIds));

        // Two elicitation/create requests must have been written
        $this->assertCount(2, $transport->writtenMessages);
        $this->assertInstanceOf(JSONRPCRequest::class, $transport->writtenMessages[0]->message);
        $this->assertInstanceOf(JSONRPCRequest::class, $transport->writtenMessages[1]->message);

        // State must survive a serialisation round-trip
        $data = $session->toArray();
        $this->assertCount(2, $data['pendingElicitations']);

        $transport2 = new ElicitationHttpTestTransport();
        $restored = HttpServerSession::fromArray(
            $data, $transport2, $this->createInitOptions(), new NullLogger()
        );
        $this->assertCount(2, $restored->getPendingElicitations());
    }

    /**
     * After two concurrent suspends, responding to one must leave the
     * other intact.
     */
    public function testResolvingOnePendingLeavesOtherIntact(): void
    {
        $transport = new ElicitationHttpTestTransport();
        $session = $this->createInitializedSession($transport);

        // Handler: "tool-a" uses form elicitation, "tool-b" just returns
        $session->registerHandlers([
            'tools/call' => function ($params) use ($session) {
                $elicitResults = [];
                if (is_object($params) && isset($params->_elicitationResults)) {
                    $elicitResults = (array) $params->_elicitationResults;
                }

                // tool-a always uses elicitation
                if (($params->name ?? '') === 'tool-a') {
                    $ctx = new ElicitationContext(
                        session: $session,
                        httpMode: true,
                        preloadedResults: $elicitResults,
                        toolName: 'tool-a',
                        toolArguments: [],
                        originalRequestId: 0,
                    );
                    $res = $ctx->form('Name?', [
                        'type' => 'object',
                        'properties' => ['n' => ['type' => 'string']],
                    ]);
                    return new \Mcp\Types\CallToolResult(
                        content: [new \Mcp\Types\TextContent(text: 'a:' . ($res->content['n'] ?? '?'))]
                    );
                }

                // tool-b also suspends
                $ctx = new ElicitationContext(
                    session: $session,
                    httpMode: true,
                    preloadedResults: $elicitResults,
                    toolName: 'tool-b',
                    toolArguments: [],
                    originalRequestId: 0,
                );
                $res = $ctx->form('Age?', [
                    'type' => 'object',
                    'properties' => ['a' => ['type' => 'number']],
                ]);
                return new \Mcp\Types\CallToolResult(
                    content: [new \Mcp\Types\TextContent(text: 'b:' . ($res->content['a'] ?? '?'))]
                );
            },
        ]);

        // Suspend both
        $session->handleRequest(new RequestResponder(
            new RequestId(1), ['name' => 'tool-a'],
            new ClientRequest(new CallToolRequest('tool-a', null)), $session,
        ));
        $session->handleRequest(new RequestResponder(
            new RequestId(2), ['name' => 'tool-b'],
            new ClientRequest(new CallToolRequest('tool-b', null)), $session,
        ));

        $this->assertCount(2, $session->getPendingElicitations());

        // Identify which server IDs belong to which tool
        $pendingByTool = [];
        foreach ($session->getPendingElicitations() as $id => $p) {
            $pendingByTool[$p->toolName] = $id;
        }

        // Resolve tool-a only
        $transport->writtenMessages = []; // clear outgoing
        $transport->enqueue(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId($pendingByTool['tool-a']),
            result: ['action' => 'accept', 'content' => ['n' => 'Alice']],
        )));

        // Process — this should resume tool-a and leave tool-b pending
        $ref = new \ReflectionClass($session);
        $ref->getMethod('startMessageProcessing')->invoke($session);

        // tool-a's response should have been written
        $responses = array_filter($transport->writtenMessages, function ($m) {
            return $m->message instanceof JSONRPCResponse;
        });
        $this->assertCount(1, $responses);
        $resp = reset($responses)->message;
        $this->assertSame(1, $resp->id->value); // tool-a's original request
        $this->assertStringContainsString('Alice', json_encode($resp->result));

        // tool-b must still be pending
        $remaining = $session->getPendingElicitations();
        $this->assertCount(1, $remaining);
        $leftover = reset($remaining);
        $this->assertSame('tool-b', $leftover->toolName);
        $this->assertSame(2, $leftover->originalRequestId);
    }
}
