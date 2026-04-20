<?php

declare(strict_types=1);

namespace Mcp\Tests\Server\Sampling;

use PHPUnit\Framework\TestCase;
use Mcp\Server\Sampling\SamplingContext;
use Mcp\Server\Sampling\SamplingSuspendException;
use Mcp\Server\InitializationOptions;
use Mcp\Server\InitializationState;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\Implementation;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\Role;
use Mcp\Types\SamplingCapability;
use Mcp\Types\SamplingMessage;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\TextContent;
use Psr\Log\NullLogger;

class SamplingTestTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    public array $writtenMessages = [];

    public function start(): void {}
    public function stop(): void {}

    public function readMessage(): ?JsonRpcMessage
    {
        return null;
    }

    public function writeMessage(JsonRpcMessage $message): void
    {
        $this->writtenMessages[] = $message;
    }
}

final class SamplingContextTest extends TestCase
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
     * Build a pre-initialized ServerSession with the given sampling capability.
     */
    private function createSessionWithCapabilities(
        ?SamplingCapability $sampling = null,
        string $protocolVersion = '2025-11-25',
    ): ServerSession {
        $transport = new SamplingTestTransport();
        $session = new ServerSession($transport, $this->createInitOptions(), new NullLogger());

        $ref = new \ReflectionClass($session);

        $initStateField = $ref->getProperty('initializationState');
        $initStateField->setValue($session, InitializationState::Initialized);

        $clientParams = new InitializeRequestParams(
            protocolVersion: $protocolVersion,
            capabilities: new ClientCapabilities(sampling: $sampling),
            clientInfo: new Implementation(name: 'test-client', version: '1.0'),
        );
        $clientParamsField = $ref->getProperty('clientParams');
        $clientParamsField->setValue($session, $clientParams);

        $versionField = $ref->getProperty('negotiatedProtocolVersion');
        $versionField->setValue($session, $protocolVersion);

        return $session;
    }

    public function testSupportsSamplingWhenClientAdvertisesCapability(): void
    {
        $session = $this->createSessionWithCapabilities(new SamplingCapability());
        $context = new SamplingContext(session: $session);
        $this->assertTrue($context->supportsSampling());
    }

    public function testSupportsSamplingFalseWhenCapabilityMissing(): void
    {
        $session = $this->createSessionWithCapabilities(null);
        $context = new SamplingContext(session: $session);
        $this->assertFalse($context->supportsSampling());
    }

    public function testSupportsSamplingFalseOnAncientProtocolVersion(): void
    {
        // Negotiated version older than the minimum for sampling should gate out.
        $session = $this->createSessionWithCapabilities(
            new SamplingCapability(),
            protocolVersion: '2020-01-01',
        );
        $context = new SamplingContext(session: $session);
        $this->assertFalse($context->supportsSampling());
    }

    public function testSupportsToolsInSamplingRequiresSubCapability(): void
    {
        // Plain sampling capability — no `tools` sub-field.
        $session = $this->createSessionWithCapabilities(new SamplingCapability());
        $context = new SamplingContext(session: $session);
        $this->assertFalse($context->supportsToolsInSampling());

        // Sampling capability with `tools` sub-field.
        $cap = new SamplingCapability();
        $cap->tools = new \stdClass();
        $session2 = $this->createSessionWithCapabilities($cap);
        $context2 = new SamplingContext(session: $session2);
        $this->assertTrue($context2->supportsToolsInSampling());
    }

    public function testCreateMessageReturnsNullWhenUnsupported(): void
    {
        $session = $this->createSessionWithCapabilities(null);
        $context = new SamplingContext(session: $session);

        $result = $context->createMessage(
            messages: [new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi'))],
            maxTokens: 100,
        );
        $this->assertNull($result);
    }

    public function testHttpModeThrowsSuspendExceptionWithPayload(): void
    {
        $session = $this->createSessionWithCapabilities(new SamplingCapability());
        $context = new SamplingContext(
            session: $session,
            httpMode: true,
            preloadedResults: [],
            toolName: 'test_sampling',
            toolArguments: ['prompt' => 'hi'],
            originalRequestId: 42,
        );

        try {
            $context->prompt('What is 2+2?', maxTokens: 50);
            $this->fail('Expected SamplingSuspendException was not thrown');
        } catch (SamplingSuspendException $e) {
            $this->assertSame('test_sampling', $e->toolName);
            $this->assertSame(42, $e->originalRequestId);
            $this->assertSame(0, $e->samplingSequence);
            $this->assertSame('sampling/createMessage', $e->request->method);
            $this->assertSame(50, $e->request->maxTokens);
        }
    }

    public function testHttpModeReturnsPreloadedResultWithoutThrowing(): void
    {
        $session = $this->createSessionWithCapabilities(new SamplingCapability());

        $preloaded = [
            0 => [
                'role' => 'assistant',
                'content' => ['type' => 'text', 'text' => 'canned reply'],
                'model' => 'test-model',
                'stopReason' => 'endTurn',
            ],
        ];

        $context = new SamplingContext(
            session: $session,
            httpMode: true,
            preloadedResults: $preloaded,
            toolName: 'test_sampling',
            toolArguments: ['prompt' => 'hi'],
            originalRequestId: 1,
        );

        $result = $context->prompt('anything', maxTokens: 10);
        $this->assertNotNull($result);
        $this->assertSame('test-model', $result->model);
        $this->assertSame('endTurn', $result->stopReason);
        $this->assertInstanceOf(TextContent::class, $result->content);
        $this->assertSame('canned reply', $result->content->text);
    }

    public function testHttpModeSecondCallSuspendsWithIncrementedSequence(): void
    {
        $session = $this->createSessionWithCapabilities(new SamplingCapability());

        $preloaded = [
            0 => [
                'role' => 'assistant',
                'content' => ['type' => 'text', 'text' => 'first reply'],
                'model' => 'test-model',
                'stopReason' => 'endTurn',
            ],
        ];

        $context = new SamplingContext(
            session: $session,
            httpMode: true,
            preloadedResults: $preloaded,
            toolName: 'tool',
            toolArguments: [],
            originalRequestId: 7,
        );

        // First call consumes preloaded[0].
        $first = $context->prompt('round 1', maxTokens: 10);
        $this->assertNotNull($first);

        // Second call has no preloaded result at sequence 1 -> suspend.
        try {
            $context->prompt('round 2', maxTokens: 10);
            $this->fail('Expected suspend exception for second round');
        } catch (SamplingSuspendException $e) {
            $this->assertSame(1, $e->samplingSequence);
            $this->assertSame($preloaded, $e->previousResults);
        }
    }

    public function testToolsRequestedWithoutCapabilityReturnsNull(): void
    {
        // Sampling is declared but the `tools` sub-capability is not;
        // tool-enabled sampling returns null so the caller can fall back
        // explicitly instead of reinterpreting the request as plain sampling.
        $session = $this->createSessionWithCapabilities(new SamplingCapability());
        $context = new SamplingContext(
            session: $session,
            httpMode: true,
            toolName: 'tool',
            originalRequestId: 1,
        );

        $fakeTool = new \Mcp\Types\Tool(
            name: 'lookup',
            inputSchema: new \Mcp\Types\ToolInputSchema(),
            description: 'test',
        );

        $result = $context->createMessage(
            messages: [new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi'))],
            maxTokens: 10,
            tools: [$fakeTool],
        );

        $this->assertNull($result, 'createMessage must refuse tool-enabled sampling without sampling.tools');
    }

    public function testToolChoiceRequestedWithoutCapabilityReturnsNull(): void
    {
        $session = $this->createSessionWithCapabilities(new SamplingCapability());
        $context = new SamplingContext(
            session: $session,
            httpMode: true,
            toolName: 'tool',
            originalRequestId: 1,
        );

        $result = $context->createMessage(
            messages: [new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi'))],
            maxTokens: 10,
            toolChoice: new \Mcp\Types\ToolChoice(mode: 'auto'),
        );

        $this->assertNull($result, 'createMessage must refuse sampling with toolChoice but no sampling.tools');
    }

    public function testToolsSucceedWhenClientAdvertisesSamplingTools(): void
    {
        $cap = new SamplingCapability();
        $cap->tools = new \stdClass();
        $session = $this->createSessionWithCapabilities($cap);

        $context = new SamplingContext(
            session: $session,
            httpMode: true,
            toolName: 'tool',
            toolArguments: [],
            originalRequestId: 1,
        );

        $fakeTool = new \Mcp\Types\Tool(
            name: 'lookup',
            inputSchema: new \Mcp\Types\ToolInputSchema(),
            description: 'test',
        );

        try {
            $context->createMessage(
                messages: [new SamplingMessage(role: Role::USER, content: new TextContent(text: 'hi'))],
                maxTokens: 10,
                tools: [$fakeTool],
            );
            $this->fail('Expected suspend exception');
        } catch (SamplingSuspendException $e) {
            // Tools are preserved end-to-end when the client supports them.
            $this->assertIsArray($e->request->tools);
            $this->assertCount(1, $e->request->tools);
            $this->assertSame('lookup', $e->request->tools[0]->name);
        }
    }

    public function testStringRequestIdFlowsThroughSuspendException(): void
    {
        // JSON-RPC request ids are `string | number`; a string id round-trips
        // through the suspend exception with its original type intact.
        $session = $this->createSessionWithCapabilities(new SamplingCapability());
        $context = new SamplingContext(
            session: $session,
            httpMode: true,
            toolName: 'tool',
            originalRequestId: 'req-abc-123',
        );

        try {
            $context->prompt('hi', maxTokens: 5);
            $this->fail('Expected suspend exception');
        } catch (SamplingSuspendException $e) {
            $this->assertSame('req-abc-123', $e->originalRequestId);
        }
    }
}
