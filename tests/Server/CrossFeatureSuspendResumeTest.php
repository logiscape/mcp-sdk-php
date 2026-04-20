<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\Elicitation\PendingElicitation;
use Mcp\Server\HttpServerSession;
use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\Sampling\PendingSampling;
use Mcp\Server\Sampling\SamplingContext;
use Mcp\Server\Transport\Transport;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ElicitationCapability;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\JSONRPCRequest;
use Mcp\Types\JSONRPCResponse;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\RequestId;
use Mcp\Types\SamplingCapability;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\TextContent;
use Psr\Log\NullLogger;

/**
 * Cross-feature suspend/resume integration tests.
 *
 * A tool handler in HTTP mode can chain elicitation and sampling within a
 * single tools/call. Each feature suspends and resumes through its own
 * pending state, but when the handler crosses feature boundaries the prior
 * feature's accumulated results must be carried forward so cached calls
 * return their preloaded value instead of re-suspending and re-prompting
 * the client.
 *
 * Without the carry-forward, the symptom is a duplicate elicitation/create
 * (or sampling/createMessage) request emitted after the cross-feature
 * resume, plus a second PendingElicitation (or PendingSampling) entry that
 * the client must answer redundantly.
 */
final class CrossFeatureSuspendResumeTest extends TestCase
{
    private function createInitOptions(): InitializationOptions
    {
        return new InitializationOptions(
            serverName: 'test-server',
            serverVersion: '1.0.0',
            capabilities: new ServerCapabilities()
        );
    }

    private function createInitializedSession(CrossFeatureTestTransport $transport): HttpServerSession
    {
        $session = new HttpServerSession(
            $transport,
            $this->createInitOptions(),
            new NullLogger()
        );

        $ref = new \ReflectionClass($session);

        $initStateField = $ref->getProperty('initializationState');
        $initStateField->setValue($session, InitializationState::Initialized);

        // Both capabilities must be advertised — the contexts gate on them.
        $clientParams = new InitializeRequestParams(
            protocolVersion: '2025-11-25',
            capabilities: new ClientCapabilities(
                sampling: new SamplingCapability(),
                elicitation: new ElicitationCapability(form: true, url: true),
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
     * A tool that elicits first, then samples. The elicit result accumulated
     * in round 2 must survive the cross-feature hop into the sampling
     * pending state, otherwise round 3's resumed handler re-throws the
     * elicitation suspend and prompts the client a second time.
     */
    public function testElicitationToSamplingTransitionCarriesElicitationResults(): void
    {
        $transport = new CrossFeatureTestTransport();
        $session = $this->createInitializedSession($transport);

        $session->registerHandlers([
            'tools/call' => function ($params) use ($session) {
                $elicitResults = [];
                if (is_object($params) && isset($params->_elicitationResults)) {
                    $elicitResults = (array) $params->_elicitationResults;
                }
                $samplingResults = [];
                if (is_object($params) && isset($params->_samplingResults)) {
                    $samplingResults = (array) $params->_samplingResults;
                }

                $elicit = new ElicitationContext(
                    session: $session,
                    httpMode: true,
                    preloadedResults: $elicitResults,
                    toolName: 'mixed-tool',
                    toolArguments: [],
                    originalRequestId: 0,
                );
                $sampling = new SamplingContext(
                    session: $session,
                    httpMode: true,
                    preloadedResults: $samplingResults,
                    toolName: 'mixed-tool',
                    toolArguments: [],
                    originalRequestId: 0,
                );

                $form = $elicit->form('Name?', [
                    'type' => 'object',
                    'properties' => ['n' => ['type' => 'string']],
                ]);
                $name = $form->content['n'] ?? '?';

                $reply = $sampling->prompt("Greet {$name}", maxTokens: 10);

                return new \Mcp\Types\CallToolResult(
                    content: [new TextContent(text: 'final:' . $reply->content->text)],
                );
            },
        ]);

        // Seed round 1 — pending elicitation already in flight from a prior POST.
        $ref = new \ReflectionClass($session);
        $pendingField = $ref->getProperty('pendingElicitations');
        $pendingField->setValue($session, [100 => new PendingElicitation(
            toolName: 'mixed-tool',
            toolArguments: [],
            originalRequestId: 7,
            serverRequestId: 100,
            elicitationSequence: 0,
            previousResults: [],
            createdAt: microtime(true),
            originalRequestParams: [],
        )]);

        // Round 2: client answers the elicitation.
        $transport->enqueue(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(100),
            result: ['action' => 'accept', 'content' => ['n' => 'Alice']],
        )));

        $startMethod = $ref->getMethod('startMessageProcessing');
        $startMethod->invoke($session);

        // After round 2: elicitation pending is gone, exactly one sampling
        // pending remains, and exactly one new outbound message (the
        // sampling/createMessage request) was written. If the carry-forward
        // is missing, the handler would have re-suspended on elicitation and
        // we'd see an elicitation/create request instead.
        $this->assertEmpty($session->getPendingElicitations(), 'elicitation pending must be cleared after its response');

        $samplings = $session->getPendingSamplings();
        $this->assertCount(1, $samplings, 'exactly one sampling pending after the cross-feature hop');
        $samplingPending = reset($samplings);

        $this->assertCount(1, $transport->writtenMessages, 'cross-feature hop must emit exactly one outbound request');
        $outbound = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCRequest::class, $outbound);
        $this->assertSame(
            'sampling/createMessage',
            $outbound->method,
            'next outbound must be sampling, not a duplicate elicitation/create',
        );

        // The carry-forward itself: the new sampling pending's originalRequestParams
        // must include the just-resolved elicitation result.
        $this->assertArrayHasKey('_elicitationResults', $samplingPending->originalRequestParams);
        $carried = $samplingPending->originalRequestParams['_elicitationResults'];
        $this->assertSame(['n' => 'Alice'], $carried[0]['content'] ?? null);
        $this->assertSame('accept', $carried[0]['action'] ?? null);

        // Round 3: client answers the sampling. Handler resumes both cached calls
        // and returns the final result against the original tools/call id (7).
        $transport->writtenMessages = [];
        $transport->enqueue(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId($samplingPending->serverRequestId),
            result: [
                'role' => 'assistant',
                'content' => ['type' => 'text', 'text' => 'Hi Alice'],
                'model' => 'test-model',
                'stopReason' => 'endTurn',
            ],
        )));

        $startMethod->invoke($session);

        $this->assertEmpty($session->getPendingSamplings(), 'sampling pending must clear after its response');
        $this->assertEmpty($session->getPendingElicitations(), 'no second elicitation should ever have been triggered');

        $this->assertCount(1, $transport->writtenMessages, 'final round emits only the tools/call response');
        $final = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCResponse::class, $final);
        $this->assertSame(7, $final->id->value, 'final response routes back to the original tools/call id');
        $this->assertStringContainsString('Hi Alice', json_encode($final->result));
    }

    /**
     * Symmetric: a tool that samples first, then elicits. The sampling
     * result accumulated in round 2 must survive the cross-feature hop into
     * the elicitation pending state.
     */
    public function testSamplingToElicitationTransitionCarriesSamplingResults(): void
    {
        $transport = new CrossFeatureTestTransport();
        $session = $this->createInitializedSession($transport);

        $session->registerHandlers([
            'tools/call' => function ($params) use ($session) {
                $elicitResults = [];
                if (is_object($params) && isset($params->_elicitationResults)) {
                    $elicitResults = (array) $params->_elicitationResults;
                }
                $samplingResults = [];
                if (is_object($params) && isset($params->_samplingResults)) {
                    $samplingResults = (array) $params->_samplingResults;
                }

                $elicit = new ElicitationContext(
                    session: $session,
                    httpMode: true,
                    preloadedResults: $elicitResults,
                    toolName: 'mixed-tool',
                    toolArguments: [],
                    originalRequestId: 0,
                );
                $sampling = new SamplingContext(
                    session: $session,
                    httpMode: true,
                    preloadedResults: $samplingResults,
                    toolName: 'mixed-tool',
                    toolArguments: [],
                    originalRequestId: 0,
                );

                $reply = $sampling->prompt('Suggest a topic', maxTokens: 10);
                $topic = $reply->content->text;

                $form = $elicit->form("Confirm topic '{$topic}'?", [
                    'type' => 'object',
                    'properties' => ['ok' => ['type' => 'boolean']],
                ]);
                $confirmed = $form->content['ok'] ?? false;

                return new \Mcp\Types\CallToolResult(
                    content: [new TextContent(text: $confirmed ? "topic:{$topic}" : 'declined')],
                );
            },
        ]);

        // Seed round 1 — pending sampling already in flight.
        $ref = new \ReflectionClass($session);
        $pendingField = $ref->getProperty('pendingSamplings');
        $pendingField->setValue($session, [200 => new PendingSampling(
            toolName: 'mixed-tool',
            toolArguments: [],
            originalRequestId: 9,
            serverRequestId: 200,
            samplingSequence: 0,
            previousResults: [],
            createdAt: microtime(true),
            originalRequestParams: [],
        )]);

        // Round 2: client answers the sampling.
        $transport->enqueue(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId(200),
            result: [
                'role' => 'assistant',
                'content' => ['type' => 'text', 'text' => 'pirates'],
                'model' => 'test-model',
                'stopReason' => 'endTurn',
            ],
        )));

        $startMethod = $ref->getMethod('startMessageProcessing');
        $startMethod->invoke($session);

        $this->assertEmpty($session->getPendingSamplings(), 'sampling pending must be cleared');
        $elicits = $session->getPendingElicitations();
        $this->assertCount(1, $elicits);
        $elicitPending = reset($elicits);

        $this->assertCount(1, $transport->writtenMessages, 'cross-feature hop emits exactly one outbound');
        $outbound = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCRequest::class, $outbound);
        $this->assertSame(
            'elicitation/create',
            $outbound->method,
            'next outbound must be elicitation, not a duplicate sampling/createMessage',
        );

        // Carry-forward assertion.
        $this->assertArrayHasKey('_samplingResults', $elicitPending->originalRequestParams);
        $carried = $elicitPending->originalRequestParams['_samplingResults'];
        $this->assertSame('pirates', $carried[0]['content']['text'] ?? null);

        // Round 3: client confirms.
        $transport->writtenMessages = [];
        $transport->enqueue(new JsonRpcMessage(new JSONRPCResponse(
            jsonrpc: '2.0',
            id: new RequestId($elicitPending->serverRequestId),
            result: ['action' => 'accept', 'content' => ['ok' => true]],
        )));

        $startMethod->invoke($session);

        $this->assertEmpty($session->getPendingElicitations());
        $this->assertEmpty($session->getPendingSamplings(), 'no second sampling should ever have been triggered');

        $this->assertCount(1, $transport->writtenMessages);
        $final = $transport->writtenMessages[0]->message;
        $this->assertInstanceOf(JSONRPCResponse::class, $final);
        $this->assertSame(9, $final->id->value);
        $this->assertStringContainsString('topic:pirates', json_encode($final->result));
    }
}

final class CrossFeatureTestTransport implements Transport
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
