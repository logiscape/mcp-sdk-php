<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\McpServer;
use Mcp\Server\McpServerException;
use Mcp\Server\NotificationOptions;
use Mcp\Types\CallToolResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\TaskCapability;
use Mcp\Types\TextContent;
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
