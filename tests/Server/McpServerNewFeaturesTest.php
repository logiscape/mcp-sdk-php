<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\HttpServerSession;
use Mcp\Server\McpServer;
use Mcp\Server\McpServerException;
use Mcp\Server\NotificationOptions;
use Mcp\Shared\ProgressContext;
use Mcp\Shared\RequestResponder;
use Mcp\Types\CallToolRequest;
use Mcp\Types\CallToolResult;
use Mcp\Types\ClientCapabilities;
use Mcp\Types\ClientRequest;
use Mcp\Types\Implementation;
use Mcp\Server\InitializationState;
use Mcp\Types\InitializeRequestParams;
use Mcp\Types\JsonRpcMessage;
use Mcp\Types\ListToolsResult;
use Mcp\Types\Meta;
use Mcp\Types\ProgressToken;
use Mcp\Types\RequestId;
use Mcp\Types\TaskCapability;
use Mcp\Types\TextContent;
use Mcp\Server\Transport\Transport;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for McpServer convenience wrapper new features (2025-06-18 / 2025-11-25).
 */
final class McpServerNewFeaturesTest extends TestCase
{
    /**
     * Test that tool() accepts title and outputSchema parameters.
     */
    public function testToolWithTitleAndOutputSchema(): void {
        $server = new McpServer('test');
        $server->tool(
            name: 'compute',
            description: 'Compute sum',
            callback: fn(float $a, float $b) => ['sum' => $a + $b],
            title: 'Sum Calculator',
            outputSchema: ['type' => 'object', 'properties' => ['sum' => ['type' => 'number']]],
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();

        // Test tools/list returns tool with title and outputSchema
        $listResult = $handlers['tools/list'](null);
        $this->assertInstanceOf(ListToolsResult::class, $listResult);
        $tools = $listResult->tools;
        $this->assertCount(1, $tools);
        $this->assertEquals('Sum Calculator', $tools[0]->title);
        $this->assertNotNull($tools[0]->outputSchema);
    }

    /**
     * Test that tool with outputSchema auto-wraps array return as structuredContent.
     */
    public function testToolWithOutputSchemaStructuredContent(): void {
        $server = new McpServer('test');
        $server->tool(
            name: 'data',
            description: 'Get data',
            callback: fn() => ['key' => 'value'],
            outputSchema: ['type' => 'object'],
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();

        // Invoke tools/call
        $params = new \stdClass();
        $params->name = 'data';
        $params->arguments = new \stdClass();
        $result = $handlers['tools/call']($params);
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertEquals(['key' => 'value'], $result->structuredContent);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
    }

    /**
     * Test that tool with icons parameter creates Icon objects.
     */
    public function testToolWithIcons(): void {
        $server = new McpServer('test');
        $server->tool(
            name: 'search',
            description: 'Search',
            callback: fn(string $q) => "Results for: $q",
            icons: [['src' => 'https://example.com/search.png', 'mimeType' => 'image/png']],
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();
        $listResult = $handlers['tools/list'](null);
        $this->assertCount(1, $listResult->tools[0]->icons);
    }

    /**
     * Test that tool() accepts a custom inputSchema that overrides reflection-generated schema.
     * Verifies JSON Schema 2020-12 keywords ($schema, $defs, additionalProperties) are preserved.
     */
    public function testToolWithCustomInputSchema(): void {
        $server = new McpServer('test');
        $server->tool(
            name: 'schema_tool',
            description: 'Tool with custom schema',
            callback: function (string $name): string {
                return "Hello, $name";
            },
            inputSchema: [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'address' => ['$ref' => '#/$defs/address'],
                    'name' => ['type' => 'string'],
                ],
                'required' => ['address', 'name'],
                'additionalProperties' => false,
                '$defs' => [
                    'address' => [
                        'type' => 'object',
                        'properties' => [
                            'street' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();

        $listResult = $handlers['tools/list'](null);
        $this->assertInstanceOf(ListToolsResult::class, $listResult);

        $tool = $listResult->tools[0];
        $serialized = json_decode(json_encode($tool), true);
        $schema = $serialized['inputSchema'];

        // Verify JSON Schema 2020-12 keywords are preserved
        $this->assertEquals('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertArrayHasKey('$defs', $schema);
        $this->assertArrayHasKey('address', $schema['$defs']);
        $this->assertEquals(['$ref' => '#/$defs/address'], $schema['properties']['address']);
        $this->assertEquals('object', $schema['type']);
    }

    /**
     * Test that inputSchema=null still uses reflection-generated schema.
     */
    public function testToolWithoutInputSchemaUsesReflection(): void {
        $server = new McpServer('test');
        $server->tool(
            name: 'reflected',
            description: 'Reflection schema',
            callback: fn(float $a, string $b) => "$a $b",
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();
        $listResult = $handlers['tools/list'](null);
        $schema = json_decode(json_encode($listResult->tools[0]->inputSchema), true);

        $this->assertEquals('object', $schema['type']);
        $this->assertEquals('number', $schema['properties']['a']['type']);
        $this->assertEquals('string', $schema['properties']['b']['type']);
        $this->assertContains('a', $schema['required']);
        $this->assertContains('b', $schema['required']);
    }

    /**
     * Test that ProgressContext parameter is excluded from the tool's input schema.
     */
    public function testProgressContextExcludedFromSchema(): void {
        $server = new McpServer('test');
        $server->tool(
            name: 'with-progress',
            description: 'Progress tool',
            callback: fn(?ProgressContext $progress, string $input) => "done: $input",
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();
        $listResult = $handlers['tools/list'](null);
        $schema = json_decode(json_encode($listResult->tools[0]->inputSchema), true);

        // ProgressContext should NOT appear in properties
        $this->assertArrayHasKey('input', $schema['properties']);
        $this->assertArrayNotHasKey('progress', $schema['properties']);
        $this->assertContains('input', $schema['required']);
    }

    /**
     * Test that nullable ProgressContext receives null when no session is active.
     * The tool should still execute successfully (isError === false).
     */
    public function testProgressContextNullWithoutSession(): void {
        $server = new McpServer('test');

        $server->tool(
            name: 'progress-tool',
            description: 'Tool with optional progress',
            callback: function (?ProgressContext $progress = null): string {
                return 'progress is ' . ($progress === null ? 'null' : 'set');
            },
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();

        $params = new \stdClass();
        $params->name = 'progress-tool';
        $params->arguments = new \stdClass();
        $meta = new Meta();
        $meta->progressToken = 'test-token-123';
        $params->_meta = $meta;

        $result = $handlers['tools/call']($params);
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertFalse($result->isError, 'Tool with nullable ProgressContext should succeed even without an active session');
        $this->assertEquals('progress is null', $result->content[0]->text);
    }

    /**
     * Test that non-nullable ProgressContext produces a clear SDK error when
     * no progressToken/session is available, rather than a silent TypeError.
     */
    public function testNonNullableProgressContextFailsClearly(): void {
        $server = new McpServer('test');

        $server->tool(
            name: 'strict-progress',
            description: 'Tool requiring progress',
            callback: function (ProgressContext $progress): string {
                return 'progress: ' . $progress->getCurrent();
            },
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();

        $params = new \stdClass();
        $params->name = 'strict-progress';
        $params->arguments = new \stdClass();
        // No _meta — ProgressContext will be null

        $result = $handlers['tools/call']($params);
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('non-nullable ProgressContext', $result->content[0]->text);
    }

    /**
     * Test happy path: with an active session and _meta.progressToken, the
     * callback receives a non-null ProgressContext carrying the correct token.
     * Progress notifications are written to the transport.
     */
    public function testProgressContextInjectedWithSessionAndToken(): void {
        $receivedToken = null;
        $receivedContext = null;

        $server = new McpServer('test');
        $server->tool(
            name: 'progress-tool',
            description: 'Tool with progress',
            callback: function (?ProgressContext $progress = null) use (&$receivedToken, &$receivedContext): string {
                $receivedContext = $progress;
                if ($progress !== null) {
                    $receivedToken = $progress->getToken()->getValue();
                    $progress->progress(50);
                }
                return 'done';
            },
        );

        // Build a session-backed environment (same pattern as McpServerElicitationTest)
        $transport = new ProgressTestTransport();
        $inner = $server->getServer();
        $initOpts = $inner->createInitializationOptions(new NotificationOptions());
        $session = new HttpServerSession($transport, $initOpts, new NullLogger());

        // Fast-forward to Initialized state
        $ref = new \ReflectionClass($session);
        $ref->getProperty('initializationState')
            ->setValue($session, InitializationState::Initialized);
        $ref->getProperty('negotiatedProtocolVersion')
            ->setValue($session, '2025-11-25');
        $ref->getProperty('clientParams')
            ->setValue($session, new InitializeRequestParams(
                protocolVersion: '2025-11-25',
                capabilities: new ClientCapabilities(),
                clientInfo: new Implementation('test-client', '1.0'),
            ));

        $inner->setSession($session);
        $session->registerHandlers($inner->getHandlers());

        // Fire a tools/call with _meta.progressToken through the session
        $meta = new Meta();
        $meta->progressToken = 'my-progress-token';

        $clientRequest = new ClientRequest(
            new CallToolRequest('progress-tool', null, null, $meta),
        );
        $responder = new RequestResponder(
            new RequestId(1),
            ['name' => 'progress-tool', 'arguments' => null, '_meta' => ['progressToken' => 'my-progress-token']],
            $clientRequest,
            $session,
        );

        $session->handleRequest($responder);

        // Verify the callback received a non-null ProgressContext with the correct token
        $this->assertNotNull($receivedContext, 'ProgressContext should be injected when session and token are available');
        $this->assertEquals('my-progress-token', $receivedToken);
        $this->assertEquals(50.0, $receivedContext->getCurrent());

        // Verify progress notification was written to the transport
        $this->assertGreaterThanOrEqual(2, count($transport->written)); // at least 1 notification + 1 response
        $firstMessage = $transport->written[0]->message;
        $this->assertInstanceOf(\Mcp\Types\JSONRPCNotification::class, $firstMessage);
        $this->assertEquals('notifications/progress', $firstMessage->method);
    }

    /**
     * Test that tools without ProgressContext continue to work unchanged.
     */
    public function testToolWithoutProgressContextUnaffected(): void {
        $server = new McpServer('test');
        $server->tool(
            name: 'simple',
            description: 'No progress',
            callback: fn(string $x) => "echo: $x",
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();

        $params = new \stdClass();
        $params->name = 'simple';
        $params->arguments = (object) ['x' => 'hello'];
        $meta = new Meta();
        $meta->progressToken = 'unused-token';
        $params->_meta = $meta;

        $result = $handlers['tools/call']($params);
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertFalse($result->isError);
        $this->assertEquals('echo: hello', $result->content[0]->text);
    }

    /**
     * Test prompt with title parameter.
     */
    public function testPromptWithTitle(): void {
        $server = new McpServer('test');
        $server->prompt(
            name: 'greet',
            description: 'Greeting',
            callback: fn(string $name) => "Hello, {$name}!",
            title: 'Greeting Prompt',
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();
        $listResult = $handlers['prompts/list'](null);
        $this->assertEquals('Greeting Prompt', $listResult->prompts[0]->title);
    }

    /**
     * Test resource with title and size parameters.
     */
    public function testResourceWithTitleAndSize(): void {
        $server = new McpServer('test');
        $server->resource(
            uri: 'info://ver',
            name: 'version',
            callback: fn() => '1.0',
            title: 'Version Info',
            size: 3,
        );

        $underlying = $server->getServer();
        $handlers = $underlying->getHandlers();
        $listResult = $handlers['resources/list'](null);
        $this->assertEquals('Version Info', $listResult->resources[0]->title);
        $this->assertEquals(3, $listResult->resources[0]->size);
    }

    /**
     * Test method chaining still works with new parameters.
     */
    public function testMethodChaining(): void {
        $server = new McpServer('test');
        $result = $server
            ->tool('t1', 'Tool 1', fn() => 'ok', title: 'T1')
            ->prompt('p1', 'Prompt 1', fn(string $x) => $x, title: 'P1')
            ->resource(uri: 'info://x', name: 'x', callback: fn() => 'x', title: 'X');

        $this->assertSame($server, $result);
    }

    /**
     * Test that enableTasks() registers task handlers and returns self for chaining.
     */
    public function testEnableTasksReturnsSelf(): void {
        $server = new McpServer('test');
        $result = $server->enableTasks();
        $this->assertSame($server, $result);
    }

    /**
     * Test that enableTasks() creates a TaskManager.
     */
    public function testEnableTasksCreatesTaskManager(): void {
        $server = new McpServer('test');
        $this->assertNull($server->getTaskManager());

        $server->enableTasks();
        $this->assertNotNull($server->getTaskManager());
    }

    /**
     * Test that enableTasks() registers tasks/get, tasks/list, tasks/cancel, tasks/result handlers.
     */
    public function testEnableTasksRegistersHandlers(): void {
        $server = new McpServer('test');
        $server->enableTasks();

        $handlers = $server->getServer()->getHandlers();
        $this->assertArrayHasKey('tasks/get', $handlers);
        $this->assertArrayHasKey('tasks/list', $handlers);
        $this->assertArrayHasKey('tasks/cancel', $handlers);
        $this->assertArrayHasKey('tasks/result', $handlers);
    }

    /**
     * Test that enableTasks() causes tasks capability to be advertised.
     */
    public function testEnableTasksExposesCapability(): void {
        $server = new McpServer('test');
        $server->tool('t1', 'Tool', fn() => 'ok');
        $server->enableTasks();

        $underlying = $server->getServer();
        $caps = $underlying->getCapabilities(
            new NotificationOptions(),
            []
        );
        $this->assertNotNull($caps->tasks);
        $this->assertTrue($caps->tasks->list);
        $this->assertTrue($caps->tasks->cancel);
    }

    /**
     * Test that McpServer without enableTasks() does not expose tasks capability.
     */
    public function testNoTasksCapabilityWithoutEnableTasks(): void {
        $server = new McpServer('test');
        $server->tool('t1', 'Tool', fn() => 'ok');

        $underlying = $server->getServer();
        $caps = $underlying->getCapabilities(
            new NotificationOptions(),
            []
        );
        $this->assertNull($caps->tasks);
    }

    /**
     * Test that non-empty experimental capabilities are preserved in the
     * ServerCapabilities object returned by getCapabilities().
     *
     * Regression test: previously ExperimentalCapabilities was instantiated
     * via `new ExperimentalCapabilities($array)` which silently discarded the
     * data because the class has no constructor. The fix uses fromArray().
     */
    public function testExperimentalCapabilitiesPreserved(): void {
        $server = new McpServer('test');
        $server->tool('t1', 'Tool', fn() => 'ok');

        $experimental = [
            'customFeature' => ['enabled' => true],
            'anotherCap' => 'value',
        ];

        $underlying = $server->getServer();
        $caps = $underlying->getCapabilities(
            new NotificationOptions(),
            $experimental
        );

        $this->assertNotNull($caps->experimental);
        $serialized = json_decode(json_encode($caps->experimental), true);
        $this->assertEquals(['enabled' => true], $serialized['customFeature']);
        $this->assertEquals('value', $serialized['anotherCap']);
    }
}

/**
 * Minimal in-memory transport for session-backed tests.
 */
class ProgressTestTransport implements Transport
{
    /** @var JsonRpcMessage[] */
    public array $written = [];

    /** @var JsonRpcMessage[] */
    private array $incoming = [];

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
