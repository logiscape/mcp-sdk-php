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
 *   - Scenarios that require unimplemented grant types (client_credentials,
 *     jwt-bearer) fail with a clear message naming the missing feature,
 *     rather than falling through to the generic authorization-code flow.
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
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Callback\HeadlessCallbackHandler;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Token\MemoryTokenStorage;

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
// Scenario dispatch
// ---------------------------------------------------------------------------

try {
    match ($scenario) {
        'initialize' => scenarioInitialize($serverUrl),
        'tools_call' => scenarioToolsCall($serverUrl),

        // Scenarios not yet implemented — fail explicitly with clear message
        'elicitation-sep1034-client-defaults' => throw new \RuntimeException(
            "Scenario 'elicitation-sep1034-client-defaults' requires elicitation request "
            . "handler support, which is not yet implemented in the PHP client SDK"
        ),
        'sse-retry' => scenarioSseRetry($serverUrl),

        // --- Client credentials grant type (not implemented) ---
        // These scenarios require grant_type=client_credentials which the SDK
        // does not support. They need dedicated handlers, not the generic
        // authorization-code flow, so they fail here with a clear message.
        'auth/client-credentials-jwt' => throw new \RuntimeException(
            "Scenario 'auth/client-credentials-jwt' requires the client_credentials "
            . "grant type with private_key_jwt authentication (JWT client assertion), "
            . "which is not yet implemented in the PHP client SDK"
        ),
        'auth/client-credentials-basic' => throw new \RuntimeException(
            "Scenario 'auth/client-credentials-basic' requires the client_credentials "
            . "grant type with client_secret_basic authentication, "
            . "which is not yet implemented in the PHP client SDK"
        ),

        // --- Cross-app access (not implemented) ---
        // This scenario requires token exchange (RFC 8693) at an IDP followed
        // by a jwt-bearer grant (RFC 7523) at the AS. It needs a dedicated
        // handler that performs these steps manually.
        'auth/cross-app-access-complete-flow' => throw new \RuntimeException(
            "Scenario 'auth/cross-app-access-complete-flow' requires token exchange "
            . "(RFC 8693) and jwt-bearer grant (RFC 7523), "
            . "which are not yet implemented in the PHP client SDK"
        ),

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
