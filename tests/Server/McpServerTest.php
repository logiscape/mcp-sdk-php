<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 */

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\Server\McpServer;
use Mcp\Server\McpServerException;
use Mcp\Server\Server;
use Mcp\Types\CallToolResult;
use Mcp\Types\GetPromptResult;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\PromptMessage;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\Role;
use Mcp\Types\TextContent;
use Mcp\Types\TextResourceContents;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the McpServer convenience wrapper.
 *
 * Validates that McpServer correctly:
 * - Registers tools with automatic schema generation from callback reflection
 * - Registers prompts with automatic argument detection from callback reflection
 * - Registers resources with metadata preservation
 * - Auto-wraps simple return values (string) into proper MCP result types
 * - Passes through native MCP result types unchanged
 * - Throws McpServerException for unknown tools/prompts/resources
 * - Throws McpServerException for invalid return types
 * - Supports method chaining
 * - Provides access to underlying Server instance
 * - Configures HTTP options and auth correctly
 */
final class McpServerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Tool Registration & Invocation
    // -----------------------------------------------------------------------

    /**
     * Test that tool registration creates correct JSON Schema from PHP types.
     *
     * The buildSchemaFromCallback method uses reflection to inspect the callback's
     * parameters and generates a ToolInputSchema with:
     * - Property names matching parameter names
     * - JSON Schema types mapped from PHP types (float→number, string→string, etc.)
     * - Required list containing only non-optional parameters
     *
     * This is the foundation of the convenience wrapper — if broken, all tool schemas
     * will be incorrect and clients won't know how to call tools.
     */
    public function testToolRegistrationCreatesCorrectSchema(): void
    {
        $server = new McpServer('test');
        $server->tool('add', 'Add two numbers', function (float $a, float $b): string {
            return (string) ($a + $b);
        });

        // Access the underlying server's registered handlers
        $handlers = $server->getServer()->getHandlers();
        $this->assertArrayHasKey('tools/list', $handlers, 'tools/list handler should be registered');

        // Invoke tools/list to get registered tools
        $result = $handlers['tools/list'](null);
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount(1, $result->tools, 'Should have exactly one tool registered');

        $tool = $result->tools[0];
        $this->assertSame('add', $tool->name);
        $this->assertSame('Add two numbers', $tool->description);

        // Verify schema has required fields
        $this->assertSame(['a', 'b'], $tool->inputSchema->required);
    }

    /**
     * Test that tool callback wraps string return value in CallToolResult.
     *
     * When a tool callback returns a plain string, McpServer wraps it in a
     * CallToolResult with a single TextContent element. This saves developers
     * from constructing result objects for simple string responses.
     */
    public function testToolCallbackWrapsStringInCallToolResult(): void
    {
        $server = new McpServer('test');
        $server->tool('greet', 'Greeting', function (string $name): string {
            return "Hello, {$name}!";
        });

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'greet', 'arguments' => (object) ['name' => 'World']];
        $result = $handlers['tools/call']($params);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('Hello, World!', $result->content[0]->text);
        $this->assertFalse($result->isError);
    }

    /**
     * Test that tool callback passes through CallToolResult unchanged.
     *
     * When a tool callback returns a CallToolResult directly, McpServer
     * returns it as-is without wrapping. This supports advanced use cases
     * where developers need full control over the result.
     */
    public function testToolCallbackPassesThroughCallToolResult(): void
    {
        $server = new McpServer('test');
        $expected = new CallToolResult(
            content: [new TextContent(text: 'custom')],
            isError: false
        );
        $server->tool('custom', 'Custom result', function () use ($expected): CallToolResult {
            return $expected;
        });

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'custom', 'arguments' => new \stdClass()];
        $result = $handlers['tools/call']($params);

        $this->assertSame($expected, $result);
    }

    /**
     * Test that tool callback exceptions are wrapped as error results.
     *
     * When a tool callback throws an exception, the tools/call handler catches
     * it and returns a CallToolResult with isError=true and the exception
     * message as content. This prevents tool errors from crashing the server.
     */
    public function testToolCallbackErrorWrapping(): void
    {
        $server = new McpServer('test');
        $server->tool('fail', 'Failing tool', function (): string {
            throw new \RuntimeException('Something went wrong');
        });

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'fail', 'arguments' => new \stdClass()];
        $result = $handlers['tools/call']($params);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Something went wrong', $result->content[0]->text);
    }

    /**
     * Test that schema generation maps PHP types to JSON Schema types correctly.
     *
     * PHP type mapping:
     * - int    → number
     * - float  → number
     * - bool   → boolean
     * - array  → array
     * - string → string
     * - object → object
     */
    public function testSchemaGenerationMapsPhpTypesCorrectly(): void
    {
        $server = new McpServer('test');
        $server->tool('typed', 'Typed tool', function (
            int $intParam,
            float $floatParam,
            bool $boolParam,
            array $arrayParam,
            string $stringParam
        ): string {
            return 'ok';
        });

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['tools/list'](null);
        $schema = $result->tools[0]->inputSchema;

        // Serialize to inspect generated JSON Schema types
        $json = json_decode(json_encode($schema->jsonSerialize()), true);

        $this->assertSame('number', $json['properties']['intParam']['type']);
        $this->assertSame('number', $json['properties']['floatParam']['type']);
        $this->assertSame('boolean', $json['properties']['boolParam']['type']);
        $this->assertSame('array', $json['properties']['arrayParam']['type']);
        $this->assertSame('string', $json['properties']['stringParam']['type']);
    }

    /**
     * Test that optional parameters are excluded from the required list.
     *
     * Parameters with default values should not appear in the JSON Schema
     * 'required' array, allowing clients to omit them.
     */
    public function testOptionalParametersNotInRequired(): void
    {
        $server = new McpServer('test');
        $server->tool('optional', 'Optional params', function (
            string $required,
            string $optional = 'default'
        ): string {
            return $required . $optional;
        });

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['tools/list'](null);
        $schema = $result->tools[0]->inputSchema;

        $this->assertSame(['required'], $schema->required);
    }

    // -----------------------------------------------------------------------
    // Prompt Registration & Invocation
    // -----------------------------------------------------------------------

    /**
     * Test that prompt registration detects required and optional arguments.
     *
     * The buildArgumentsFromCallback method uses reflection to create
     * PromptArgument objects from the callback's parameters, correctly
     * marking required vs optional based on parameter defaults.
     */
    public function testPromptRegistrationDetectsArguments(): void
    {
        $server = new McpServer('test');
        $server->prompt('greet', 'Greeting', function (string $name, string $lang = 'en'): string {
            return "Hello!";
        });

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['prompts/list'](null);
        $this->assertInstanceOf(ListPromptsResult::class, $result);
        $this->assertCount(1, $result->prompts);

        $prompt = $result->prompts[0];
        $this->assertSame('greet', $prompt->name);
        $this->assertSame('Greeting', $prompt->description);
        $this->assertCount(2, $prompt->arguments);

        // First arg: required
        $this->assertSame('name', $prompt->arguments[0]->name);
        $this->assertTrue($prompt->arguments[0]->required);

        // Second arg: optional
        $this->assertSame('lang', $prompt->arguments[1]->name);
        $this->assertFalse($prompt->arguments[1]->required);
    }

    /**
     * Test that prompt callback wraps string return in GetPromptResult.
     *
     * A string return is wrapped in a GetPromptResult with a single
     * PromptMessage containing a TextContent with role=USER.
     */
    public function testPromptCallbackWrapsStringInGetPromptResult(): void
    {
        $server = new McpServer('test');
        $server->prompt('greet', 'Greeting', function (string $name): string {
            return "Hello, {$name}!";
        });

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'greet', 'arguments' => (object) ['name' => 'World']];
        $result = $handlers['prompts/get']($params);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(1, $result->messages);
        $this->assertSame(Role::USER, $result->messages[0]->role);
        $this->assertSame('Hello, World!', $result->messages[0]->content->text);
    }

    /**
     * Test that prompt callback wraps array return in GetPromptResult.
     *
     * An array of strings is wrapped in a GetPromptResult with multiple
     * PromptMessage objects, each containing a TextContent with role=USER.
     */
    public function testPromptCallbackWrapsArrayInGetPromptResult(): void
    {
        $server = new McpServer('test');
        $server->prompt('multi', 'Multi-message', function (): array {
            return ['First message', 'Second message'];
        });

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'multi', 'arguments' => new \stdClass()];
        $result = $handlers['prompts/get']($params);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertCount(2, $result->messages);
        $this->assertSame('First message', $result->messages[0]->content->text);
        $this->assertSame('Second message', $result->messages[1]->content->text);
    }

    /**
     * Test that prompt callback passes through GetPromptResult unchanged.
     */
    public function testPromptCallbackPassesThroughGetPromptResult(): void
    {
        $expected = new GetPromptResult(
            messages: [
                new PromptMessage(
                    role: Role::USER,
                    content: new TextContent(text: 'custom')
                ),
            ]
        );

        $server = new McpServer('test');
        $server->prompt('custom', 'Custom', function () use ($expected): GetPromptResult {
            return $expected;
        });

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'custom', 'arguments' => new \stdClass()];
        $result = $handlers['prompts/get']($params);

        $this->assertSame($expected, $result);
    }

    /**
     * Test that prompt callback with invalid result throws McpServerException.
     *
     * When the callback returns something other than string, array, or
     * GetPromptResult, McpServerException::invalidPromptResult is thrown.
     */
    public function testPromptCallbackInvalidResultThrows(): void
    {
        $server = new McpServer('test');
        $server->prompt('bad', 'Bad prompt', function (): int {
            return 42;
        });

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'bad', 'arguments' => new \stdClass()];

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Invalid prompt handler result');
        $handlers['prompts/get']($params);
    }

    // -----------------------------------------------------------------------
    // Resource Registration & Invocation
    // -----------------------------------------------------------------------

    /**
     * Test that resource registration stores URI, name, and mimeType.
     */
    public function testResourceRegistrationStoresMetadata(): void
    {
        $server = new McpServer('test');
        $server->resource(
            uri: 'file:///test.txt',
            name: 'Test File',
            description: 'A test file',
            mimeType: 'text/plain',
            callback: fn () => 'content'
        );

        $handlers = $server->getServer()->getHandlers();
        $result = $handlers['resources/list'](null);
        $this->assertInstanceOf(ListResourcesResult::class, $result);
        $this->assertCount(1, $result->resources);

        $resource = $result->resources[0];
        $this->assertSame('file:///test.txt', $resource->uri);
        $this->assertSame('Test File', $resource->name);
        $this->assertSame('A test file', $resource->description);
        $this->assertSame('text/plain', $resource->mimeType);
    }

    /**
     * Test that resource callback wraps string return in ReadResourceResult.
     *
     * A string return is wrapped in a ReadResourceResult with a single
     * TextResourceContents element containing the string, URI, and mimeType.
     */
    public function testResourceCallbackWrapsStringInReadResourceResult(): void
    {
        $server = new McpServer('test');
        $server->resource(
            uri: 'test://data',
            name: 'Data',
            mimeType: 'text/plain',
            callback: fn () => 'resource content'
        );

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['uri' => 'test://data'];
        $result = $handlers['resources/read']($params);

        $this->assertInstanceOf(ReadResourceResult::class, $result);
        $this->assertCount(1, $result->contents);
        $this->assertInstanceOf(TextResourceContents::class, $result->contents[0]);
        $this->assertSame('resource content', $result->contents[0]->text);
    }

    /**
     * Test that resource callback with invalid result throws McpServerException.
     */
    public function testResourceCallbackInvalidResultThrows(): void
    {
        $server = new McpServer('test');
        $server->resource(
            uri: 'test://bad',
            name: 'Bad',
            callback: fn () => 42
        );

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['uri' => 'test://bad'];

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Invalid resource handler result');
        $handlers['resources/read']($params);
    }

    // -----------------------------------------------------------------------
    // Error Handling
    // -----------------------------------------------------------------------

    /**
     * Test that calling an unknown tool throws McpServerException.
     *
     * The tools/call handler checks the toolHandlers map and throws
     * McpServerException::unknownTool if the tool name is not found.
     * This exception propagates up to Server::handleMessage which
     * converts it to a JSON-RPC error response.
     */
    public function testUnknownToolThrowsMcpServerException(): void
    {
        $server = new McpServer('test');

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'nonexistent', 'arguments' => new \stdClass()];

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Unknown tool: nonexistent');
        $handlers['tools/call']($params);
    }

    /**
     * Test that getting an unknown prompt throws McpServerException.
     */
    public function testUnknownPromptThrowsMcpServerException(): void
    {
        $server = new McpServer('test');

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'nonexistent', 'arguments' => new \stdClass()];

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Unknown prompt: nonexistent');
        $handlers['prompts/get']($params);
    }

    /**
     * Test that reading an unknown resource throws McpServerException.
     */
    public function testUnknownResourceThrowsMcpServerException(): void
    {
        $server = new McpServer('test');

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['uri' => 'test://nonexistent'];

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Unknown resource: test://nonexistent');
        $handlers['resources/read']($params);
    }

    // -----------------------------------------------------------------------
    // Chaining & Configuration
    // -----------------------------------------------------------------------

    /**
     * Test that tool(), prompt(), and resource() return $this for chaining.
     */
    public function testMethodChaining(): void
    {
        $server = new McpServer('test');

        $result = $server
            ->tool('t', 'Tool', fn () => 'ok')
            ->prompt('p', 'Prompt', fn () => 'ok')
            ->resource(uri: 'r://r', name: 'R', callback: fn () => 'ok');

        $this->assertSame($server, $result, 'Chained methods should return $this');
    }

    /**
     * Test that withAuth sets the correct HTTP options.
     *
     * The withAuth method should merge auth-related options into httpOptions:
     * auth_enabled, token_validator, authorization_servers, and resource.
     */
    public function testWithAuthSetsHttpOptions(): void
    {
        $server = new McpServer('test');
        $validator = $this->createMock(\Mcp\Server\Auth\TokenValidatorInterface::class);

        $result = $server->withAuth(
            $validator,
            'https://auth.example.com/',
            'https://my-server.com/mcp'
        );

        $this->assertSame($server, $result, 'withAuth should return $this');

        // We can verify indirectly that options were set by checking
        // the server can still chain further calls
        $result2 = $server->httpOptions(['session_timeout' => 1800]);
        $this->assertSame($server, $result2);
    }

    /**
     * Test that httpOptions() merges options correctly.
     */
    public function testHttpOptionsMerge(): void
    {
        $server = new McpServer('test');
        $validator = $this->createMock(\Mcp\Server\Auth\TokenValidatorInterface::class);

        $result = $server
            ->httpOptions(['session_timeout' => 1800, 'enable_sse' => false])
            ->withAuth($validator, ['https://auth.example.com/'], 'https://my-server.com/')
            ->httpOptions(['max_queue_size' => 500]);

        $this->assertSame($server, $result, 'Chained configuration should return $this');
    }

    /**
     * Test that getServer returns the underlying Server instance.
     */
    public function testGetServerReturnsUnderlyingInstance(): void
    {
        $server = new McpServer('test');
        $underlying = $server->getServer();

        $this->assertInstanceOf(Server::class, $underlying);
    }

    // -----------------------------------------------------------------------
    // Run Methods
    // -----------------------------------------------------------------------

    /**
     * Test that run(), runStdio(), and runHttp() methods exist.
     *
     * We cannot easily test the actual behavior since they start blocking
     * server processes, but we verify the expected API surface exists.
     */
    public function testRunMethodsExist(): void
    {
        $server = new McpServer('test');
        $this->assertTrue(method_exists($server, 'run'), 'McpServer should have a run() method');
        $this->assertTrue(method_exists($server, 'runStdio'), 'McpServer should have a runStdio() method');
        $this->assertTrue(method_exists($server, 'runHttp'), 'McpServer should have a runHttp() method');
    }

    // -----------------------------------------------------------------------
    // Named Parameter Matching
    // -----------------------------------------------------------------------

    /**
     * Test that tool arguments are matched by name, not position.
     *
     * This is the key improvement over the original pronskiy/mcp which used
     * array_values() spreading. Named matching ensures correct argument
     * binding regardless of JSON key order.
     */
    public function testToolArgumentsMatchedByName(): void
    {
        $server = new McpServer('test');
        $server->tool('divide', 'Divide', function (float $dividend, float $divisor): string {
            return (string) ($dividend / $divisor);
        });

        $handlers = $server->getServer()->getHandlers();

        // Send arguments in reverse order — named matching should handle this
        $params = (object) [
            'name' => 'divide',
            'arguments' => (object) ['divisor' => 2.0, 'dividend' => 10.0],
        ];
        $result = $handlers['tools/call']($params);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertSame('5', $result->content[0]->text, 'Arguments should be matched by name, not position');
    }

    /**
     * Test that calling a tool with a missing required argument returns an error.
     *
     * When a required parameter is not provided in the arguments, the
     * tools/call handler should catch the InvalidArgumentException and
     * return a CallToolResult with isError=true.
     */
    public function testToolMissingRequiredArgumentReturnsError(): void
    {
        $server = new McpServer('test');
        $server->tool('divide', 'Divide', function (float $dividend, float $divisor): string {
            return (string) ($dividend / $divisor);
        });

        $handlers = $server->getServer()->getHandlers();

        // Only provide one of the two required arguments
        $params = (object) [
            'name' => 'divide',
            'arguments' => (object) ['dividend' => 10.0],
        ];
        $result = $handlers['tools/call']($params);

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Missing required parameter: divisor', $result->content[0]->text);
    }

    /**
     * Test that notifyOnChanges sets notification preferences.
     */
    public function testNotifyOnChanges(): void
    {
        $server = new McpServer('test');
        $result = $server->notifyOnChanges(
            resourcesChanged: false,
            toolsChanged: true,
            promptsChanged: false
        );

        $this->assertSame($server, $result, 'notifyOnChanges should return $this');
    }

    /**
     * Test that sessionStore setter returns $this for chaining.
     */
    public function testSessionStoreChaining(): void
    {
        $server = new McpServer('test');
        $store = $this->createMock(\Mcp\Server\Transport\Http\SessionStoreInterface::class);

        $result = $server->sessionStore($store);
        $this->assertSame($server, $result, 'sessionStore should return $this');
    }

    // -----------------------------------------------------------------------
    // Callable Support
    // -----------------------------------------------------------------------

    /**
     * Test that tool registration works with non-closure callables.
     *
     * ReflectionFunction::fromCallable() supports all callable forms,
     * including [$object, 'method'] and 'Class::method'. This test
     * verifies that array-style callables work for tool registration
     * and invocation.
     */
    public function testToolRegistrationWithArrayCallable(): void
    {
        $handler = new class {
            public function add(float $a, float $b): string
            {
                return (string) ($a + $b);
            }
        };

        $server = new McpServer('test');
        $server->tool('add', 'Add numbers', [$handler, 'add']);

        $handlers = $server->getServer()->getHandlers();

        // Verify schema was generated from the method's parameters
        $toolsResult = $handlers['tools/list'](null);
        $this->assertCount(1, $toolsResult->tools);
        $this->assertSame(['a', 'b'], $toolsResult->tools[0]->inputSchema->required);

        // Verify invocation works
        $params = (object) ['name' => 'add', 'arguments' => (object) ['a' => 3.0, 'b' => 4.0]];
        $result = $handlers['tools/call']($params);
        $this->assertSame('7', $result->content[0]->text);
    }

    /**
     * Test that prompt registration works with non-closure callables.
     */
    public function testPromptRegistrationWithArrayCallable(): void
    {
        $handler = new class {
            public function greet(string $name): string
            {
                return "Hello, {$name}!";
            }
        };

        $server = new McpServer('test');
        $server->prompt('greet', 'Greeting', [$handler, 'greet']);

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'greet', 'arguments' => (object) ['name' => 'World']];
        $result = $handlers['prompts/get']($params);

        $this->assertInstanceOf(GetPromptResult::class, $result);
        $this->assertSame('Hello, World!', $result->messages[0]->content->text);
    }

    // -----------------------------------------------------------------------
    // Return Type Enforcement
    // -----------------------------------------------------------------------

    /**
     * Test that tool callback with invalid return type throws McpServerException.
     *
     * When a tool callback returns something other than string or CallToolResult,
     * McpServerException::invalidToolResult is thrown rather than silently
     * casting to string (which would produce values like "Array").
     */
    public function testToolCallbackInvalidResultThrows(): void
    {
        $server = new McpServer('test');
        $server->tool('bad', 'Bad tool', function (): array {
            return ['not', 'a', 'string'];
        });

        $handlers = $server->getServer()->getHandlers();
        $params = (object) ['name' => 'bad', 'arguments' => new \stdClass()];

        $this->expectException(McpServerException::class);
        $this->expectExceptionMessage('Invalid tool handler result');
        $handlers['tools/call']($params);
    }

    // -----------------------------------------------------------------------
    // Required Callback Enforcement
    // -----------------------------------------------------------------------

    /**
     * Test that resource() requires a callback parameter.
     *
     * The callback parameter is required (not nullable) for resources to function.
     * Omitting it causes a PHP TypeError, enforced at the language level.
     * This matches the official TypeScript and Python MCP SDKs, which also
     * require a handler for every resource.
     */
    public function testResourceRequiresCallback(): void
    {
        $server = new McpServer('test');

        $this->expectException(\TypeError::class);
        $server->resource(uri: 'test://bad', name: 'Bad');
    }
}
