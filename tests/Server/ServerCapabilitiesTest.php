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
use Mcp\Types\TaskCapability;
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
 * - Builds TaskCapability from task-related handler combinations
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
     * tools, logging, or tasks), all capability fields should be null.
     * This ensures the server does not falsely advertise features it cannot provide.
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
        $this->assertNull($capabilities->tasks, 'Tasks capability should be null without task handlers');
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
     * Test that registering a tasks/get handler enables the tasks capability.
     *
     * A single tasks/get handler is sufficient to create a TaskCapability object.
     * Without tasks/list or tasks/cancel handlers, those fields should be null.
     */
    public function testTaskGetHandlerEnablesTasksCapability(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('tasks/get', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertInstanceOf(TaskCapability::class, $capabilities->tasks);
        $this->assertNull($capabilities->tasks->list, 'List should be null without tasks/list handler');
        $this->assertNull($capabilities->tasks->cancel, 'Cancel should be null without tasks/cancel handler');
        $this->assertNull($capabilities->tasks->requests, 'Requests should be null without tools/call handler');
    }

    /**
     * Test that registering all task handlers populates list and cancel in TaskCapability.
     *
     * When tasks/get, tasks/list, and tasks/cancel handlers are all registered,
     * the TaskCapability should have list=true and cancel=true. This tells
     * clients the full range of task management operations is available.
     */
    public function testTasksCapabilityIncludesListAndCancel(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('tasks/get', function (?RequestParams $params): Result {
            return new Result();
        });
        $server->registerHandler('tasks/list', function (?RequestParams $params): Result {
            return new Result();
        });
        $server->registerHandler('tasks/cancel', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertInstanceOf(TaskCapability::class, $capabilities->tasks);
        $this->assertTrue($capabilities->tasks->list, 'List should be true when tasks/list is registered');
        $this->assertTrue($capabilities->tasks->cancel, 'Cancel should be true when tasks/cancel is registered');
    }

    /**
     * Test that TaskCapability includes tools/call in requests when tools/call is registered.
     *
     * When both a task handler (tasks/get) and tools/call are registered, the
     * TaskCapability.requests field should include a 'tools' key with a 'call'
     * sub-key. This signals that the server supports tool invocation within
     * task workflows.
     */
    public function testTasksRequestsIncludesToolsCallWhenRegistered(): void
    {
        $server = new Server('test-server');
        $server->registerHandler('tasks/get', function (?RequestParams $params): Result {
            return new Result();
        });
        $server->registerHandler('tools/call', function (?RequestParams $params): Result {
            return new Result();
        });

        $capabilities = $server->getCapabilities(new NotificationOptions(), []);

        $this->assertInstanceOf(TaskCapability::class, $capabilities->tasks);
        $this->assertIsArray($capabilities->tasks->requests);
        $this->assertArrayHasKey('tools', $capabilities->tasks->requests);
        $this->assertArrayHasKey('call', $capabilities->tasks->requests['tools']);
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
