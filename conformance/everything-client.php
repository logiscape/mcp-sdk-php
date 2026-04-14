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
// Helper: connect to the conformance test server
// ---------------------------------------------------------------------------

function connectToServer(string $serverUrl): ClientSession
{
    $client = new Client();
    return $client->connect($serverUrl);
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
    if ($initResult === null) {
        throw new \RuntimeException('Initialize returned null result');
    }
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
        'sse-retry' => throw new \RuntimeException(
            "Scenario 'sse-retry' requires SSE reconnection support (SEP-1699), "
            . "which is not yet implemented in the PHP client SDK"
        ),

        default => str_starts_with($scenario, 'auth/')
            ? throw new \RuntimeException(
                "Auth scenario '{$scenario}' requires OAuth support, "
                . "which is not yet implemented in the PHP client SDK"
            )
            : throw new \RuntimeException("Unknown scenario: {$scenario}"),
    };

    fwrite(STDERR, "Scenario '{$scenario}' completed successfully\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
