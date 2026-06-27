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
use Mcp\Server\ClientRequestSuspendException;
use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\Elicitation\ElicitationDeclinedException;
use Mcp\Server\Elicitation\ElicitationSuspendException;
use Mcp\Server\InputRequired\InputContext;
use Mcp\Server\InputRequired\InputExchange;
use Mcp\Server\InputRequired\InputRequiredSuspendException;
use Mcp\Server\InputRequired\RequestStateCodec;
use Mcp\Server\Sampling\SamplingContext;
use Mcp\Server\Sampling\SamplingSuspendException;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\HttpIoInterface;
use Mcp\Server\Transport\Http\SessionStoreInterface;
use Mcp\Server\Transport\Http\StandardPhpAdapter;
use Mcp\Types\BlobResourceContents;
use Mcp\Types\CallToolResult;
use Mcp\Types\CompleteResult;
use Mcp\Types\CompletionContext;
use Mcp\Types\CompletionObject;
use Mcp\Types\GetPromptResult;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListResourceTemplatesResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\Prompt;
use Mcp\Types\PromptArgument;
use Mcp\Types\PromptMessage;
use Mcp\Types\PromptReference;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\Resource;
use Mcp\Types\ResourceReference;
use Mcp\Types\ResourceTemplate;
use Mcp\Types\Role;
use Mcp\Types\Task;
use Mcp\Types\TaskGetResult;
use Mcp\Types\TaskListResult;
use Mcp\Types\InputRequiredResult;
use Mcp\Types\TextContent;
use Mcp\Types\TextResourceContents;
use Mcp\Types\Meta;
use Mcp\Types\ProgressToken;
use Mcp\Types\Tool;
use Mcp\Types\ToolInputProperties;
use Mcp\Types\ToolInputSchema;
use Mcp\Shared\ErrorData;
use Mcp\Shared\McpError;
use Mcp\Shared\McpHeaders;
use Mcp\Shared\ProgressContext;
use Mcp\Shared\UriTemplate;
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

    /** @var ResourceTemplate[] Registered resource templates. */
    protected array $resourceTemplates = [];

    /**
     * @var array<int, array{matcher: UriTemplate, handler: callable, mimeType: string}>
     *      Compiled template handlers, in registration order.
     */
    protected array $resourceTemplateHandlers = [];

    /** @var array<string, callable> Prompt-argument completion providers, keyed "promptName\0argName". */
    protected array $promptCompletionProviders = [];

    /** @var array<string, callable> Resource-template completion providers, keyed "uriTemplate\0argName". */
    protected array $resourceTemplateCompletionProviders = [];

    /** @var bool Whether the completion/complete handler has been registered. */
    protected bool $completionHandlerRegistered = false;

    /** @var array<string, mixed> [Added] HTTP transport options. */
    protected array $httpOptions = [];

    /**
     * Bus backing subscriptions/listen event fan-out (SEP-2575). Null
     * when no cross-request channel is configured.
     */
    protected ?\Mcp\Server\Subscriptions\SubscriptionBusInterface $subscriptionBus = null;

    /**
     * SEP-2322: the multi-round-trip exchange of the modern request
     * currently being dispatched. Set around tools/call and prompts/get
     * dispatch; the handler-context objects read it at construction.
     */
    protected ?InputExchange $currentExchange = null;

    /**
     * SEP-2322 requestState signer. Lazily defaults to the per-installation
     * file-backed secret; override via inputStateCodec().
     */
    protected ?RequestStateCodec $stateCodec = null;

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

    /** @var array<string, bool> Tool names that require ElicitationContext injection. */
    protected array $toolsNeedElicitation = [];

    /** @var array<string, bool> Tool names that require SamplingContext injection. */
    protected array $toolsNeedSampling = [];

    /**
     * Create a new McpServer instance.
     *
     * @param string $name The server name advertised during initialization
     * @param LoggerInterface|null $logger [Added] Optional PSR-3 logger
     * @param string $version The server version advertised during initialization
     */
    public function __construct(
        string $name,
        ?LoggerInterface $logger = null,
        string $version = '1.0.0',
    )
    {
        $this->logger = $logger ?? new NullLogger();
        $this->server = new Server($name, $this->logger, $version);
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
     * @param array<string, mixed>|null $inputSchema Custom JSON Schema for input (overrides reflection-generated schema)
     * @return self For method chaining
     */
    public function tool(
        string $name,
        string $description,
        callable $callback,
        ?string $title = null,
        ?array $icons = null,
        ?array $outputSchema = null,
        ?array $inputSchema = null,
    ): self {
        $schema = $inputSchema !== null
            ? ToolInputSchema::fromArray(array_merge(['type' => 'object'], $inputSchema))
            : $this->buildSchemaFromCallback($callback);

        $tool = new Tool(
            name: $name,
            inputSchema: $schema,
            description: $description,
            title: $title,
            icons: \Mcp\Types\Icon::parseArray($icons),
            outputSchema: $outputSchema,
        );

        $this->tools[] = $tool;

        // Detect if callback needs ElicitationContext, SamplingContext, or ProgressContext
        $needsElicitation = $this->callbackNeedsElicitation($callback);
        if ($needsElicitation) {
            $this->toolsNeedElicitation[$name] = true;
        }
        $needsSampling = $this->callbackNeedsSampling($callback);
        if ($needsSampling) {
            $this->toolsNeedSampling[$name] = true;
        }
        $needsProgress = $this->callbackNeedsProgress($callback);
        $needsInput = $this->callbackNeedsInputContext($callback);

        $this->toolHandlers[$name] = function ($args, ?Meta $meta = null) use ($name, $tool, $callback, $outputSchema, $needsElicitation, $needsSampling, $needsProgress, $needsInput) {
            $arguments = json_decode(json_encode($args), true) ?? [];

            // SEP-2243: on the modern HTTP path, arguments designated by an
            // x-mcp-header annotation must arrive mirrored in Mcp-Param-*
            // headers that match the body; a missing, undecodable, or
            // mismatched header is rejected 400/-32020.
            $this->validateMcpParamHeaders($tool, $arguments);

            // Check for preloaded elicitation/sampling results (HTTP resume path)
            $elicitationResults = [];
            if (is_object($args) && isset($args->_elicitationResults)) {
                $elicitationResults = (array) $args->_elicitationResults;
            }
            $samplingResults = [];
            if (is_object($args) && isset($args->_samplingResults)) {
                $samplingResults = (array) $args->_samplingResults;
            }

            $elicitContext = null;
            if ($needsElicitation) {
                $session = $this->server->getSession();
                $isHttpMode = ($session instanceof HttpServerSession);
                $elicitContext = new ElicitationContext(
                    session: $session,
                    httpMode: $isHttpMode,
                    preloadedResults: $elicitationResults,
                    toolName: $name,
                    toolArguments: $arguments,
                    originalRequestId: 0, // Set by HttpServerSession when catching suspend
                    exchange: $this->currentExchange,
                );
            }

            $samplingContext = null;
            if ($needsSampling) {
                $session = $this->server->getSession();
                $isHttpMode = ($session instanceof HttpServerSession);
                $samplingContext = new SamplingContext(
                    session: $session,
                    httpMode: $isHttpMode,
                    preloadedResults: $samplingResults,
                    toolName: $name,
                    toolArguments: $arguments,
                    originalRequestId: 0,
                    exchange: $this->currentExchange,
                );
            }

            $inputContext = null;
            if ($needsInput) {
                $session = $this->server->getSession();
                if ($session instanceof ServerSession) {
                    $inputContext = new InputContext($session, $this->currentExchange);
                }
            }

            // Create ProgressContext if callback needs it and a progressToken was provided
            $progressContext = null;
            if ($needsProgress && $meta !== null) {
                $rawToken = $meta->progressToken ?? null;
                if ($rawToken !== null) {
                    $token = $rawToken instanceof ProgressToken ? $rawToken : new ProgressToken($rawToken);
                    $session = $this->server->getSession();
                    if ($session !== null) {
                        $progressContext = new ProgressContext($session, $token);
                    }
                }
            }

            $ordered = $this->matchNamedParameters($callback, $arguments, $elicitContext, $progressContext, $samplingContext, $inputContext);

            $result = $callback(...$ordered);

            if ($result instanceof CallToolResult) {
                return $result;
            }

            // SEP-2106 (2026-07-28): when an outputSchema is declared, the
            // callback's return value IS the structured output and may be
            // any JSON value — object, array, string, number, boolean, or
            // null. The serialized JSON always rides along as a TextContent
            // block (spec back-compat SHOULD), and the server session strips
            // non-object structuredContent for legacy clients.
            if ($outputSchema !== null) {
                $jsonValue = is_object($result) ? (array) $result : $result;
                if ($jsonValue !== null && !is_array($jsonValue) && !is_scalar($jsonValue)) {
                    throw McpServerException::invalidToolResult($result);
                }
                $callResult = new CallToolResult(
                    content: [new TextContent(text: json_encode($jsonValue, JSON_UNESCAPED_SLASHES))],
                    structuredContent: $jsonValue
                );
                if ($jsonValue === null) {
                    $callResult->setStructuredContentNull();
                }
                return $callResult;
            }

            if (is_string($result)) {
                return new CallToolResult(
                    content: [new TextContent(text: $result)]
                );
            }

            throw McpServerException::invalidToolResult($result);
        };

        return $this;
    }

    /**
     * SEP-2243 Mcp-Param-* validation for the modern (2026-07-28) HTTP
     * path. For every top-level inputSchema property carrying a valid
     * x-mcp-header annotation whose argument is present (non-null) in the
     * body, the request must carry a matching Mcp-Param-{name} header:
     * missing, undecodable (broken base64 sentinel), or value-mismatched
     * headers raise HeaderMismatch (-32020), which the session maps to
     * HTTP 400. Null/absent arguments require the header to be omitted —
     * the server never expects one for them.
     *
     * No-op when the session has no transport header map (stdio, where
     * headers do not exist, and legacy HTTP requests).
     *
     * @param array<string, mixed> $arguments Decoded tool arguments
     */
    private function validateMcpParamHeaders(Tool $tool, array $arguments): void
    {
        $session = $this->server->getSession();
        if (!$session instanceof ServerSession) {
            return;
        }
        $headers = $session->getTransportHttpHeaders();
        if ($headers === null) {
            return;
        }

        $schema = json_decode(json_encode($tool->inputSchema), true);
        if (!is_array($schema)) {
            return;
        }
        $annotations = McpHeaders::collectAnnotations($schema);
        if ($annotations['map'] === []) {
            return;
        }

        foreach ($annotations['map'] as $path => $info) {
            $headerName = McpHeaders::paramHeaderName($info['annotation']);
            $headerValue = $headers[strtolower($headerName)] ?? null;

            [$found, $value] = McpHeaders::argumentAtPath($arguments, $info['segments']);
            if (!$found || $value === null) {
                // Spec: a null/absent designated parameter means the client
                // MUST omit the header and the server MUST NOT expect it.
                continue;
            }

            if (is_float($value) && !is_finite($value)) {
                $this->raiseHeaderMismatch(
                    "Header mismatch: designated parameter '$path' is not a finite number"
                );
            }
            if ((is_int($value) || is_float($value))
                && ($info['type'] === 'integer' || is_int($value))
                && !McpHeaders::isSafeIntegerValue($value)
            ) {
                // SEP-2243: designated integer values MUST be within
                // ±(2^53 - 1) — and large JSON integers can decode as
                // floats, so integral floats are held to the same bound.
                $this->raiseHeaderMismatch(
                    "Header mismatch: designated parameter '$path' exceeds the JavaScript-safe integer range"
                );
            }

            if ($headerValue === null) {
                $this->raiseHeaderMismatch(
                    "Header mismatch: missing required $headerName header for parameter '$path'"
                );
            }

            $decoded = McpHeaders::decodeParamValue(McpHeaders::trimOws($headerValue));
            if ($decoded === null) {
                $this->raiseHeaderMismatch(
                    "Header mismatch: $headerName header value could not be base64-decoded"
                );
            }

            if (!McpHeaders::paramValueMatches($decoded, $value, $info['type'])) {
                $this->raiseHeaderMismatch(
                    "Header mismatch: $headerName header value '$decoded' does not match the body value for '$path'"
                );
            }
        }
    }

    /**
     * @throws McpError Always — the -32020 HeaderMismatch protocol error.
     */
    private function raiseHeaderMismatch(string $message): never
    {
        throw new McpError(new ErrorData(
            code: McpError::HEADER_MISMATCH,
            message: $message
        ));
    }

    /**
     * [Added] Override the SEP-2322 requestState signer (secret + TTL).
     * Defaults to a per-installation file-backed secret so multi-process
     * deployments verify each other's state.
     *
     * @return self For method chaining
     */
    public function inputStateCodec(RequestStateCodec $codec): self
    {
        $this->stateCodec = $codec;
        return $this;
    }

    protected function getStateCodec(): RequestStateCodec
    {
        return $this->stateCodec ??= RequestStateCodec::withFileSecret();
    }

    /**
     * Build the SEP-2322 exchange for a modern tools/call or prompts/get
     * dispatch: verify the echoed requestState (integrity + expiry +
     * method/name binding — it is attacker-controlled input) and merge the
     * results it carries with this round's fresh inputResponses. Null on
     * legacy revisions, where the mechanism does not exist.
     *
     * @param mixed $params The typed request params
     * @throws McpError -32602 when requestState fails verification
     */
    protected function buildInputExchange(mixed $params, string $method, string $name): ?InputExchange
    {
        if (!$this->server->clientSupportsFeature('stateless_lifecycle')) {
            return null;
        }

        $state = is_object($params) && isset($params->requestState) && is_string($params->requestState)
            ? $params->requestState
            : null;
        $carried = [];
        if ($state !== null && $state !== '') {
            $payload = $this->getStateCodec()->decode($state);
            if ($payload === null
                || ($payload['m'] ?? null) !== $method
                || ($payload['n'] ?? null) !== $name
                // SEP-2322: requestState is bound to the authenticated
                // principal it was issued for — another user replaying a
                // captured state fails verification exactly like
                // tampering (no detail leaked about which check failed).
                || ($payload['p'] ?? null) !== $this->currentPrincipal()
            ) {
                throw new McpError(new ErrorData(
                    code: -32602,
                    message: 'requestState integrity check failed'
                ));
            }
            if (is_array($payload['res'] ?? null)) {
                $carried = $payload['res'];
            }
        }

        $fresh = [];
        if (is_object($params) && isset($params->inputResponses)) {
            $responses = $params->inputResponses;
            if (is_object($responses)) {
                $responses = json_decode((string) json_encode($responses), true);
            }
            if (is_array($responses)) {
                // Spec: ignore (don't fail on) unexpected/malformed entries;
                // a non-array inputResponses value counts as absent and the
                // round is simply re-requested.
                $fresh = $responses;
            }
        }

        return new InputExchange(array_merge($carried, $fresh));
    }

    /**
     * Convert an InputRequiredSuspendException into the wire
     * InputRequiredResult: the queued input requests plus a signed
     * requestState carrying every already-resolved result into the next
     * round (the handler re-executes from scratch on the retry).
     */
    protected function buildInputRequiredResult(InputRequiredSuspendException $e, string $method, string $name): InputRequiredResult
    {
        $state = $this->getStateCodec()->encode([
            'm' => $method,
            'n' => $name,
            'p' => $this->currentPrincipal(),
            'res' => $e->carryResults,
        ]);
        return new InputRequiredResult(
            inputRequests: $e->inputRequests,
            requestState: $state,
        );
    }

    /**
     * The authenticated principal of the request being dispatched (token
     * `sub` claim forwarded by the HTTP runner), or null when the request
     * is anonymous / on stdio.
     */
    private function currentPrincipal(): ?string
    {
        $session = $this->server->getSession();
        return $session instanceof ServerSession
            ? $session->getAuthenticatedPrincipal()
            : null;
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
        $needsElicitation = $this->callbackNeedsElicitation($callback);
        $needsInput = $this->callbackNeedsInputContext($callback);
        $this->promptHandlers[$name] = function ($args) use ($name, $callback, $needsElicitation, $needsInput) {
            $arguments = json_decode(json_encode($args), true) ?? [];

            // SEP-2322: prompts/get callbacks may gather client input on
            // the modern path (the contexts suspend into an
            // InputRequiredResult). The legacy suspend/resume pattern is
            // tools-only, so on legacy revisions an elicitation-needing
            // prompt degrades the same way an unsupported client does.
            $elicitContext = null;
            $inputContext = null;
            $session = $this->server->getSession();
            if ($needsElicitation && $session instanceof ServerSession) {
                // httpMode stays false here on purpose: the legacy HTTP
                // suspend/resume machinery is tools-only (its pending
                // records re-invoke tools/call on resume), so a legacy
                // HTTP prompt that elicits must fail loudly (the session's
                // BadMethodCallException) rather than suspend into state
                // it can never resume from. Modern requests use the
                // SEP-2322 exchange; legacy stdio blocks synchronously.
                $elicitContext = new ElicitationContext(
                    session: $session,
                    httpMode: false,
                    toolName: $name,
                    toolArguments: $arguments,
                    exchange: $this->currentExchange,
                );
            }
            if ($needsInput && $session instanceof ServerSession) {
                $inputContext = new InputContext($session, $this->currentExchange);
            }

            $ordered = $this->matchNamedParameters($callback, $arguments, $elicitContext, null, null, $inputContext);

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
            return $this->normalizeReadResourceResult($callback(), $uri, $mimeType);
        };

        return $this;
    }

    /**
     * Define a new resource template (RFC 6570 Level 1 + reserved {+var}).
     *
     * A resource template lets a single callback serve a family of URIs that
     * share a structure, e.g. `db://{table}/{id}`. Registering a template makes
     * it appear in `resources/templates/list`, and a `resources/read` for any
     * URI that matches the template invokes the callback with the extracted
     * variables bound to its parameters **by name** (`fn(string $id) => ...`).
     *
     * Supported template syntax (see {@see UriTemplate}):
     *  - `{var}` matches a SINGLE path segment (one or more non-`/` characters).
     *  - `{+var}` (reserved) matches greedily, INCLUDING `/`, for filesystem-like
     *    templates. The spec's `file:///{path}` example only reads a real
     *    multi-segment path when written as `file:///{+path}`.
     *  - Any other RFC 6570 operator or modifier (`{?q}`, `{#f}`, `{var:3}`,
     *    `{var*}`, `{a,b}`, …) is rejected with an `InvalidArgumentException` at
     *    registration, so a template the read path cannot match is never
     *    advertised.
     *
     * Precedence: an exact-URI {@see resource()} always wins over a template,
     * and templates are tried in registration order (first registered wins on
     * an overlap).
     *
     * The callback follows the same return-value contract as {@see resource()}:
     * a string (auto-wrapped in `ReadResourceResult` with the concrete request
     * URI), an `SplFileObject`/resource (base64 blob), or a `ReadResourceResult`
     * (passed through).
     *
     * @param string $uriTemplate The URI template (RFC 6570 subset)
     * @param string $name The template name
     * @param callable $callback Receives the extracted variables by name
     * @param string $description The template description
     * @param string $mimeType The MIME type
     * @param string|null $title Display title for the template
     * @param array<int, array<string, mixed>>|null $icons Icons for the template
     * @return self For method chaining
     * @throws \InvalidArgumentException If the template uses unsupported syntax
     * @throws McpServerException If the callback returns an invalid result
     */
    public function resourceTemplate(
        string $uriTemplate,
        string $name,
        callable $callback,
        string $description = '',
        string $mimeType = 'text/plain',
        ?string $title = null,
        ?array $icons = null,
    ): self {

        // Compile first: an unsupported RFC 6570 operator throws here, before
        // any descriptor is stored or advertised.
        $matcher = new UriTemplate($uriTemplate);

        $this->resourceTemplates[] = new ResourceTemplate(
            name: $name,
            uriTemplate: $uriTemplate,
            description: $description !== '' ? $description : null,
            mimeType: $mimeType,
            title: $title,
            icons: \Mcp\Types\Icon::parseArray($icons),
        );

        $handler = function (array $vars, string $uri) use ($callback, $mimeType): ReadResourceResult {
            // Map the extracted variables onto the callback's parameters by name
            // and stamp the contents with the concrete request URI (not template).
            $ordered = $this->matchNamedParameters($callback, $vars);
            return $this->normalizeReadResourceResult($callback(...$ordered), $uri, $mimeType);
        };

        $this->resourceTemplateHandlers[] = [
            'matcher' => $matcher,
            'handler' => $handler,
            'mimeType' => $mimeType,
        ];

        return $this;
    }

    /**
     * Normalize a resource callback's return value into a ReadResourceResult.
     *
     * Shared by {@see resource()} and {@see resourceTemplate()} so the two
     * paths never diverge. Accepts a string (TextResourceContents), an
     * SplFileObject/resource (base64 BlobResourceContents), or a
     * ReadResourceResult (passthrough).
     *
     * @param mixed $result The raw callback return value
     * @param string $uri The concrete resource URI to stamp on the contents
     * @param string $mimeType The MIME type to stamp on the contents
     * @throws McpServerException If the result type is not supported
     */
    private function normalizeReadResourceResult(mixed $result, string $uri, string $mimeType): ReadResourceResult
    {
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
    }

    /**
     * Register a completion provider for a prompt argument.
     *
     * The provider supplies autocomplete suggestions as the user types a value
     * for the named argument of the named prompt. Registering any completion
     * provider causes the server to advertise the `completions` capability.
     *
     * The provider is called as `$provider(string $value, array $context = [])`:
     *  - `$value` is the partial argument value typed so far.
     *  - `$context` is the map of already-resolved argument values the client
     *    sent (empty when none) — useful to filter on a prior selection. A
     *    provider that doesn't need context simply omits the second parameter.
     *
     * It may return a `string[]` (auto-wrapped, truncated to 100 with
     * `hasMore`/`total` if longer), a {@see CompletionObject}, or a
     * {@see CompleteResult} (both passed through after validation).
     *
     * @param string $promptName The prompt the argument belongs to
     * @param string $argumentName The argument to complete
     * @param callable $provider The suggestion provider
     * @return self For method chaining
     */
    public function completionForPrompt(
        string $promptName,
        string $argumentName,
        callable $provider
    ): self {
        $this->promptCompletionProviders[$this->completionKey($promptName, $argumentName)] = $provider;
        $this->ensureCompletionHandler();
        return $this;
    }

    /**
     * Register a completion provider for a resource-template argument.
     *
     * Identical contract to {@see completionForPrompt()}, but keyed on a
     * registered `uriTemplate` and one of its variables. The `$uriTemplate`
     * must match the string passed to {@see resourceTemplate()}; a completion
     * request naming a template that was never registered yields a -32602
     * error rather than an empty result.
     *
     * @param string $uriTemplate The registered template string
     * @param string $argumentName The template variable to complete
     * @param callable $provider The suggestion provider
     * @return self For method chaining
     */
    public function completionForResourceTemplate(
        string $uriTemplate,
        string $argumentName,
        callable $provider
    ): self {
        $this->resourceTemplateCompletionProviders[$this->completionKey($uriTemplate, $argumentName)] = $provider;
        $this->ensureCompletionHandler();
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
     * [Added] Set the subscription bus backing `subscriptions/listen`
     * (SEP-2575, revision 2026-07-28).
     *
     * The bus carries change events between the request that causes a
     * change and the request holding a listen stream open — on typical
     * PHP hosting those are different processes, so use
     * {@see \Mcp\Server\Subscriptions\FileSubscriptionBus} there. The
     * publish helpers below write to this bus and, on stdio, also deliver
     * to in-session subscriptions.
     *
     * @return self For method chaining
     */
    public function subscriptionBus(\Mcp\Server\Subscriptions\SubscriptionBusInterface $bus): self
    {
        $this->subscriptionBus = $bus;
        $this->httpOptions['subscription_bus'] = $bus;
        // Note: this deliberately does NOT register legacy
        // resources/subscribe handlers or flip the legacy subscribe
        // capability — McpServer has no legacy update-delivery channel,
        // and advertising one would let pre-2026 clients subscribe into
        // silence. The modern resourceSubscriptions filter is honored
        // independently: subscriptions/listen gates it on actual
        // deliverability (this bus on HTTP, the in-session channel on
        // stdio), and the acknowledgement frame is the spec's signal of
        // what the server agreed to honor.
        return $this;
    }

    /**
     * [Added] Announce that the tool list changed: notifies active
     * subscriptions/listen channels (via the configured bus and, on
     * stdio, in-session subscriptions).
     */
    public function publishToolsListChanged(): self
    {
        return $this->publishSubscriptionEvent('notifications/tools/list_changed');
    }

    /** [Added] Announce that the prompt list changed. */
    public function publishPromptsListChanged(): self
    {
        return $this->publishSubscriptionEvent('notifications/prompts/list_changed');
    }

    /** [Added] Announce that the resource list changed. */
    public function publishResourcesListChanged(): self
    {
        return $this->publishSubscriptionEvent('notifications/resources/list_changed');
    }

    /** [Added] Announce that a specific resource's contents changed. */
    public function publishResourceUpdated(string $uri): self
    {
        return $this->publishSubscriptionEvent('notifications/resources/updated', ['uri' => $uri]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function publishSubscriptionEvent(string $method, array $params = []): self
    {
        $this->subscriptionBus?->publish($method, $params);

        $session = $this->server->getSession();
        if ($session instanceof ServerSession && !($session instanceof HttpServerSession)) {
            // stdio: subscriptions live in-session; forward directly.
            $session->deliverSubscriptionNotification($method, $params);
        }
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

    /**
     * Send a notifications/elicitation/complete notification to the client.
     *
     * Call this when your server learns (through its own endpoint, e.g., an
     * OAuth callback) that an out-of-band URL-mode elicitation has completed.
     * The client can then prompt the user to retry the original request.
     *
     * This is typically called from your OAuth callback handler or similar
     * endpoint, outside of a tool handler context.
     *
     * @param string $elicitationId The ID of the completed elicitation
     */
    public function notifyElicitationComplete(string $elicitationId): void
    {
        $session = $this->server->getSession();
        if ($session !== null) {
            $session->sendElicitationCompleteNotification($elicitationId);
        } else {
            $this->logger->warning(
                "Cannot send elicitation complete notification: no active session (elicitationId: {$elicitationId})"
            );
        }
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
        // Auto-enable DNS rebinding protection when running under PHP's built-in
        // development server (cli-server SAPI) and the user has not explicitly
        // configured allowed_origins. This matches the pattern used by the
        // TypeScript SDK's createMcpExpressApp() and the Python SDK's FastMCP,
        // which auto-protect localhost-bound servers (CVE-2025-66414 / CVE-2025-66416).
        //
        // For production SAPIs (apache2handler, fpm-fcgi, etc.), protection is
        // opt-in via httpOptions(['allowed_origins' => ['yourdomain.com']]) since
        // the PHP host config does not reflect the actual bind address.
        if (!array_key_exists('allowed_origins', $this->httpOptions) && PHP_SAPI === 'cli-server') {
            $this->httpOptions['allowed_origins'] = ['localhost', '127.0.0.1', '::1'];
        }

        $notificationOptions = new NotificationOptions(
            promptsChanged: $this->promptsChanged,
            resourcesChanged: $this->resourcesChanged,
            toolsChanged: $this->toolsChanged
        );

        $initOptions = $this->server->createInitializationOptions($notificationOptions);

        $sessionStore = $this->sessionStore
            ?? new FileSessionStore(sys_get_temp_dir() . '/mcp_sessions');

        // Allow embedders to inject a custom HttpIoInterface via the
        // httpOptions key 'io'. The option is stripped before the
        // remaining options reach the transport's Config so it is not
        // surfaced as user-facing configuration. Defaults to NativePhpIo
        // inside HttpServerRunner when omitted — the cPanel/Apache path.
        $io = null;
        $runnerOptions = $this->httpOptions;
        if (array_key_exists('io', $runnerOptions)) {
            $candidate = $runnerOptions['io'];
            unset($runnerOptions['io']);
            if (!$candidate instanceof HttpIoInterface) {
                throw new \InvalidArgumentException(
                    "httpOptions['io'] must implement " . HttpIoInterface::class
                );
            }
            $io = $candidate;
        }

        $runner = new HttpServerRunner(
            $this->server,
            $initOptions,
            $runnerOptions,
            $this->logger,
            $sessionStore,
            $io
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
            $meta = $params->_meta ?? null;

            // Forward elicitation/sampling results for HTTP resume path
            if (is_object($params) && isset($params->_elicitationResults)) {
                $arguments->_elicitationResults = $params->_elicitationResults;
            }
            if (is_object($params) && isset($params->_samplingResults)) {
                $arguments->_samplingResults = $params->_samplingResults;
            }

            if (!isset($this->toolHandlers[$name])) {
                throw McpServerException::unknownTool($name);
            }

            $handler = $this->toolHandlers[$name];

            // SEP-2322 (2026-07-28): build the multi-round-trip exchange
            // from the verified requestState plus this round's
            // inputResponses. Tampered/expired state is rejected here.
            $this->currentExchange = $this->buildInputExchange($params, 'tools/call', (string) $name);

            try {
                $result = $handler($arguments, $meta);
            } catch (InputRequiredSuspendException $e) {
                // The handler needs client-side input: answer with the
                // SEP-2322 InputRequiredResult instead of a normal result.
                return $this->buildInputRequiredResult($e, 'tools/call', (string) $name);
            } catch (ClientRequestSuspendException $e) {
                throw $e; // Must propagate to HttpServerSession for suspend/resume
            } catch (\Mcp\Shared\McpError $e) {
                // Protocol-level errors must surface as JSON-RPC errors,
                // never as isError tool results: McpServerException
                // (programming errors, -32042 URL elicitation) and the
                // SDK-raised SEP-2575 errors alike — e.g. the -32021
                // MissingRequiredClientCapabilityError thrown by the
                // sampling/elicitation capability guards on the modern
                // path, which the spec requires on the wire with HTTP 400.
                // Only tool EXECUTION failures below become isError
                // results.
                throw $e;
            } catch (\Throwable $e) {
                return new CallToolResult(
                    content: [new TextContent(text: 'Error: ' . $e->getMessage())],
                    isError: true
                );
            } finally {
                $this->currentExchange = null;
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

            // SEP-2322: prompts/get is one of the three methods that may
            // answer InputRequiredResult on the modern path.
            $this->currentExchange = $this->buildInputExchange($params, 'prompts/get', (string) $name);
            try {
                return $handler($arguments);
            } catch (InputRequiredSuspendException $e) {
                return $this->buildInputRequiredResult($e, 'prompts/get', (string) $name);
            } finally {
                $this->currentExchange = null;
            }
        });

        $this->server->registerHandler('resources/list', function () {
            return new ListResourcesResult(array_values($this->resources));
        });

        // Registered unconditionally (mirrors resources/list): a server with no
        // templates answers with an empty list rather than "method not found",
        // which is friendlier to clients that probe this method on connect.
        $this->server->registerHandler('resources/templates/list', function () {
            return new ListResourceTemplatesResult(array_values($this->resourceTemplates));
        });

        $this->server->registerHandler('resources/read', function ($params) {
            $uri = $params->uri;

            // Exact-match static resources win over templates (unchanged fast path).
            if (isset($this->resourceHandlers[$uri])) {
                $handler = $this->resourceHandlers[$uri];
                return $handler();
            }

            // Fall through to templates, tried in registration order.
            foreach ($this->resourceTemplateHandlers as $entry) {
                $vars = $entry['matcher']->extract($uri);
                if ($vars !== null) {
                    return ($entry['handler'])($vars, $uri);
                }
            }

            // SEP-2164: -32602 under 2026-07-28, -32002 on legacy revisions.
            // Never an empty contents array — a missing resource is always
            // an error.
            $modernErrorCode = $this->server->clientSupportsFeature('resource_not_found_invalid_params');
            throw McpServerException::unknownResource($uri, $modernErrorCode);
        });
    }

    /**
     * Build the composite key for a completion provider.
     *
     * Uses a NUL separator so a name containing the separator cannot collide
     * with a different (name, argument) pair.
     */
    private function completionKey(string $refName, string $argumentName): string
    {
        return $refName . "\0" . $argumentName;
    }

    /**
     * Whether any registered resource template uses the given URI template.
     */
    private function hasResourceTemplate(string $uriTemplate): bool
    {
        foreach ($this->resourceTemplates as $template) {
            if ($template->uriTemplate === $uriTemplate) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lazily register the completion/complete handler on first provider use.
     *
     * Keeping registration lazy means Server::getCapabilities() only advertises
     * the `completions` capability for servers that actually register a
     * provider.
     */
    private function ensureCompletionHandler(): void
    {
        if ($this->completionHandlerRegistered) {
            return;
        }
        $this->completionHandlerRegistered = true;

        $this->server->registerHandler('completion/complete', function ($params) {
            $ref = is_object($params) ? ($params->ref ?? null) : null;
            $argument = is_object($params) ? ($params->argument ?? null) : null;
            $argName = is_object($argument) ? ($argument->name ?? '') : '';
            $argValue = is_object($argument) ? ($argument->value ?? '') : '';

            // Already-resolved arguments for multi-argument completion.
            $context = [];
            $ctx = is_object($params) ? ($params->context ?? null) : null;
            if ($ctx instanceof CompletionContext) {
                $context = $ctx->arguments;
            }

            // An invalid *reference* is a -32602 error, not an empty result.
            if ($ref instanceof PromptReference) {
                if (!isset($this->promptHandlers[$ref->name])) {
                    throw McpServerException::unknownPrompt($ref->name);
                }
                $provider = $this->promptCompletionProviders[$this->completionKey($ref->name, $argName)] ?? null;
            } elseif ($ref instanceof ResourceReference) {
                // ResourceReference->uri carries the registered template string.
                if (!$this->hasResourceTemplate($ref->uri)) {
                    throw McpServerException::unknownResourceTemplate($ref->uri);
                }
                $provider = $this->resourceTemplateCompletionProviders[$this->completionKey($ref->uri, $argName)] ?? null;
            } else {
                throw McpServerException::invalidCompletionRef();
            }

            // Valid ref but no provider for this specific argument: no suggestions.
            if ($provider === null) {
                return new CompleteResult(completion: new CompletionObject(values: []));
            }

            return $this->normalizeCompletionResult($provider($argValue, $context));
        });
    }

    /**
     * Normalize a completion provider's return value into a CompleteResult.
     *
     * Enforces the spec's 100-value cap on the SEND side (BaseSession does not
     * validate outgoing results):
     *  - A `string[]` longer than 100 is truncated to the first 100, with
     *    `hasMore: true` and `total` set to the full count; truncation is
     *    logged so it is not silent.
     *  - A hand-built CompletionObject/CompleteResult is validated (which throws
     *    above 100), so an author-built oversized response fails loudly at the
     *    source rather than emitting a spec-violating payload.
     *
     * @param mixed $result The raw provider return value
     * @throws McpServerException If the result type is unsupported
     */
    private function normalizeCompletionResult(mixed $result): CompleteResult
    {
        if ($result instanceof CompleteResult) {
            $result->completion->validate();
            return $result;
        }

        if ($result instanceof CompletionObject) {
            $result->validate();
            return new CompleteResult(completion: $result);
        }

        if (is_array($result)) {
            $values = array_values(array_map(static fn ($v): string => (string)$v, $result));
            $total = count($values);

            if ($total > 100) {
                $this->logger->debug(sprintf(
                    'Completion provider returned %d values; truncating to 100 and setting hasMore=true.',
                    $total
                ));
                return new CompleteResult(completion: new CompletionObject(
                    values: array_slice($values, 0, 100),
                    total: $total,
                    hasMore: true,
                ));
            }

            return new CompleteResult(completion: new CompletionObject(values: $values));
        }

        throw McpServerException::invalidCompletionResult($result);
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

            // Skip injected context parameters — they are not user input
            if ($typeName === ElicitationContext::class
                || $typeName === ProgressContext::class
                || $typeName === SamplingContext::class
            ) {
                continue;
            }

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
     * correct ordering regardless of JSON key order. ElicitationContext,
     * SamplingContext, and ProgressContext parameters are injected automatically
     * when provided.
     *
     * @param callable $callback The target callback
     * @param array<string, mixed> $arguments Associative array of arguments
     * @param ElicitationContext|null $elicitContext Optional context to inject
     * @param ProgressContext|null $progressContext Optional context to inject
     * @param SamplingContext|null $samplingContext Optional context to inject
     * @return array<int, mixed> Ordered arguments matching the callback's parameter list
     */
    protected function matchNamedParameters(callable $callback, array $arguments, ?ElicitationContext $elicitContext = null, ?ProgressContext $progressContext = null, ?SamplingContext $samplingContext = null, ?InputContext $inputContext = null): array
    {
        $reflection = new ReflectionFunction(\Closure::fromCallable($callback));
        $parameters = $reflection->getParameters();
        $ordered = [];

        foreach ($parameters as $param) {
            $name = $param->getName();
            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';

            // Inject ElicitationContext
            if ($typeName === ElicitationContext::class) {
                $ordered[] = $elicitContext;
                continue;
            }

            // Inject SamplingContext
            if ($typeName === SamplingContext::class) {
                $ordered[] = $samplingContext;
                continue;
            }

            // Inject InputContext (SEP-2322 batch input gathering)
            if ($typeName === InputContext::class) {
                $ordered[] = $inputContext;
                continue;
            }

            // Inject ProgressContext
            if ($typeName === ProgressContext::class) {
                if ($progressContext === null && !$param->allowsNull() && !$param->isOptional()) {
                    throw new \InvalidArgumentException(
                        "Tool callback declares non-nullable ProgressContext parameter '{$name}'. "
                        . "Use ?ProgressContext \${$name} = null so the tool can execute when no progressToken is provided."
                    );
                }
                $ordered[] = $progressContext;
                continue;
            }

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

    /**
     * Check if a callback has an ElicitationContext parameter.
     */
    protected function callbackNeedsElicitation(callable $callback): bool
    {
        $reflection = new ReflectionFunction(\Closure::fromCallable($callback));
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === ElicitationContext::class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a callback has an InputContext parameter (SEP-2322 batch
     * input gathering).
     */
    protected function callbackNeedsInputContext(callable $callback): bool
    {
        $reflection = new ReflectionFunction(\Closure::fromCallable($callback));
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === InputContext::class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a callback has a SamplingContext parameter.
     */
    protected function callbackNeedsSampling(callable $callback): bool
    {
        $reflection = new ReflectionFunction(\Closure::fromCallable($callback));
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === SamplingContext::class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a callback has a ProgressContext parameter.
     */
    protected function callbackNeedsProgress(callable $callback): bool
    {
        $reflection = new ReflectionFunction(\Closure::fromCallable($callback));
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === ProgressContext::class) {
                return true;
            }
        }
        return false;
    }
}
