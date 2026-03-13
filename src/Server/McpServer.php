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
 *
 * Filename: Server/McpServer.php
 */

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Server\Auth\TokenValidatorInterface;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\SessionStoreInterface;
use Mcp\Server\Transport\Http\StandardPhpAdapter;
use Mcp\Types\BlobResourceContents;
use Mcp\Types\CallToolResult;
use Mcp\Types\GetPromptResult;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\Prompt;
use Mcp\Types\PromptArgument;
use Mcp\Types\PromptMessage;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\Resource;
use Mcp\Types\Role;
use Mcp\Types\Task;
use Mcp\Types\TaskGetResult;
use Mcp\Types\TaskListResult;
use Mcp\Types\TextContent;
use Mcp\Types\TextResourceContents;
use Mcp\Types\Tool;
use Mcp\Types\ToolInputProperties;
use Mcp\Types\ToolInputSchema;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * Convenience wrapper around the MCP Server.
 *
 * Provides a developer-friendly interface for creating MCP servers with
 * minimal boilerplate. Supports stdio and HTTP transports, optional OAuth
 * authentication, and automatic type conversion.
 *
 * Example usage:
 *
 * ```php
 * $server = new McpServer('my-server');
 * $server
 *     ->tool('add', 'Add numbers', fn(float $a, float $b) => "Sum: " . ($a + $b))
 *     ->prompt('greet', 'Greeting', fn(string $name) => "Hello, {$name}!")
 *     ->resource(uri: 'info://php', name: 'PHP Info', callback: fn() => PHP_VERSION)
 *     ->run();
 * ```
 *
 * Derived from pronskiy/mcp (https://github.com/pronskiy/mcp)
 * Copyright (c) pronskiy <roman@pronskiy.com>
 * Licensed under the MIT License
 *
 * Key changes from original class:
 *
 * - Namespace changed from `Pronskiy\Mcp` to `Mcp\Server`
 * - Class renamed from `Server` to `McpServer` to avoid conflict with existing `Mcp\Server\Server`
 * - Added HTTP transport support (`runHttp()`) using the SDK's `HttpServerRunner` and `StandardPhpAdapter`
 * - Added automatic transport detection (`run()`) calls `runStdio()` for local servers and `runHttp()` for remote servers
 * - Added OAuth authentication support (`withAuth()`) using the SDK's `TokenValidatorInterface`
 * - Added PSR-3 logger support
 * - Removed the static facade for now, to simplify implementation and testing
 */
class McpServer
{
    /** The underlying MCP Server instance. */
    protected Server $server;

    /** @var Tool[] Registered tools. */
    protected array $tools = [];

    /** @var array<string, callable> Registered tool handlers keyed by name. */
    protected array $toolHandlers = [];

    /** @var Prompt[] Registered prompts. */
    protected array $prompts = [];

    /** @var array<string, callable> Registered prompt handlers keyed by name. */
    protected array $promptHandlers = [];

    /** @var Resource[] Registered resources. */
    protected array $resources = [];

    /** @var array<string, callable> Registered resource handlers keyed by URI. */
    protected array $resourceHandlers = [];

    /** @var array<string, mixed> [Added] HTTP transport options. */
    protected array $httpOptions = [];

    /** @var SessionStoreInterface|null [Added] Session store for HTTP transport. */
    protected ?SessionStoreInterface $sessionStore = null;

    /** @var LoggerInterface [Added] PSR-3 logger. */
    protected LoggerInterface $logger;

    /** @var bool [Added] Whether to notify clients of resource changes. */
    protected bool $resourcesChanged = true;

    /** @var bool [Added] Whether to notify clients of tool changes. */
    protected bool $toolsChanged = true;

    /** @var bool [Added] Whether to notify clients of prompt changes. */
    protected bool $promptsChanged = true;

    /** @var TaskManager|null Task manager for long-running operations. */
    protected ?TaskManager $taskManager = null;

    /**
     * Create a new McpServer instance.
     *
     * @param string $name The server name advertised during initialization
     * @param LoggerInterface|null $logger [Added] Optional PSR-3 logger
     */
    public function __construct(string $name, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->server = new Server($name, $this->logger);
        $this->registerDefaultHandlers();
    }

    // -----------------------------------------------------------------------
    // Registration Methods
    // -----------------------------------------------------------------------

    /**
     * Define a new tool.
     *
     * The input schema is automatically generated from the callback's parameters
     * using reflection. The callback can return a string (auto-wrapped in
     * CallToolResult), an array (auto-wrapped as structured content), or a
     * CallToolResult directly.
     *
     * @param string $name The tool name
     * @param string $description A description of what the tool does
     * @param callable $callback The function that implements the tool
     * @param string|null $title Display title for the tool
     * @param array<int, array<string, mixed>>|null $icons Icons for the tool
     * @param array<string, mixed>|null $outputSchema JSON Schema for structured output
     * @return self For method chaining
     */
    public function tool(
        string $name,
        string $description,
        callable $callback,
        ?string $title = null,
        ?array $icons = null,
        ?array $outputSchema = null,
    ): self {
        $schema = $this->buildSchemaFromCallback($callback);

        $tool = new Tool(
            name: $name,
            inputSchema: $schema,
            description: $description,
            title: $title,
            icons: \Mcp\Types\Icon::parseArray($icons),
            outputSchema: $outputSchema,
        );

        $this->tools[] = $tool;

        $this->toolHandlers[$name] = function ($args) use ($callback, $outputSchema) {
            $arguments = json_decode(json_encode($args), true) ?? [];
            $ordered = $this->matchNamedParameters($callback, $arguments);

            $result = $callback(...$ordered);

            if ($result instanceof CallToolResult) {
                return $result;
            }

            if (is_string($result)) {
                return new CallToolResult(
                    content: [new TextContent(text: $result)]
                );
            }

            // When outputSchema is set and callback returns array/object, populate both content and structuredContent
            if ($outputSchema !== null && (is_array($result) || is_object($result))) {
                $jsonResult = is_object($result) ? (array)$result : $result;
                return new CallToolResult(
                    content: [new TextContent(text: json_encode($jsonResult, JSON_UNESCAPED_SLASHES))],
                    structuredContent: $jsonResult
                );
            }

            throw McpServerException::invalidToolResult($result);
        };

        return $this;
    }

    /**
     * Define a new prompt.
     *
     * The arguments are automatically generated from the callback's parameters
     * using reflection. The callback can return a string, an array of strings,
     * or a GetPromptResult directly.
     *
     * @param string $name The prompt name
     * @param string $description A description of the prompt
     * @param callable $callback The function that implements the prompt
     * @param string|null $title Display title for the prompt
     * @param array<int, array<string, mixed>>|null $icons Icons for the prompt
     * @return self For method chaining
     * @throws McpServerException If the callback returns an invalid result
     */
    public function prompt(
        string $name,
        string $description,
        callable $callback,
        ?string $title = null,
        ?array $icons = null,
    ): self {
        $arguments = $this->buildArgumentsFromCallback($callback);

        $prompt = new Prompt(
            name: $name,
            description: $description,
            arguments: $arguments,
            title: $title,
            icons: \Mcp\Types\Icon::parseArray($icons),
        );

        $this->prompts[] = $prompt;

        // [Modified from pronskiy/mcp] Use named parameter matching.
        $this->promptHandlers[$name] = function ($args) use ($callback) {
            $arguments = json_decode(json_encode($args), true) ?? [];
            $ordered = $this->matchNamedParameters($callback, $arguments);

            $result = $callback(...$ordered);

            if ($result instanceof GetPromptResult) {
                return $result;
            }

            if (is_string($result)) {
                return new GetPromptResult(
                    messages: [
                        new PromptMessage(
                            role: Role::USER,
                            content: new TextContent(text: $result)
                        ),
                    ]
                );
            }

            if (is_array($result)) {
                $messages = [];
                foreach ($result as $message) {
                    $messages[] = new PromptMessage(
                        role: Role::USER,
                        content: new TextContent(text: (string) $message)
                    );
                }
                return new GetPromptResult(messages: $messages);
            }

            throw McpServerException::invalidPromptResult($result);
        };

        return $this;
    }

    /**
     * Define a new resource.
     *
     * The callback should return a string (auto-wrapped in ReadResourceResult),
     * an SplFileObject or resource (base64-encoded as blob), or a
     * ReadResourceResult directly.
     *
     * @param string $uri The resource URI
     * @param string $name The resource name
     * @param callable $callback The callback that returns the resource content
     * @param string $description The resource description
     * @param string $mimeType The MIME type
     * @param string|null $title Display title for the resource
     * @param array<int, array<string, mixed>>|null $icons Icons for the resource
     * @param int|null $size Resource size in bytes
     * @return self For method chaining
     * @throws McpServerException If the callback returns an invalid result
     */
    public function resource(
        string $uri,
        string $name,
        callable $callback,
        string $description = '',
        string $mimeType = 'text/plain',
        ?string $title = null,
        ?array $icons = null,
        ?int $size = null,
    ): self {

        $resource = new Resource(
            name: $name,
            uri: $uri,
            description: $description,
            mimeType: $mimeType,
            title: $title,
            icons: \Mcp\Types\Icon::parseArray($icons),
            size: $size,
        );

        $this->resources[] = $resource;

        $this->resourceHandlers[$uri] = function () use ($callback, $uri, $mimeType) {
            $result = $callback();

            if ($result instanceof ReadResourceResult) {
                return $result;
            }

            if (is_string($result)) {
                return new ReadResourceResult(
                    contents: [
                        new TextResourceContents(
                            text: $result,
                            uri: $uri,
                            mimeType: $mimeType
                        ),
                    ]
                );
            }

            if ($result instanceof \SplFileObject || is_resource($result)) {
                $content = '';
                if ($result instanceof \SplFileObject) {
                    $content = $result->fread($result->getSize());
                } else {
                    $content = stream_get_contents($result);
                }

                return new ReadResourceResult(
                    contents: [
                        new BlobResourceContents(
                            blob: base64_encode($content),
                            uri: $uri,
                            mimeType: $mimeType
                        ),
                    ]
                );
            }

            throw McpServerException::invalidResourceResult($result);
        };

        return $this;
    }

    // -----------------------------------------------------------------------
    // Configuration — [Added]
    // -----------------------------------------------------------------------

    /**
     * [Added] Set HTTP transport options.
     *
     * @param array<string, mixed> $options Options passed to HttpServerTransport (see Config.php)
     * @return self For method chaining
     */
    public function httpOptions(array $options): self
    {
        $this->httpOptions = array_merge($this->httpOptions, $options);
        return $this;
    }

    /**
     * [Added] Set the session store for HTTP transport.
     *
     * @param SessionStoreInterface $store Session store implementation
     * @return self For method chaining
     */
    public function sessionStore(SessionStoreInterface $store): self
    {
        $this->sessionStore = $store;
        return $this;
    }

    /**
     * [Added] Configure OAuth authentication for the HTTP transport.
     *
     * @param TokenValidatorInterface $tokenValidator Token validator implementation
     * @param string|array<int, string> $authorizationServers One or more authorization server URLs
     * @param string $resourceId The protected resource identifier
     * @return self For method chaining
     */
    public function withAuth(
        TokenValidatorInterface $tokenValidator,
        string|array $authorizationServers,
        string $resourceId
    ): self {
        $servers = is_string($authorizationServers) ? [$authorizationServers] : $authorizationServers;

        $this->httpOptions = array_merge($this->httpOptions, [
            'auth_enabled' => true,
            'token_validator' => $tokenValidator,
            'authorization_servers' => $servers,
            'resource' => $resourceId,
        ]);

        return $this;
    }

    /**
     * [Added] Configure which change notifications to send.
     *
     * Alternative to passing parameters to run(). Affects both runStdio() and runHttp().
     *
     * @return self For method chaining
     */
    public function notifyOnChanges(
        bool $resourcesChanged = true,
        bool $toolsChanged = true,
        bool $promptsChanged = true
    ): self {
        $this->resourcesChanged = $resourcesChanged;
        $this->toolsChanged = $toolsChanged;
        $this->promptsChanged = $promptsChanged;
        return $this;
    }

    /**
     * Enable task support for long-running operations.
     *
     * Registers tasks/get, tasks/list, tasks/cancel, and tasks/result handlers.
     *
     * @param string|null $storagePath Directory for task file storage (null = system temp)
     * @return self For method chaining
     */
    public function enableTasks(?string $storagePath = null): self
    {
        $this->taskManager = new TaskManager($storagePath ?? '');

        $this->server->registerHandler('tasks/get', function ($params) {
            $taskId = $params->taskId ?? '';
            $task = $this->taskManager->getTask($taskId);
            if ($task === null) {
                throw McpServerException::taskNotFound($taskId);
            }
            return TaskGetResult::fromTask($task);
        });

        $this->server->registerHandler('tasks/list', function ($params) {
            $tasks = $this->taskManager->listTasks();
            return new TaskListResult(tasks: $tasks);
        });

        $this->server->registerHandler('tasks/cancel', function ($params) {
            $taskId = $params->taskId ?? '';
            $task = $this->taskManager->getTask($taskId);
            if ($task === null) {
                throw McpServerException::taskNotFound($taskId);
            }
            try {
                $cancelled = $this->taskManager->cancelTask($taskId);
            } catch (\InvalidArgumentException $e) {
                throw McpServerException::taskNotCancellable($taskId, $task->status);
            }
            return TaskGetResult::fromTask($cancelled);
        });

        $this->server->registerHandler('tasks/result', function ($params) {
            $taskId = $params->taskId ?? '';
            $task = $this->taskManager->getTask($taskId);
            if ($task === null) {
                throw McpServerException::taskNotFound($taskId);
            }
            $taskResult = $this->taskManager->getResult($taskId);
            if ($taskResult === null) {
                throw McpServerException::taskResultNotAvailable($taskId);
            }
            // Return the underlying result directly with related-task metadata.
            // Inject _meta with io.modelcontextprotocol/related-task per spec.
            $taskResult['_meta'] = array_merge(
                $taskResult['_meta'] ?? [],
                ['io.modelcontextprotocol/related-task' => ['taskId' => $taskId]]
            );
            return CallToolResult::fromResponseData($taskResult);
        });

        return $this;
    }

    /**
     * Get the TaskManager instance (if tasks are enabled).
     */
    public function getTaskManager(): ?TaskManager
    {
        return $this->taskManager;
    }

    // -----------------------------------------------------------------------
    // Run Methods
    // -----------------------------------------------------------------------

    /**
     * Run the server using stdio transport.
     *
     * [Modified from pronskiy/mcp] Logs errors via PSR-3 logger and rethrows
     * instead of echoing to stdout.
     *
     * @param bool|null $resourcesChanged Whether to notify clients when resources change (null = use notifyOnChanges value)
     * @param bool|null $toolsChanged Whether to notify clients when tools change (null = use notifyOnChanges value)
     * @param bool|null $promptsChanged Whether to notify clients when prompts change (null = use notifyOnChanges value)
     * @throws \Throwable If an error occurs while running the server
     */
    public function runStdio(
        ?bool $resourcesChanged = null,
        ?bool $toolsChanged = null,
        ?bool $promptsChanged = null
    ): void {
        $notificationOptions = new NotificationOptions(
            promptsChanged: $promptsChanged ?? $this->promptsChanged,
            resourcesChanged: $resourcesChanged ?? $this->resourcesChanged,
            toolsChanged: $toolsChanged ?? $this->toolsChanged,
        );

        $initOptions = $this->server->createInitializationOptions($notificationOptions);
        $runner = new ServerRunner($this->server, $initOptions, $this->logger);

        try {
            $runner->run();
        } catch (\Throwable $e) {
            $this->logger->error('McpServer error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * [Added] Run the server using HTTP transport.
     *
     * Uses the SDK's HttpServerRunner and StandardPhpAdapter to handle
     * HTTP requests in a standard PHP web server environment.
     *
     * @throws \Throwable If an error occurs while running the server
     */
    public function runHttp(): void
    {
        $notificationOptions = new NotificationOptions(
            promptsChanged: $this->promptsChanged,
            resourcesChanged: $this->resourcesChanged,
            toolsChanged: $this->toolsChanged
        );

        $initOptions = $this->server->createInitializationOptions($notificationOptions);

        $sessionStore = $this->sessionStore
            ?? new FileSessionStore(sys_get_temp_dir() . '/mcp_sessions');

        $runner = new HttpServerRunner(
            $this->server,
            $initOptions,
            $this->httpOptions,
            $this->logger,
            $sessionStore
        );

        $adapter = new StandardPhpAdapter($runner);
        $adapter->handle();
    }

    /**
     * [Added] Auto-detect transport and run.
     *
     * Uses stdio when running from CLI, HTTP when running in a web server.
     */
    public function run(): void
    {
        if (PHP_SAPI === 'cli') {
            $this->runStdio();
        } else {
            $this->runHttp();
        }
    }

    // -----------------------------------------------------------------------
    // Escape Hatch
    // -----------------------------------------------------------------------

    /**
     * [Added] Access the underlying Mcp\Server\Server instance.
     *
     * Use this to register low-level handlers or access advanced features
     * not exposed by the convenience wrapper.
     */
    public function getServer(): Server
    {
        return $this->server;
    }

    // -----------------------------------------------------------------------
    // Internal — Handler Registration
    // -----------------------------------------------------------------------

    /**
     * Register the default MCP handlers for tools, prompts, and resources.
     */
    protected function registerDefaultHandlers(): void
    {
        $this->server->registerHandler('tools/list', function () {
            return new ListToolsResult(array_values($this->tools));
        });

        $this->server->registerHandler('tools/call', function ($params) {
            $name = $params->name;
            $arguments = $params->arguments ?? new \stdClass();

            if (!isset($this->toolHandlers[$name])) {
                throw McpServerException::unknownTool($name);
            }

            $handler = $this->toolHandlers[$name];

            try {
                $result = $handler($arguments);
            } catch (McpServerException $e) {
                throw $e; // Programming errors should propagate
            } catch (\Throwable $e) {
                return new CallToolResult(
                    content: [new TextContent(text: 'Error: ' . $e->getMessage())],
                    isError: true
                );
            }

            return $result;
        });

        $this->server->registerHandler('prompts/list', function () {
            return new ListPromptsResult(array_values($this->prompts));
        });

        $this->server->registerHandler('prompts/get', function ($params) {
            $name = $params->name;
            $arguments = $params->arguments ?? new \stdClass();

            if (!isset($this->promptHandlers[$name])) {
                throw McpServerException::unknownPrompt($name);
            }

            $handler = $this->promptHandlers[$name];
            return $handler($arguments);
        });

        $this->server->registerHandler('resources/list', function () {
            return new ListResourcesResult(array_values($this->resources));
        });

        $this->server->registerHandler('resources/read', function ($params) {
            $uri = $params->uri;

            if (!isset($this->resourceHandlers[$uri])) {
                throw McpServerException::unknownResource($uri);
            }

            $handler = $this->resourceHandlers[$uri];
            return $handler();
        });
    }

    // -----------------------------------------------------------------------
    // Internal — Reflection Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a ToolInputSchema from a callback's parameter list using reflection.
     */
    protected function buildSchemaFromCallback(callable $callback): ToolInputSchema
    {
        $reflection = new ReflectionFunction(\Closure::fromCallable($callback));
        $parameters = $reflection->getParameters();

        $properties = [];
        $required = [];

        foreach ($parameters as $param) {
            $name = $param->getName();
            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'string';

            $jsonType = match ($typeName) {
                'int', 'float' => 'number',
                'bool' => 'boolean',
                'array' => 'array',
                'object', 'stdClass' => 'object',
                default => 'string',
            };

            $properties[$name] = [
                'type' => $jsonType,
                'description' => "Parameter: {$name}",
            ];

            if (!$param->isOptional()) {
                $required[] = $name;
            }
        }

        return new ToolInputSchema(
            properties: ToolInputProperties::fromArray($properties),
            required: $required
        );
    }

    /**
     * Build PromptArgument list from a callback's parameter list using reflection.
     *
     * @return array<int, PromptArgument>
     */
    protected function buildArgumentsFromCallback(callable $callback): array
    {
        $reflection = new ReflectionFunction(\Closure::fromCallable($callback));
        $parameters = $reflection->getParameters();

        $arguments = [];

        foreach ($parameters as $param) {
            $arguments[] = new PromptArgument(
                name: $param->getName(),
                description: "Parameter: {$param->getName()}",
                required: !$param->isOptional()
            );
        }

        return $arguments;
    }

    /**
     * [Added] Match named arguments from a JSON object to a callback's parameters.
     *
     * Uses reflection to map argument names to parameter positions, providing
     * correct ordering regardless of JSON key order.
     *
     * @param callable $callback The target callback
     * @param array<string, mixed> $arguments Associative array of arguments
     * @return array<int, mixed> Ordered arguments matching the callback's parameter list
     */
    protected function matchNamedParameters(callable $callback, array $arguments): array
    {
        $reflection = new ReflectionFunction(\Closure::fromCallable($callback));
        $parameters = $reflection->getParameters();
        $ordered = [];

        foreach ($parameters as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $arguments)) {
                $ordered[] = $arguments[$name];
            } elseif ($param->isOptional()) {
                $ordered[] = $param->getDefaultValue();
            } else {
                throw new \InvalidArgumentException("Missing required parameter: {$name}");
            }
        }

        return $ordered;
    }
}
