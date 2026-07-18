<?php

/**
 * Dual-era protocol negotiation from the client side.
 *
 * A v2 client can talk to servers from two protocol eras:
 *
 *   - MODERN (2026-07-28): no handshake. The client probes `server/discover`
 *     with its preferred version in `_meta`, then sends self-contained
 *     stateless requests.
 *   - LEGACY (2024-11-05 … 2025-11-25): the classic initialize handshake,
 *     followed by session-scoped requests.
 *
 * With the default `protocolMode: 'auto'`, Client::connect() follows the
 * spec's detection rules:
 *
 *   - stdio: probe `server/discover`; fall back to initialize on
 *     "Method not found" (-32601). If the server answers with
 *     UnsupportedProtocolVersionError (-32022) the client retries with one of
 *     the server's advertised versions — a modern server never triggers the
 *     legacy fallback.
 *   - HTTP: send the modern probe first; a 400 with a recognized modern
 *     JSON-RPC error body means the server is modern (retry appropriately),
 *     while an empty/unrecognized body means legacy fallback.
 *
 * Application code is identical either way — listTools(), callTool(), etc.
 * work on both paths, and getInitializeResult() is synthesized from the
 * discover result on the modern path.
 *
 * Usage:
 *   php examples/client_negotiation.php <server.php | url> [--mode=auto|modern|legacy]
 *
 * Examples:
 *   php examples/client_negotiation.php examples/stateless_server.php
 *   php examples/client_negotiation.php http://localhost:8000 --mode=modern
 */

require 'vendor/autoload.php';

use Mcp\Client\Client;

$target = null;
$mode = 'auto';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, 7);
    } elseif ($target === null) {
        $target = $arg;
    }
}

if ($target === null || !in_array($mode, ['auto', 'modern', 'legacy'], true)) {
    fwrite(STDERR, "Usage: php examples/client_negotiation.php <server.php | url> [--mode=auto|modern|legacy]\n");
    exit(1);
}

$client = new Client();

try {
    $isHttp = str_starts_with($target, 'http://') || str_starts_with($target, 'https://');

    echo "Connecting to {$target} (protocolMode: {$mode})...\n";

    if ($isHttp) {
        // For HTTP, connect options travel in the $env array.
        $session = $client->connect($target, [], ['protocolMode' => $mode]);
    } else {
        // For stdio, spawn the server script as a child process.
        $session = $client->connect('php', [$target], protocolMode: $mode);
    }

    // Report what was negotiated.
    $era = $session->isModernMode() ? 'modern (stateless, per-request)' : 'legacy (initialize handshake)';
    $init = $session->getInitializeResult();

    echo "\nNegotiated era:    {$era}\n";
    echo "Protocol version:  {$session->getNegotiatedProtocolVersion()}\n";
    if ($session->isModernMode()) {
        // The exact identifier stamped into each request's _meta envelope.
        echo "Wire version:      {$session->getModernWireVersion()}\n";
    }
    // getServerInfo() is null when a modern server chose not to identify
    // itself — identity is an optional _meta field since spec PR #3002.
    $identity = $session->getServerInfo();
    echo 'Server:            ' . ($identity !== null ? "{$identity->name} {$identity->version}" : '(anonymous)') . "\n";

    // From here on, the code is era-agnostic.
    $tools = $session->listTools();
    echo "\nTools (" . count($tools->tools) . "):\n";
    foreach ($tools->tools as $tool) {
        echo "  - {$tool->name}: {$tool->description}\n";
    }

    foreach ($tools->tools as $tool) {
        if ($tool->name === 'add-numbers') {
            $result = $session->callTool('add-numbers', ['a' => 19, 'b' => 23]);
            echo "\ncallTool('add-numbers', {a: 19, b: 23}) => {$result->content[0]->text}\n";
            break;
        }
    }
} catch (\Exception $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
} finally {
    $client->close();
}
