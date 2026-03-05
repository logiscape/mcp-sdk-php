<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\McpServer;
use Mcp\Types\CallToolResult;
use Mcp\Types\ListToolsResult;
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
}
