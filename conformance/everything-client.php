<?php

/**
 * MCP Conformance Test Client
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * Implements the client-side conformance test scenarios expected by the
 * MCP conformance test suite (@modelcontextprotocol/conformance).
 *
 * Design principles:
 *   - Faithfully implement the official runner contract.
 *   - Match the reference TypeScript everything-client behavior.
 *   - Never swallow errors — if a scenario fails, exit 1.
 *   - Use the SDK's public Client/ClientSession API.
 *   - Unknown/unimplemented scenarios exit 1 with a clear message.
 *
 * The conformance runner spawns this script with:
 *   - MCP_CONFORMANCE_SCENARIO env var (scenario name)
 *   - MCP_CONFORMANCE_CONTEXT env var (JSON context, optional)
 *   - Server URL as the last CLI argument
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\ClientSession;
use Mcp\Client\Auth\CrossAppAccessConfiguration;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Callback\HeadlessCallbackHandler;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Token\MemoryTokenStorage;
use Mcp\Shared\McpError;
use Mcp\Shared\Version;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;

/**
 * Redirect URI for conformance tests.
 * Matches the reference TypeScript client (ConformanceOAuthProvider and
 * runPreRegistration both use http://localhost:3000/callback).
 */
const CONFORMANCE_REDIRECT_URI = 'http://localhost:3000/callback';

/**
 * Fixed client metadata URL for CIMD conformance tests.
 * When the AS supports client_id_metadata_document_supported, this URL
 * is used as the client_id instead of doing dynamic registration.
 *
 * Matches the reference TypeScript client's CIMD_CLIENT_METADATA_URL.
 */
const CIMD_CLIENT_METADATA_URL = 'https://conformance-test.local/client-metadata.json';

// ---------------------------------------------------------------------------
// Parse conformance runner inputs
// ---------------------------------------------------------------------------

$scenario = getenv('MCP_CONFORMANCE_SCENARIO');
if ($scenario === false || $scenario === '') {
    fwrite(STDERR, "ERROR: MCP_CONFORMANCE_SCENARIO environment variable not set\n");
    exit(1);
}

$context = null;
$contextJson = getenv('MCP_CONFORMANCE_CONTEXT');
if ($contextJson !== false && $contextJson !== '') {
    $context = json_decode($contextJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "ERROR: Failed to parse MCP_CONFORMANCE_CONTEXT: " . json_last_error_msg() . "\n");
        exit(1);
    }
}

if ($argc < 2) {
    fwrite(STDERR, "ERROR: Server URL must be provided as the last argument\n");
    exit(1);
}
$serverUrl = $argv[$argc - 1];

fwrite(STDERR, "Scenario: {$scenario}\n");
fwrite(STDERR, "Server URL: {$serverUrl}\n");
if ($context !== null) {
    fwrite(STDERR, "Context keys: " . implode(', ', array_keys($context)) . "\n");
}

// ---------------------------------------------------------------------------
// Helper: connect to the conformance test server (no auth)
// ---------------------------------------------------------------------------

function connectToServer(string $serverUrl): ClientSession
{
    $client = new Client();
    return $client->connect($serverUrl);
}

/**
 * Connect to the conformance server with SSE auto-probing disabled.
 *
 * The sse-retry scenario measures the reconnect GET for a specific
 * request/response stream. Disabling the optional background SSE probe keeps
 * that exchange isolated.
 */
function connectToServerNoSse(string $serverUrl): ClientSession
{
    $client = new Client();
    return $client->connect($serverUrl, [], ['autoSse' => false]);
}

/**
 * The modern wire version for forced-modern (2026-07-28) scenarios:
 * MCP_CONFORMANCE_PROTOCOL_VERSION when the runner provides it, otherwise
 * the draft-track identifier the 0.2.0-alpha conformance tool speaks.
 */
function conformanceModernWireVersion(): string
{
    $env = getenv('MCP_CONFORMANCE_PROTOCOL_VERSION');
    return ($env !== false && $env !== '') ? $env : Version::DRAFT_MODERN_PROTOCOL_VERSION;
}

/**
 * Connect in forced-modern mode (protocolMode 'modern'): no server/discover
 * probe, no initialize — the draft-suite mocks answer -32601 to both, and
 * the stateless 2026-07-28 model needs no pre-flight. The client simply
 * starts sending enveloped requests with the given wire version.
 */
function connectToServerModern(string $serverUrl): ClientSession
{
    $client = new Client();
    return $client->connect($serverUrl, [], [
        'autoSse' => false,
        'protocolMode' => 'modern',
        'protocolVersion' => conformanceModernWireVersion(),
    ]);
}

// ---------------------------------------------------------------------------
// Helper: connect to the conformance test server with OAuth
//
// Mirrors the reference TypeScript runAuthClient:
//   - Uses HeadlessCallbackHandler (same as ConformanceOAuthProvider's
//     fetch-with-manual-redirect approach)
//   - Passes CIMD_CLIENT_METADATA_URL so the SDK exercises the CIMD path
//     when the AS advertises client_id_metadata_document_supported
//   - TLS verification disabled explicitly for localhost conformance servers
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed>|null $context
 */
function connectToServerWithAuth(
    string $serverUrl,
    ?array $context = null,
    bool $legacyOAuthFallback = false
): ClientSession {
    $client = new Client();

    $callbackHandler = new HeadlessCallbackHandler(
        redirectUri: CONFORMANCE_REDIRECT_URI,
        timeout: 30.0,
        verifyTls: false
    );

    // Check if context provides pre-registered client credentials.
    // Use AUTH_METHOD_AUTO so the SDK discovers the correct auth method
    // from AS metadata rather than assuming one.
    $clientCredentials = null;
    if ($context !== null && isset($context['client_id'])) {
        $authMethod = $context['token_endpoint_auth_method']
            ?? ClientCredentials::AUTH_METHOD_AUTO;
        $clientCredentials = new ClientCredentials(
            clientId: $context['client_id'],
            clientSecret: $context['client_secret'] ?? null,
            tokenEndpointAuthMethod: $authMethod
        );
    }

    $oauthConfig = new OAuthConfiguration(
        clientCredentials: $clientCredentials,
        tokenStorage: new MemoryTokenStorage(),
        authCallback: $callbackHandler,
        enableCimd: true,
        enableDynamicRegistration: true,
        cimdUrl: CIMD_CLIENT_METADATA_URL,
        redirectUri: CONFORMANCE_REDIRECT_URI,
        verifyTls: false,
        enableLegacyOAuthFallback: $legacyOAuthFallback,
    );

    $httpOptions = [
        'oauth' => $oauthConfig,
        'verifyTls' => false,
        'autoSse' => false,
    ];

    return $client->connect($serverUrl, [], $httpOptions);
}

// ---------------------------------------------------------------------------
// Scenario: initialize
//
// Matches reference: connect, perform a follow-up RPC (listTools) to verify
// the connection works beyond the handshake, then close.
// ---------------------------------------------------------------------------

function scenarioInitialize(string $serverUrl): void
{
    $session = connectToServer($serverUrl);

    $initResult = $session->getInitializeResult();
    fwrite(STDERR, "Protocol version: " . ($initResult->protocolVersion ?? 'unknown') . "\n");
    fwrite(STDERR, "Server name: " . ($initResult->serverInfo->name ?? 'unknown') . "\n");

    // Follow-up RPC to verify the connection works beyond the handshake
    $toolsResult = $session->listTools();
    fwrite(STDERR, "Listed " . count($toolsResult->tools ?? []) . " tools\n");
}

// ---------------------------------------------------------------------------
// Scenario: tools_call
//
// Matches reference: connect, list tools, find add_numbers, call it with
// concrete arguments, verify the response. Fails if tool not found or
// call fails.
// ---------------------------------------------------------------------------

function scenarioToolsCall(string $serverUrl): void
{
    $session = connectToServer($serverUrl);

    // List tools
    $toolsResult = $session->listTools();
    $tools = $toolsResult->tools ?? [];
    fwrite(STDERR, "Found " . count($tools) . " tools\n");

    // Find add_numbers — this is the tool the conformance test server provides
    $found = false;
    foreach ($tools as $tool) {
        if ($tool->name === 'add_numbers') {
            $found = true;
            break;
        }
    }

    if (!$found) {
        throw new \RuntimeException(
            'Expected tool "add_numbers" not found. Available: '
            . implode(', ', array_map(fn($t) => $t->name, $tools))
        );
    }

    // Call with concrete arguments matching the expected schema
    $result = $session->callTool('add_numbers', ['a' => 1, 'b' => 2]);
    $content = $result->content ?? [];
    if (empty($content)) {
        throw new \RuntimeException('add_numbers returned empty content');
    }

    fwrite(STDERR, "add_numbers(1, 2) returned " . count($content) . " content blocks\n");
    foreach ($content as $block) {
        if (isset($block->text)) {
            fwrite(STDERR, "  Result: {$block->text}\n");
        }
    }
}

// ---------------------------------------------------------------------------
// Scenario: sse-retry (SEP-1699)
//
// The conformance server returns an SSE POST response with a priming event
// (id + retry), then gracefully closes the stream before sending the tool
// response. The client must honor the `retry` field, wait the specified ms,
// and reconnect via GET with Last-Event-ID. The tool response is delivered
// on the reconnected GET.
// ---------------------------------------------------------------------------

function scenarioSseRetry(string $serverUrl): void
{
    $session = connectToServerNoSse($serverUrl);

    $toolsResult = $session->listTools();
    $tools = $toolsResult->tools ?? [];
    fwrite(STDERR, "Found " . count($tools) . " tools\n");

    $found = false;
    foreach ($tools as $tool) {
        if ($tool->name === 'test_reconnection') {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new \RuntimeException(
            'Expected tool "test_reconnection" not found. Available: '
            . implode(', ', array_map(fn($t) => $t->name, $tools))
        );
    }

    $result = $session->callTool('test_reconnection', []);
    $content = $result->content ?? [];
    if (empty($content)) {
        throw new \RuntimeException('test_reconnection returned empty content');
    }

    $first = $content[0];
    $text = isset($first->text) ? $first->text : null;
    if ($text !== 'Reconnection test completed successfully') {
        throw new \RuntimeException(
            'Unexpected test_reconnection content: ' . var_export($text, true)
        );
    }

    fwrite(STDERR, "test_reconnection returned expected result\n");
}

// ---------------------------------------------------------------------------
// Scenario: elicitation-sep1034-client-defaults
//
// The conformance server calls its test_client_elicitation_defaults tool,
// which sends an elicitation/create request whose requestedSchema carries a
// per-property `default` for string, integer, number, enum, and boolean
// fields (SEP-1034). The client must advertise the elicitation capability
// with applyDefaults=true, accept with an empty content object, and let the
// SDK fill the defaults into the response — the server then verifies each
// default value round-tripped correctly.
// ---------------------------------------------------------------------------

function scenarioElicitationClientDefaults(string $serverUrl): void
{
    $client = new Client();
    $client->onElicit(
        static function (ElicitationCreateRequest $request): ElicitationCreateResult {
            fwrite(STDERR, "Received elicitation/create: {$request->message}\n");
            return new ElicitationCreateResult(action: 'accept', content: []);
        },
        applyDefaults: true
    );
    $session = $client->connect($serverUrl);

    $result = $session->callTool('test_client_elicitation_defaults', []);
    $content = $result->content ?? [];
    if (empty($content)) {
        throw new \RuntimeException('test_client_elicitation_defaults returned empty content');
    }
    fwrite(STDERR, "test_client_elicitation_defaults returned " . count($content) . " content blocks\n");
}

// ---------------------------------------------------------------------------
// Scenario: json-schema-ref-no-deref (SEP-2106)
//
// The conformance server advertises a tool whose inputSchema uses JSON
// Schema 2020-12 $defs/$ref, including a $ref to a network "canary" URL the
// test framework watches. The client must list the tools (parsing the
// 2020-12 schema without choking) and MUST NOT fetch the network $ref —
// SEP-2106 forbids automatic dereferencing of network URIs. The PHP SDK
// passes schemas through verbatim and never dereferences, so this scenario
// only needs the connect + tools/list flow.
// ---------------------------------------------------------------------------

function scenarioJsonSchemaRefNoDeref(string $serverUrl): void
{
    $session = connectToServer($serverUrl);

    $toolsResult = $session->listTools();
    $tools = $toolsResult->tools ?? [];
    fwrite(STDERR, "Found " . count($tools) . " tools\n");

    if (empty($tools)) {
        throw new \RuntimeException('Expected at least one tool advertising a 2020-12 schema');
    }

    // Confirm the $ref-bearing schema round-tripped intact (nothing
    // dereferenced, nothing dropped).
    foreach ($tools as $tool) {
        $schema = json_decode(json_encode($tool->inputSchema), true);
        $refs = isset($schema['properties']) ? array_filter(
            $schema['properties'],
            static fn($p) => is_array($p) && isset($p['$ref'])
        ) : [];
        fwrite(STDERR, "Tool {$tool->name}: " . count($refs) . " \$ref properties preserved\n");
    }
}

// ---------------------------------------------------------------------------
// Scenario: request-metadata (SEP-2575)
//
// The mock rejects the FIRST request with -32004 UnsupportedProtocolVersion
// carrying supported:["DRAFT-2026-v1"]. Connect with protocolMode 'auto':
// negotiate() retries the probe with the advertised version and enters
// modern mode speaking it; every subsequent request carries the matching
// envelope and MCP-Protocol-Version header.
// ---------------------------------------------------------------------------

function scenarioRequestMetadata(string $serverUrl): void
{
    $client = new Client();
    $session = $client->connect($serverUrl, [], ['autoSse' => false]);

    fwrite(STDERR, "Negotiated wire version: " . ($session->getModernWireVersion() ?? 'legacy') . "\n");

    $toolsResult = $session->listTools();
    fwrite(STDERR, "Listed " . count($toolsResult->tools ?? []) . " tools\n");

    $session->callTool('test_headers', []);
    fwrite(STDERR, "Called test_headers\n");
}

// ---------------------------------------------------------------------------
// Scenario: http-standard-headers (SEP-2243)
//
// Forced modern. Exercises Mcp-Method on every request and Mcp-Name on the
// name-bearing methods: tools/call (params.name, including a hyphenated
// name), resources/read (params.uri VERBATIM — not re-encoded), and
// prompts/get. The mock measures headers; my-hyphenated-tool may not exist
// server-side, so a JSON-RPC error from it is tolerated.
// ---------------------------------------------------------------------------

function scenarioHttpStandardHeaders(string $serverUrl): void
{
    $session = connectToServerModern($serverUrl);

    $session->listTools();
    fwrite(STDERR, "tools/list ok\n");

    $session->callTool('test_headers', []);
    fwrite(STDERR, "tools/call test_headers ok\n");

    try {
        $session->callTool('my-hyphenated-tool', []);
        fwrite(STDERR, "tools/call my-hyphenated-tool ok\n");
    } catch (McpError $e) {
        // Headers are what is measured; a server-side error is acceptable.
        fwrite(STDERR, "tools/call my-hyphenated-tool errored (tolerated): {$e->getMessage()}\n");
    }

    $session->listResources();
    fwrite(STDERR, "resources/list ok\n");

    $session->readResource('file:///path/to/file%20name.txt');
    fwrite(STDERR, "resources/read ok\n");

    $session->listPrompts();
    fwrite(STDERR, "prompts/list ok\n");

    $session->getPrompt('test_prompt');
    fwrite(STDERR, "prompts/get test_prompt ok\n");
}

// ---------------------------------------------------------------------------
// Scenario: http-custom-headers (SEP-2243)
//
// Forced modern. tools/list caches each tool's x-mcp-header annotation map;
// each subsequent tools/call mirrors the annotated arguments as
// Mcp-Param-{name} headers (numbers/booleans stringified, empty string as a
// present empty header, whitespace/non-ASCII/control values base64-wrapped,
// null arguments omitted, unannotated arguments never mirrored). The
// context supplies the exact tool calls; a documented fallback covers a
// context-less run.
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed>|null $context
 */
function scenarioHttpCustomHeaders(string $serverUrl, ?array $context): void
{
    $session = connectToServerModern($serverUrl);

    // Cache annotation maps before calling.
    $toolsResult = $session->listTools();
    fwrite(STDERR, "Listed " . count($toolsResult->tools ?? []) . " tools\n");

    $toolCalls = $context['toolCalls'] ?? null;
    if (!is_array($toolCalls) || $toolCalls === []) {
        $toolCalls = [
            [
                'name' => 'test_custom_headers',
                'arguments' => [
                    'region' => 'us-east1',
                    'priority' => 42,
                    'verbose' => false,
                    'debug' => true,
                    'empty_val' => '',
                    'float_val' => 3.14159,
                    'non_ascii_val' => 'Hello, 世界',
                    'whitespace_val' => ' padded ',
                    'control_char_val' => "line1\nline2",
                    'crlf_val' => "line1\r\nline2",
                    'tab_val' => "\tindented",
                    'leading_space_val' => ' leading',
                    'trailing_space_val' => 'trailing ',
                    'internal_space_val' => 'us west 1',
                    'method_val' => 'custom-method',
                    'query' => 'SELECT 1',
                ],
            ],
            [
                'name' => 'test_custom_headers_null',
                'arguments' => [
                    'region' => 'us-east1',
                    'priority' => 1,
                    'verbose' => null,
                    'query' => 'SELECT 1',
                ],
            ],
        ];
    }

    foreach ($toolCalls as $i => $call) {
        if (!is_array($call) || !isset($call['name']) || !is_string($call['name'])) {
            throw new \RuntimeException("Malformed toolCalls[{$i}] in context");
        }
        $arguments = $call['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new \RuntimeException("Malformed toolCalls[{$i}].arguments in context");
        }
        $session->callTool($call['name'], $arguments);
        fwrite(STDERR, "Called {$call['name']}\n");
    }
}

// ---------------------------------------------------------------------------
// Scenario: http-invalid-tool-headers (SEP-2243)
//
// Forced modern. The mock advertises tools whose x-mcp-header annotations
// violate the spec (empty/typed-object/duplicate/charset-invalid names…).
// The SDK must list tools, exclude every invalid tool (never calling one),
// and still call the valid sibling.
// ---------------------------------------------------------------------------

function scenarioHttpInvalidToolHeaders(string $serverUrl): void
{
    $session = connectToServerModern($serverUrl);

    $toolsResult = $session->listTools();
    $names = array_map(static fn($t) => $t->name, $toolsResult->tools ?? []);
    fwrite(STDERR, "Tools after annotation filtering: " . implode(', ', $names) . "\n");

    $session->callTool('valid_tool', ['region' => 'us-west1']);
    fwrite(STDERR, "Called valid_tool\n");
}

// ---------------------------------------------------------------------------
// Scenario: sep-2322-client-request-state (SEP-2322)
//
// Forced modern (the mock answers -32601 to initialize AND server/discover).
// test_mrtr_echo_state answers input_required with an elicitation/create
// inputRequest plus a requestState the retry must echo byte-identically
// under a new JSON-RPC id; test_mrtr_unrelated (called between MRTR tools)
// must carry neither inputResponses nor requestState; test_mrtr_no_state's
// retry must omit the requestState key entirely; test_mrtr_no_result_type
// returns a result WITHOUT resultType and must not be retried.
// ---------------------------------------------------------------------------

function scenarioSep2322ClientRequestState(string $serverUrl): void
{
    $client = new Client();
    $client->onElicit(
        static function (ElicitationCreateRequest $request): ElicitationCreateResult {
            fwrite(STDERR, "Servicing elicitation/create: {$request->message}\n");
            return new ElicitationCreateResult(action: 'accept', content: ['confirmed' => true]);
        }
    );
    $session = $client->connect($serverUrl, [], [
        'autoSse' => false,
        'protocolMode' => 'modern',
        'protocolVersion' => conformanceModernWireVersion(),
    ]);

    foreach (
        [
            'test_mrtr_echo_state',
            'test_mrtr_unrelated',
            'test_mrtr_no_state',
            'test_mrtr_no_result_type',
        ] as $tool
    ) {
        $session->callTool($tool, []);
        fwrite(STDERR, "Called {$tool}\n");
    }
}

// ---------------------------------------------------------------------------
// Scenario: auth/* - OAuth authorization-code scenarios
//
// Matches reference runAuthClient: connect with OAuth, listTools, then
// callTool('test-tool', {}) to verify that a protected operation succeeds
// after authentication. This matches the upstream TypeScript reference client.
//
// Used for scenarios that use the standard authorization code flow:
// metadata discovery, CIMD, scope handling, token endpoint auth methods,
// pre-registration, resource-mismatch, and offline-access.
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed>|null $context
 */
function scenarioAuth(string $scenario, string $serverUrl, ?array $context): void
{
    fwrite(STDERR, "Running auth scenario: {$scenario}\n");

    // MCP 2025-03-26 spec scenarios exercise legacy OAuth fallback behavior
    // (root-derived AS metadata + default /authorize, /token, /register).
    $legacyOAuthFallback = str_starts_with($scenario, 'auth/2025-03-26-');
    if ($legacyOAuthFallback) {
        fwrite(STDERR, "Enabling MCP 2025-03-26 legacy OAuth fallback\n");
    }

    $session = connectToServerWithAuth($serverUrl, $context, $legacyOAuthFallback);

    $initResult = $session->getInitializeResult();
    fwrite(STDERR, "Protocol version: " . ($initResult->protocolVersion ?? 'unknown') . "\n");
    fwrite(STDERR, "Server name: " . ($initResult->serverInfo->name ?? 'unknown') . "\n");

    // List tools to verify authenticated access
    $toolsResult = $session->listTools();
    fwrite(STDERR, "Listed " . count($toolsResult->tools ?? []) . " tools after auth\n");

    // Call a tool to verify protected operations work after auth.
    // The conformance test server exposes 'test-tool' for this purpose.
    $result = $session->callTool('test-tool', []);
    fwrite(STDERR, "Called test-tool successfully\n");
}

// ---------------------------------------------------------------------------
// Helper: verify authenticated access (listTools + test-tool) on a session
// ---------------------------------------------------------------------------

function verifyAuthenticatedAccess(ClientSession $session): void
{
    $initResult = $session->getInitializeResult();
    fwrite(STDERR, "Protocol version: " . ($initResult->protocolVersion ?? 'unknown') . "\n");
    fwrite(STDERR, "Server name: " . ($initResult->serverInfo->name ?? 'unknown') . "\n");

    $toolsResult = $session->listTools();
    fwrite(STDERR, "Listed " . count($toolsResult->tools ?? []) . " tools after auth\n");

    $session->callTool('test-tool', []);
    fwrite(STDERR, "Called test-tool successfully\n");
}

// ---------------------------------------------------------------------------
// Scenario: auth/client-credentials-jwt and auth/client-credentials-basic
//
// Drives the SDK's client_credentials grant (no browser, PKCE, or redirect).
// The context supplies pre-registered credentials:
//   - jwt:   {client_id, private_key_pem, signing_algorithm} -> private_key_jwt
//            client authentication via an RFC 7523 JWT client assertion
//   - basic: {client_id, client_secret} -> client_secret_basic authentication
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed>|null $context
 */
function scenarioClientCredentials(string $scenario, string $serverUrl, ?array $context): void
{
    fwrite(STDERR, "Running client_credentials scenario: {$scenario}\n");

    if ($context === null || !isset($context['client_id'])) {
        throw new \RuntimeException(
            "Scenario '{$scenario}' requires a context with client_id"
        );
    }

    if (isset($context['private_key_pem'])) {
        $credentials = new ClientCredentials(
            clientId: $context['client_id'],
            clientSecret: null,
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_PRIVATE_KEY_JWT,
            privateKeyPem: $context['private_key_pem'],
            signingAlgorithm: $context['signing_algorithm'] ?? 'ES256'
        );
    } else {
        if (!isset($context['client_secret'])) {
            throw new \RuntimeException(
                "Scenario '{$scenario}' requires client_secret or private_key_pem in context"
            );
        }
        $credentials = new ClientCredentials(
            clientId: $context['client_id'],
            clientSecret: $context['client_secret'],
            tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_BASIC
        );
    }

    $oauthConfig = new OAuthConfiguration(
        clientCredentials: $credentials,
        tokenStorage: new MemoryTokenStorage(),
        verifyTls: false,
        useClientCredentialsGrant: true,
    );

    $client = new Client();
    $session = $client->connect($serverUrl, [], [
        'oauth' => $oauthConfig,
        'verifyTls' => false,
        'autoSse' => false,
    ]);

    verifyAuthenticatedAccess($session);
}

// ---------------------------------------------------------------------------
// Scenario: auth/cross-app-access-complete-flow (SEP-990)
//
// Drives the SDK's cross-app access flow: RFC 8693 token exchange at the IdP
// (ID token -> ID-JAG audienced at the AS), then an RFC 7523 jwt-bearer grant
// at the AS token endpoint with client_secret_basic authentication.
// Context: {client_id, client_secret, idp_client_id, idp_id_token,
//           idp_issuer, idp_token_endpoint}
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed>|null $context
 */
function scenarioCrossAppAccess(string $scenario, string $serverUrl, ?array $context): void
{
    fwrite(STDERR, "Running cross-app access scenario: {$scenario}\n");

    foreach (['client_id', 'client_secret', 'idp_client_id', 'idp_id_token', 'idp_token_endpoint'] as $key) {
        if (!isset($context[$key])) {
            throw new \RuntimeException("Scenario '{$scenario}' requires context key '{$key}'");
        }
    }

    $credentials = new ClientCredentials(
        clientId: $context['client_id'],
        clientSecret: $context['client_secret'],
        tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_CLIENT_SECRET_BASIC
    );

    $crossAppAccess = new CrossAppAccessConfiguration(
        idpTokenEndpoint: $context['idp_token_endpoint'],
        idpIdToken: $context['idp_id_token'],
        idpClientId: $context['idp_client_id'],
        idpIssuer: $context['idp_issuer'] ?? null,
    );

    $oauthConfig = new OAuthConfiguration(
        clientCredentials: $credentials,
        tokenStorage: new MemoryTokenStorage(),
        verifyTls: false,
        crossAppAccess: $crossAppAccess,
    );

    $client = new Client();
    $session = $client->connect($serverUrl, [], [
        'oauth' => $oauthConfig,
        'verifyTls' => false,
        'autoSse' => false,
    ]);

    verifyAuthenticatedAccess($session);
}

// ---------------------------------------------------------------------------
// Scenario dispatch
// ---------------------------------------------------------------------------

try {
    match ($scenario) {
        'initialize' => scenarioInitialize($serverUrl),
        'tools_call' => scenarioToolsCall($serverUrl),

        'elicitation-sep1034-client-defaults' => scenarioElicitationClientDefaults($serverUrl),
        'sse-retry' => scenarioSseRetry($serverUrl),
        'json-schema-ref-no-deref' => scenarioJsonSchemaRefNoDeref($serverUrl),

        // --- 2026-07-28 draft-track transport scenarios (WS3 client side) ---
        'request-metadata' => scenarioRequestMetadata($serverUrl),
        'http-standard-headers' => scenarioHttpStandardHeaders($serverUrl),
        'http-custom-headers' => scenarioHttpCustomHeaders($serverUrl, $context),
        'http-invalid-tool-headers' => scenarioHttpInvalidToolHeaders($serverUrl),
        'sep-2322-client-request-state' => scenarioSep2322ClientRequestState($serverUrl),

        // --- Client credentials grant (no browser, PKCE, or redirect) ---
        'auth/client-credentials-jwt',
        'auth/client-credentials-basic' => scenarioClientCredentials($scenario, $serverUrl, $context),

        // --- Cross-app access (SEP-990): RFC 8693 token exchange at the IdP
        //     followed by an RFC 7523 jwt-bearer grant at the AS ---
        'auth/cross-app-access-complete-flow' => scenarioCrossAppAccess($scenario, $serverUrl, $context),

        default => str_starts_with($scenario, 'auth/')
            ? scenarioAuth($scenario, $serverUrl, $context)
            : throw new \RuntimeException("Unknown scenario: {$scenario}"),
    };

    fwrite(STDERR, "Scenario '{$scenario}' completed successfully\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
