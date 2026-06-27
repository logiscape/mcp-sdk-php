<?php

/**
 * MCP Conformance Test Server
 * 
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * Implements all tools, prompts, resources, and capabilities expected by the
 * MCP conformance test suite (@modelcontextprotocol/conformance).
 *
 * Design principles:
 *   - Use McpServer's public API wherever possible.
 *   - Use low-level Server::registerHandler() ONLY for features McpServer
 *     doesn't expose (logging/setLevel, resource subscriptions).
 *   - NEVER override tools/call or tools/list to work around SDK limitations.
 *     If the SDK drops _meta, doesn't support sampling in HTTP mode, etc.,
 *     let those tests FAIL — that surfaces real issues.
 *   - Throw exceptions for error handling, never hand-craft error CallToolResults.
 *
 * Usage:
 *   Stdio:  php conformance/everything-server.php
 *   HTTP:   php -S localhost:3000 conformance/everything-server.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Mcp\Types\ImageContent;
use Mcp\Types\AudioContent;
use Mcp\Types\EmbeddedResource;
use Mcp\Types\TextResourceContents;
use Mcp\Types\BlobResourceContents;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\PromptMessage;
use Mcp\Types\GetPromptResult;
use Mcp\Types\Role;
use Mcp\Types\LoggingLevel;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\SamplingMessage;
use Mcp\Types\EmptyResult;
use Mcp\Server\Elicitation\ElicitationContext;
use Mcp\Server\InputRequired\InputContext;
use Mcp\Server\Sampling\SamplingContext;
use Mcp\Server\Subscriptions\FileSubscriptionBus;

// Minimal 1x1 transparent PNG (base64)
const TEST_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

// Minimal WAV header + 1 sample of silence (base64)
const TEST_WAV_BASE64 = 'UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQIAAACA';

$server = new McpServer('conformance-test-server');

// Enable the resumable SSE substrate for HTTP mode so the conformance suite
// exercises spec-aligned streaming of progress notifications and server-to-
// client requests. Gracefully disabled at runtime when Environment checks
// fail, so shared-hosting deployments that mirror this config stay safe.
// The listen_max_ms cap keeps a subscriptions/listen stream from holding a
// CLI-server worker hostage long after the conformance tool aborted it.
$server->httpOptions(['enable_sse' => true, 'listen_max_ms' => 5000]);

// Advertise listChanged on tools/prompts/resources: the draft suite's
// subscriptions/listen SHOULD checks only engage when server/discover
// declares these capabilities.
$server->notifyOnChanges(resourcesChanged: true, toolsChanged: true, promptsChanged: true);

// Cross-process event channel for subscriptions/listen: the trigger tool
// runs in a different CLI-server worker than the open listen stream, so
// the change events travel through a file-backed bus (the shared-hosting
// pattern the SDK ships for exactly this).
$server->subscriptionBus(new FileSubscriptionBus(
    sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-conformance-subscription-bus'
));

// ---------------------------------------------------------------------------
// Tools — all registered through McpServer's public API
// ---------------------------------------------------------------------------

$server->tool('test_simple_text', 'Returns a simple text response', function (): string {
    return 'Hello from the conformance test server!';
});

// JSON Schema 2020-12 tool — exercises $schema, $defs, $ref, additionalProperties
$server->tool(
    name: 'json_schema_2020_12_tool',
    description: 'Tool with JSON Schema 2020-12 keywords for conformance testing',
    callback: function (string $name): string {
        return "Received: name=$name";
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
                    'city' => ['type' => 'string'],
                ],
                'required' => ['street', 'city'],
                'additionalProperties' => false,
            ],
        ],
    ],
);

$server->tool('test_image_content', 'Returns an image content block', function (): CallToolResult {
    return new CallToolResult(
        content: [new ImageContent(data: TEST_PNG_BASE64, mimeType: 'image/png')]
    );
});

$server->tool('test_audio_content', 'Returns an audio content block', function (): CallToolResult {
    return new CallToolResult(
        content: [new AudioContent(data: TEST_WAV_BASE64, mimeType: 'audio/wav')]
    );
});

$server->tool('test_embedded_resource', 'Returns an embedded resource content block', function (): CallToolResult {
    return new CallToolResult(
        content: [
            new EmbeddedResource(
                resource: new TextResourceContents(
                    text: 'Embedded resource content',
                    uri: 'test://embedded',
                    mimeType: 'text/plain'
                )
            ),
        ]
    );
});

$server->tool('test_multiple_content_types', 'Returns multiple content types', function (): CallToolResult {
    return new CallToolResult(
        content: [
            new TextContent(text: 'Text part of the response'),
            new ImageContent(data: TEST_PNG_BASE64, mimeType: 'image/png'),
            new EmbeddedResource(
                resource: new TextResourceContents(
                    text: 'Resource part of the response',
                    uri: 'test://mixed-resource',
                    mimeType: 'text/plain'
                )
            ),
        ]
    );
});

// Logging tool — accesses session via escape hatch (public API: getServer()->getSession()).
// This is the SDK's intended pattern for server-to-client notifications.
$server->tool('test_tool_with_logging', 'Emits log notifications then returns', function () use ($server): CallToolResult {
    $session = $server->getServer()->getSession();
    if ($session) {
        $session->sendLogMessage(LoggingLevel::INFO, 'Log message 1', 'test-logger');
        $session->sendLogMessage(LoggingLevel::INFO, 'Log message 2', 'test-logger');
        $session->sendLogMessage(LoggingLevel::INFO, 'Log message 3', 'test-logger');
    }
    return new CallToolResult(
        content: [new TextContent(text: 'Logging complete')]
    );
});

// Error handling — throws an exception, letting the SDK convert it to a
// protocol error response. This exercises the SDK's error-to-protocol mapping.
$server->tool('test_error_handling', 'Throws an error for testing', function (): never {
    throw new \RuntimeException('Test error from conformance server');
});

// Progress tool — McpServer injects ProgressContext when the callback declares
// it, just like ElicitationContext. The conformance test sends _meta.progressToken
// and expects progress notifications: 0/100, 50/100, 100/100.
// We use ProgressContext for the injection but send notifications via session
// for full control over progress/total values.
$server->tool('test_tool_with_progress', 'Sends progress notifications', function (?\Mcp\Shared\ProgressContext $progress = null) use ($server): CallToolResult {
    $session = $server->getServer()->getSession();
    $token = $progress?->getToken();
    if ($session !== null && $token !== null) {
        $session->sendProgressNotification($token, 0, 100);
        usleep(50000);
        $session->sendProgressNotification($token, 50, 100);
        usleep(50000);
        $session->sendProgressNotification($token, 100, 100);
    }
    return new CallToolResult(
        content: [new TextContent(text: 'Progress complete')]
    );
});

// Sampling tool — uses the SamplingContext public API so the same handler
// works across stdio, HTTP buffered, and HTTP SSE transports. In HTTP mode
// the first call throws SamplingSuspendException and the tool resumes when
// the client returns the CreateMessageResult.
$server->tool('test_sampling', 'Requests LLM sampling via sampling/createMessage', function (string $prompt, SamplingContext $sampling): CallToolResult {
    $response = $sampling->prompt($prompt, maxTokens: 100);

    if ($response === null) {
        // Client did not advertise sampling capability (or the negotiated
        // protocol version predates sampling). Return an isError result so
        // the tool call surfaces the unsupported state instead of a stub
        // success.
        return new CallToolResult(
            content: [new TextContent(text: 'Sampling is not supported by this client')],
            isError: true,
        );
    }

    $responseText = 'Sampling response received';
    $content = $response->content;
    if ($content instanceof TextContent) {
        $responseText = "LLM response: {$content->text}";
    } elseif (is_array($content)) {
        foreach ($content as $block) {
            if ($block instanceof TextContent) {
                $responseText = "LLM response: {$block->text}";
                break;
            }
        }
    }

    return new CallToolResult(
        content: [new TextContent(text: $responseText)]
    );
});

// Capability-requirement tool — exercised by the draft suite's SEP-2575
// missing-capability checks: the stateless connector calls this tool with a
// request envelope that deliberately omits the sampling capability and
// expects MissingRequiredClientCapabilityError (-32021 with
// data.requiredCapabilities, HTTP 400). The handler goes through the
// ordinary SamplingContext SDK path — the error is raised inside the SDK
// when the modern request's envelope lacks the capability, not fabricated
// here; legacy clients keep the pre-2026 graceful-fallback contract.
$server->tool('test_missing_capability', 'Requires the sampling client capability', function (SamplingContext $sampling): CallToolResult {
    $response = $sampling->prompt('Capability check', maxTokens: 10);

    if ($response === null) {
        return new CallToolResult(
            content: [new TextContent(text: 'Sampling is not supported by this client')],
            isError: true,
        );
    }

    return new CallToolResult(
        content: [new TextContent(text: 'Sampling capability available')]
    );
});

// Elicitation tool — uses ElicitationContext, which is the SDK's public API
// for requesting user input. If it doesn't work in HTTP mode, the test fails.
$server->tool('test_elicitation', 'Requests elicitation from the client', function (string $message, ElicitationContext $elicit): CallToolResult {
    $result = $elicit->form(
        $message,
        [
            'type' => 'object',
            'properties' => [
                'username' => ['type' => 'string', 'description' => "User's response"],
                'email' => ['type' => 'string', 'description' => "User's email address"],
            ],
            'required' => ['username', 'email'],
        ]
    );

    $action = $result?->action ?? 'no response';
    $content = $result?->content ?? [];
    return new CallToolResult(
        content: [new TextContent(text: "User response: action={$action}, content=" . json_encode($content))]
    );
});

// Elicitation with default values (SEP-1034)
$server->tool('test_elicitation_sep1034_defaults', 'Requests elicitation with default values', function (ElicitationContext $elicit): CallToolResult {
    $result = $elicit->form(
        'Please review and confirm your information',
        [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'title' => 'Name', 'description' => 'Your name', 'default' => 'John Doe'],
                'age' => ['type' => 'integer', 'title' => 'Age', 'description' => 'Your age', 'default' => 30],
                'score' => ['type' => 'number', 'title' => 'Score', 'description' => 'Your score', 'default' => 95.5],
                'status' => ['type' => 'string', 'title' => 'Status', 'enum' => ['active', 'inactive', 'pending'], 'default' => 'active'],
                'verified' => ['type' => 'boolean', 'title' => 'Verified', 'description' => 'Whether verified', 'default' => true],
            ],
            'required' => ['name', 'age', 'score', 'status', 'verified'],
        ]
    );

    $action = $result?->action ?? 'no response';
    $content = $result?->content ?? [];
    return new CallToolResult(
        content: [new TextContent(text: "Elicitation completed: action={$action}, content=" . json_encode($content))]
    );
});

// Elicitation with enum schemas (SEP-1330)
$server->tool('test_elicitation_sep1330_enums', 'Requests elicitation with enum schemas', function (ElicitationContext $elicit): CallToolResult {
    $result = $elicit->form(
        'Please select options',
        [
            'type' => 'object',
            'properties' => [
                'untitledSingle' => [
                    'type' => 'string',
                    'enum' => ['option1', 'option2', 'option3'],
                ],
                'titledSingle' => [
                    'type' => 'string',
                    'oneOf' => [
                        ['const' => 'value1', 'title' => 'First Option'],
                        ['const' => 'value2', 'title' => 'Second Option'],
                        ['const' => 'value3', 'title' => 'Third Option'],
                    ],
                ],
                'legacyEnum' => [
                    'type' => 'string',
                    'enum' => ['opt1', 'opt2', 'opt3'],
                    'enumNames' => ['Option One', 'Option Two', 'Option Three'],
                ],
                'untitledMulti' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['option1', 'option2', 'option3'],
                    ],
                ],
                'titledMulti' => [
                    'type' => 'array',
                    'items' => [
                        'anyOf' => [
                            ['const' => 'value1', 'title' => 'First Choice'],
                            ['const' => 'value2', 'title' => 'Second Choice'],
                            ['const' => 'value3', 'title' => 'Third Choice'],
                        ],
                    ],
                ],
            ],
        ]
    );

    $action = $result?->action ?? 'no response';
    $content = $result?->content ?? [];
    return new CallToolResult(
        content: [new TextContent(text: "Elicitation completed: action={$action}, content=" . json_encode($content))]
    );
});

// ---------------------------------------------------------------------------
// WS3: SEP-2243 designated header parameters (x-mcp-header)
// ---------------------------------------------------------------------------

// The http-custom-header-server-validation scenario activates on the first
// listed tool whose inputSchema carries an x-mcp-header annotation on a
// string property; it then calls the tool with matching / mismatched /
// base64-mangled Mcp-Param-Region headers and expects the SDK to accept or
// reject (400/-32020) accordingly. The handler itself only ever sees valid
// invocations.
$server->tool(
    name: 'test_header_params',
    description: 'Echoes a parameter designated for header mirroring via x-mcp-header',
    callback: fn(string $region): string => "region={$region}",
    inputSchema: [
        'properties' => [
            'region' => [
                'type' => 'string',
                'description' => 'Deployment region (mirrored into Mcp-Param-Region)',
                'x-mcp-header' => 'Region',
            ],
        ],
        'required' => ['region'],
    ],
);

// ---------------------------------------------------------------------------
// WS3: subscriptions/listen diagnostics (SEP-2575)
// ---------------------------------------------------------------------------

$server->tool('test_trigger_tool_change', 'Publishes a tools list_changed event', function () use ($server): string {
    $server->publishToolsListChanged();
    return 'Tool list change triggered';
});

$server->tool('test_trigger_prompt_change', 'Publishes a prompts list_changed event', function () use ($server): string {
    $server->publishPromptsListChanged();
    return 'Prompt list change triggered';
});

// Diagnostic for sep-2575-server-no-independent-requests-on-stream: a tool
// that needs elicitation mid-call. On the modern path the SDK answers with
// an InputRequiredResult (SEP-2322) — no server-initiated request may ever
// appear on the response stream.
$server->tool('test_streaming_elicitation', 'Elicits input mid-call (modern: MRTR, never a stream request)', function (ElicitationContext $elicit): string {
    $result = $elicit->form(
        'Streaming elicitation check',
        ['type' => 'object', 'properties' => ['ack' => ['type' => 'string']]],
        inputKey: 'ack'
    );
    return 'Streaming elicitation complete: ' . ($result?->action ?? 'none');
});

// Diagnostic for sep-2575-server-no-log-without-loglevel: emits
// notifications/message ONLY when the request's _meta carried the
// io.modelcontextprotocol/logLevel key (SEP-2577 per-request opt-in).
$server->tool('test_logging_tool', 'Logs only when the request opts in via _meta logLevel', function () use ($server): string {
    $session = $server->getServer()->getSession();
    if ($session !== null && $session->getCurrentRequestLogLevel() !== null) {
        $session->sendLogMessage(LoggingLevel::INFO, 'Per-request log line', 'test-logger');
    }
    return 'Logging tool complete';
});

// ---------------------------------------------------------------------------
// WS3: SEP-2322 multi-round-trip fixtures (input-required-result-* scenarios)
// ---------------------------------------------------------------------------

$server->tool('test_input_required_result_elicitation', 'Round 1: requests the user name via elicitation; round 2: completes', function (ElicitationContext $elicit): string {
    $result = $elicit->form(
        'What is your name?',
        [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'description' => "The user's name"]],
            'required' => ['name'],
        ],
        inputKey: 'user_name'
    );
    $content = $result?->content;
    $name = is_object($content) ? ($content->name ?? null) : (is_array($content) ? ($content['name'] ?? null) : null);
    return 'Hello, ' . (is_string($name) ? $name : 'unknown') . '!';
});

$server->tool('test_input_required_result_sampling', 'Round 1: requests a sampling completion; round 2: completes', function (SamplingContext $sampling): string {
    $response = $sampling->prompt('What is the capital of France?', maxTokens: 100);
    $text = $response?->content instanceof TextContent ? $response->content->text : 'no response';
    return 'Sampling says: ' . $text;
});

$server->tool('test_input_required_result_list_roots', 'Round 1: requests the client roots; round 2: completes', function (InputContext $input): string {
    $input->wantRoots('workspace_roots');
    $results = $input->collect();
    $roots = $results['workspace_roots']['roots'] ?? [];
    return 'Received ' . count($roots) . ' root(s)';
});

$server->tool('test_input_required_result_request_state', 'Exercises requestState round-tripping', function (ElicitationContext $elicit): string {
    $elicit->form(
        'Confirm to continue',
        ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        inputKey: 'confirmation'
    );
    return 'state-ok';
});

$server->tool('test_input_required_result_multiple_inputs', 'Requests elicitation + sampling + roots in ONE round', function (InputContext $input): string {
    $input->wantForm('user_name', 'What is your name?', [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'required' => ['name'],
    ]);
    $input->wantSample('greeting', [new SamplingMessage(
        role: Role::USER,
        content: new TextContent(text: 'Say hello')
    )], 50);
    $input->wantRoots('workspace');
    $input->collect();
    return 'All three inputs gathered';
});

$server->tool('test_input_required_result_multi_round', 'Two sequential elicitation rounds', function (ElicitationContext $elicit): string {
    $name = $elicit->form(
        'What is your name?',
        ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
        inputKey: 'name_round'
    );
    $color = $elicit->form(
        'What is your favorite color?',
        ['type' => 'object', 'properties' => ['color' => ['type' => 'string']], 'required' => ['color']],
        inputKey: 'color_round'
    );
    return 'Multi-round complete';
});

$server->tool('test_input_required_result_tampered_state', 'Rejects tampered requestState (SDK-signed)', function (ElicitationContext $elicit): string {
    $elicit->form(
        'Tamper check',
        ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        inputKey: 'tamper_check'
    );
    return 'Tamper check complete';
});

// Capability-aware fixture: the scenario calls this with clientCapabilities
// declaring ONLY sampling — the tool must request input WITHOUT including
// any elicitation/create entry (spec: servers MUST NOT request input types
// the client did not declare) and without erroring.
$server->tool('test_input_required_result_capabilities', 'Requests only input types the client declared', function (InputContext $input): string {
    if ($input->supports('elicitation')) {
        $input->wantForm('user_name', 'What is your name?', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);
    }
    if ($input->supports('sampling')) {
        $input->wantSample('llm_check', [new SamplingMessage(
            role: Role::USER,
            content: new TextContent(text: 'Capability check')
        )], 50);
    }
    $input->collect();
    return 'Capability-aware gathering complete';
});

// ---------------------------------------------------------------------------
// Prompts — all registered through McpServer's public API
// ---------------------------------------------------------------------------

// SEP-2322 non-tool fixture: prompts/get may also answer InputRequiredResult.
$server->prompt('test_input_required_result_prompt', 'Prompt that elicits context before rendering', function (ElicitationContext $elicit): GetPromptResult {
    $result = $elicit->form(
        'What context should the prompt use?',
        ['type' => 'object', 'properties' => ['context' => ['type' => 'string']], 'required' => ['context']],
        inputKey: 'user_context'
    );
    $content = $result?->content;
    $context = is_object($content) ? ($content->context ?? null) : (is_array($content) ? ($content['context'] ?? null) : null);
    return new GetPromptResult(
        messages: [
            new PromptMessage(
                role: Role::USER,
                content: new TextContent(text: 'Prompt with context: ' . (is_string($context) ? $context : 'none'))
            ),
        ],
        description: 'Prompt rendered with elicited context'
    );
});

$server->prompt('test_simple_prompt', 'A simple test prompt', function (): GetPromptResult {
    return new GetPromptResult(
        messages: [
            new PromptMessage(
                role: Role::USER,
                content: new TextContent(text: 'This is a simple test prompt message.')
            ),
        ],
        description: 'A simple test prompt'
    );
});

$server->prompt('test_prompt_with_arguments', 'A test prompt with arguments', function (string $arg1, string $arg2): GetPromptResult {
    return new GetPromptResult(
        messages: [
            new PromptMessage(
                role: Role::USER,
                content: new TextContent(text: "Prompt with arg1={$arg1} and arg2={$arg2}")
            ),
        ],
        description: 'A test prompt with arguments'
    );
});

$server->prompt('test_prompt_with_embedded_resource', 'A test prompt with an embedded resource', function (string $resourceUri): GetPromptResult {
    return new GetPromptResult(
        messages: [
            new PromptMessage(
                role: Role::USER,
                content: new EmbeddedResource(
                    resource: new TextResourceContents(
                        text: "Content of resource at {$resourceUri}",
                        uri: $resourceUri,
                        mimeType: 'text/plain'
                    )
                )
            ),
            new PromptMessage(
                role: Role::USER,
                content: new TextContent(text: "Please analyze the resource at {$resourceUri}")
            ),
        ],
        description: 'A test prompt with an embedded resource'
    );
});

$server->prompt('test_prompt_with_image', 'A test prompt with an image', function (): GetPromptResult {
    return new GetPromptResult(
        messages: [
            new PromptMessage(
                role: Role::USER,
                content: new ImageContent(data: TEST_PNG_BASE64, mimeType: 'image/png')
            ),
            new PromptMessage(
                role: Role::USER,
                content: new TextContent(text: 'Please analyze this image.')
            ),
        ],
        description: 'A test prompt with an image'
    );
});

// ---------------------------------------------------------------------------
// Resources — registered through McpServer's public API
// ---------------------------------------------------------------------------

$server->resource(
    uri: 'test://static-text',
    name: 'Static Text Resource',
    callback: fn() => 'This is the static text resource content.',
    description: 'A static text resource for testing',
    mimeType: 'text/plain'
);

$server->resource(
    uri: 'test://static-binary',
    name: 'Static Binary Resource',
    callback: fn() => new ReadResourceResult(
        contents: [new BlobResourceContents(
            blob: TEST_PNG_BASE64,
            uri: 'test://static-binary',
            mimeType: 'image/png'
        )]
    ),
    description: 'A static binary resource for testing',
    mimeType: 'image/png'
);

$server->resource(
    uri: 'test://watched-resource',
    name: 'Watched Resource',
    callback: fn() => 'Watched resource content (version 1)',
    description: 'A resource that can be subscribed to for changes',
    mimeType: 'text/plain'
);

// Resource template — exercised via McpServer's first-class resourceTemplate()
// API, which advertises it in resources/templates/list and resolves matching
// resources/read calls with the extracted {id} bound by name.
$server->resourceTemplate(
    uriTemplate: 'test://template/{id}/data',
    name: 'Template Resource',
    callback: fn(string $id) => "Template resource data for id={$id}",
    description: 'A parameterized resource template',
    mimeType: 'text/plain'
);

// Completions — exercised via McpServer's first-class completion API. Providers
// receive the partial value (and any already-resolved context) and return the
// suggestion list; McpServer advertises the `completions` capability and routes
// completion/complete to the provider keyed by ref + argument name.
$server->completionForPrompt(
    'test_prompt_with_arguments',
    'arg1',
    fn(string $value) => array_values(array_filter(
        ['value1', 'value2', 'value3'],
        fn($v) => str_starts_with($v, $value)
    ))
);

$server->completionForPrompt(
    'test_prompt_with_embedded_resource',
    'resourceUri',
    fn(string $value) => array_values(array_filter(
        ['test://static-text', 'test://static-binary', 'test://watched-resource'],
        fn($v) => str_starts_with($v, $value)
    ))
);

$server->completionForResourceTemplate(
    'test://template/{id}/data',
    'id',
    fn(string $value) => array_values(array_filter(
        ['item-1', 'item-2', 'item-3'],
        fn($v) => str_starts_with($v, $value)
    ))
);

// ---------------------------------------------------------------------------
// Low-level handlers — ONLY for features McpServer doesn't expose
//
// These remain low-level because McpServer's convenience API doesn't expose
// logging/setLevel or resource subscriptions. Resource templates and
// completions are now handled through McpServer's first-class APIs above.
// ---------------------------------------------------------------------------

$lowLevel = $server->getServer();

// Resource subscribe/unsubscribe — McpServer has no subscription API.
// NOTE: We accept subscriptions but have no mechanism to emit
// notifications/resources/updated. If the conformance tool tests for
// update notifications, those tests will fail. That is correct.
$subscriptions = [];

$lowLevel->registerHandler('resources/subscribe', function ($params) use (&$subscriptions) {
    $uri = $params->uri;
    $subscriptions[$uri] = true;
    return new EmptyResult();
});

$lowLevel->registerHandler('resources/unsubscribe', function ($params) use (&$subscriptions) {
    $uri = $params->uri;
    unset($subscriptions[$uri]);
    return new EmptyResult();
});

// Logging level — McpServer has no setLoggingLevel() method
$lowLevel->registerHandler('logging/setLevel', function ($params) {
    // Accept the level; the capability is advertised but there's nothing
    // to configure at the test-server level.
    return new EmptyResult();
});

// ---------------------------------------------------------------------------
// Run — auto-detects stdio vs HTTP
// ---------------------------------------------------------------------------

// DNS rebinding protection is auto-enabled by McpServer::runHttp() for localhost
// servers. The conformance test sends Origin headers that are validated against
// the default localhost allowlist (['localhost', '127.0.0.1', '::1']).

$server->run();
