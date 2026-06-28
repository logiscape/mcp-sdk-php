<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
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

use Mcp\Server\Server;
use Mcp\Server\NotificationOptions;
use Mcp\Server\InitializationOptions;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\ServerPromptsCapability;
use Mcp\Types\ServerResourcesCapability;
use Mcp\Types\ServerToolsCapability;
use Mcp\Types\ServerLoggingCapability;
use Mcp\Types\ExtensionIds;
use Mcp\Types\Result;
use Mcp\Types\RequestParams;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Server capability detection and initialization options.
 *
 * Validates that the Server correctly:
 * - Detects capabilities based on registered handlers
 * - Returns null capabilities when no handlers are registered
 * - Propagates notification options to capability objects
 * - Declares the SEP-2663 Tasks extension via the SEP-2133 extensions map
 *   when a tasks/get handler is registered
 * - Creates proper InitializationOptions with server metadata
 * - Registers a built-in ping handler in the constructor
 *
 * Capability detection is critical because clients use the reported
 * capabilities to determine which protocol features the server supports.
 * Incorrect capability reporting leads to client-side errors or missed
 * functionality.
 */
final class ServerCapabilitiesTest extends TestCase
{
    /**
     * Test that a fresh server with no user-registered handlers reports null capabilities.
     *
     * A newly constructed Server only has the built-in 'ping' handler.
     * Since ping is not tied to any capability category (prompts, resources,
     * tools, logging, or the tasks extension), all capability fields should be
     * null. This ensures the server does not falsely advertise features it
     * cannot provide.
     */
    public function testNoHandlersNullCapabilities(): void
    {
        $server = new Server('test-server');
        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertInstanceOf(ServerCapabilities::class, $capabilities);
        $this->assertNull($capabilities->prompts, 'Prompts capability should be null without prompts/list handler');
        $this->assertNull($capabilities->resources, 'Resources capability should be null without resources/list handler');
        $this->assertNull($capabilities->tools, 'Tools capability should be null without tools/list handler');
        $this->assertNull($capabilities->logging, 'Logging capability should be null without logging/setLevel handler');
        $this->assertNull($capabilities->extensions, 'Extensions map should be null without the tasks/get handler');
    }

    /**
     * Test that registering a prompts/list handler enables the prompts capability.
     *
     * When a handler is registered for 'prompts/list', the server should report
     * a ServerPromptsCapability in the capabilities object. This signals to clients
     * that they can list and retrieve prompts from this server.
     */
    public function testPromptsListHandlerEnablesPromptsCapability(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('prompts/list', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertInstanceOf(ServerPromptsCapability::class, $capabilities->prompts);
        $this->assertNull($capabilities->resources, 'Resources should remain null');
        $this->assertNull($capabilities->tools, 'Tools should remain null');
    }

    /**
     * Test that registering a resources/list handler enables the resources capability.
     *
     * When a handler is registered for 'resources/list', the server should report
     * a ServerResourcesCapability. The subscribe field defaults to false, and
     * listChanged reflects the notification options.
     */
    public function testResourcesListHandlerEnablesResourcesCapability(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('resources/list', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertInstanceOf(ServerResourcesCapability::class, $capabilities->resources);
        $this->assertNull($capabilities->prompts, 'Prompts should remain null');
        $this->assertNull($capabilities->tools, 'Tools should remain null');
    }

    /**
     * Test that registering a tools/list handler enables the tools capability.
     *
     * When a handler is registered for 'tools/list', the server should report
     * a ServerToolsCapability. This tells clients they can discover and call
     * tools provided by this server.
     */
    public function testToolsListHandlerEnablesToolsCapability(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('tools/list', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertInstanceOf(ServerToolsCapability::class, $capabilities->tools);
        $this->assertNull($capabilities->prompts, 'Prompts should remain null');
        $this->assertNull($capabilities->resources, 'Resources should remain null');
    }

    /**
     * Test that registering a logging/setLevel handler enables the logging capability.
     *
     * When a handler is registered for 'logging/setLevel', the server should report
     * a ServerLoggingCapability. This allows clients to control the server's log level.
     */
    public function testLoggingSetLevelHandlerEnablesLoggingCapability(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('logging/setLevel', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertInstanceOf(ServerLoggingCapability::class, $capabilities->logging);
    }

    /**
     * Test that registering a tasks/get handler declares the SEP-2663 Tasks
     * extension through the SEP-2133 extensions map.
     *
     * Under revision 2026-07-28 there is no dedicated `tasks` capability slot:
     * the extension is advertised as a key under `capabilities.extensions`
     * whose value is the empty object `{}` (no extension-specific settings).
     */
    public function testTaskGetHandlerDeclaresTasksExtension(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('tasks/get', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertIsArray($capabilities->extensions);
        $this->assertArrayHasKey(ExtensionIds::TASKS, $capabilities->extensions);
        $this->assertSame([], $capabilities->extensions[ExtensionIds::TASKS], 'Tasks extension carries no settings');
    }

    /**
     * Test that the declared Tasks extension serializes as an object-of-objects
     * on the wire, with the empty settings becoming `{}` (not `[]`).
     *
     * The list/cancel/requests sub-capabilities of the old pre-release
     * TaskCapability no longer exist; the registration of tasks/get alone
     * declares the extension, and additional task handlers do not change its
     * (empty) settings value.
     */
    public function testTasksExtensionSerializesAsEmptyObject(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('tasks/get', function (?RequestParams $params): Result {
            return new Result();
        });
        $server->registerHandler('tasks/cancel', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        // Round-trip through json to assert the wire shape: the extension value
        // must be an empty object {} (not an empty array []).
        $decoded = json_decode(json_encode($capabilities));
        $this->assertObjectHasProperty('extensions', $decoded);
        $this->assertObjectHasProperty(ExtensionIds::TASKS, $decoded->extensions);
        $this->assertEquals(new \stdClass(), $decoded->extensions->{ExtensionIds::TASKS});
    }

    /**
     * Test that registering tools/call alongside tasks/get does not change the
     * Tasks extension's (empty) settings value.
     *
     * The old TaskCapability.requests slot (which advertised tools/call task
     * augmentation) was removed in SEP-2663; per-tool task support is now a
     * server-side McpServer::tool($taskSupport) knob, not a wire capability.
     */
    public function testToolsCallDoesNotAffectTasksExtension(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('tasks/get', function (?RequestParams $params): Result {
            return new Result();
        });
        $server->registerHandler('tools/call', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertIsArray($capabilities->extensions);
        $this->assertSame([], $capabilities->extensions[ExtensionIds::TASKS]);
    }

    /**
     * Test that NotificationOptions values propagate to capability objects.
     *
     * When promptsChanged is true in NotificationOptions and a prompts/list
     * handler is registered, the resulting ServerPromptsCapability should have
     * listChanged=true. This controls whether the server will emit
     * notifications/prompts/list_changed notifications to clients.
     */
    public function testNotificationOptionsPropagateToCapabilities(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('prompts/list', function (?RequestParams $params): Result {
            return new Result();
        });
        $server->registerHandler('resources/list', function (?RequestParams $params): Result {
            return new Result();
        });
        $server->registerHandler('tools/list', function (?RequestParams $params): Result {
            return new Result();
        });

        $notificationOptions = new NotificationOptions(
            promptsChanged: true,
            resourcesChanged: true,
            toolsChanged: true,
        );
        $capabilities = $server->getCapabilities($notificationOptions, []);

        $this->assertInstanceOf(ServerPromptsCapability::class, $capabilities->prompts);
        $this->assertTrue($capabilities->prompts->listChanged, 'Prompts listChanged should be true');

        $this->assertInstanceOf(ServerResourcesCapability::class, $capabilities->resources);
        $this->assertTrue($capabilities->resources->listChanged, 'Resources listChanged should be true');

        $this->assertInstanceOf(ServerToolsCapability::class, $capabilities->tools);
        $this->assertTrue($capabilities->tools->listChanged, 'Tools listChanged should be true');
    }

    /**
     * Test that createInitializationOptions returns a correctly structured object.
     *
     * The InitializationOptions must contain the server name passed to the
     * constructor, a server version of '1.0.0', and a ServerCapabilities
     * object reflecting the currently registered handlers. This is the data
     * sent to clients during the initialization handshake.
     */
    public function testCreateInitializationOptionsReturnsCorrectStructure(): void
    {
        $server = new Server('my-test-server');
        $server->registerHandler('tools/list', function (?RequestParams $params): Result {
            return new Result();
        });

        $options = $server->createInitializationOptions();

        $this->assertInstanceOf(InitializationOptions::class, $options);
        $this->assertSame('my-test-server', $options->serverName);
        $this->assertSame('1.0.0', $options->serverVersion);
        $this->assertInstanceOf(ServerCapabilities::class, $options->capabilities);
        $this->assertInstanceOf(ServerToolsCapability::class, $options->capabilities->tools);
    }

    /**
     * Test that the built-in ping handler is registered and functional.
     *
     * The Server constructor automatically registers a 'ping' handler that
     * returns an empty Result. This handler is required by the MCP protocol
     * for connection health checks. Verify it exists in the handlers map
     * and that invoking it with null params returns a Result instance.
     */
    public function testBuiltInPingHandlerExists(): void
    {
        $server = new Server('test-server');
        $handlers = $server->getHandlers();

        $this->assertArrayHasKey('ping', $handlers, 'Built-in ping handler should be registered');
        $this->assertIsCallable($handlers['ping']);

        $result = ($handlers['ping'])(null);
        $this->assertInstanceOf(Result::class, $result);
    }
}
