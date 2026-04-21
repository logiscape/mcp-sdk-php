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

/**
 * POST /api/execute.php
 *
 * Dispatches a single synchronous MCP operation against the stored connection:
 *   list_prompts, get_prompt, list_tools, call_tool,
 *   list_resources, read_resource, ping.
 *
 * Request body:
 *   {
 *     "operation": "<op>",
 *     "args": { ... operation-specific params ... }
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/Bootstrap.php';
Bootstrap::init();

use Mcp\Client\Client;
use Mcp\Client\ClientSession;

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Bootstrap::json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$logger = Bootstrap::logger();
$store = new SessionStore($logger);
$body = Bootstrap::jsonBody();

$operation = (string)($body['operation'] ?? '');
$args = is_array($body['args'] ?? null) ? $body['args'] : [];

if ($operation === '') {
    Bootstrap::json(['success' => false, 'error' => 'Missing operation'], 400);
}

if (!$store->isActive()) {
    Bootstrap::json([
        'success' => false,
        'error' => 'No active MCP connection',
        'code' => 'not_connected',
    ], 409);
}

$capture = new ElicitationCapture();
$client = null;
try {
    $client = $store->resumeOrConnect($capture);
    $session = $client->getSession();
    if ($session === null) {
        throw new RuntimeException('Failed to obtain client session');
    }

    $result = dispatchOperation($session, $operation, $args);

    // Remember any tool that triggered elicitation so the UI can badge it later.
    if ($operation === 'call_tool' && $capture->hasEvents() && isset($args['name'])) {
        $store->markToolElicited((string)$args['name']);
    }

    $store->persist($client);
    $client->detach();

    $response = [
        'success' => true,
        'operation' => $operation,
        'result' => $result,
        'logs' => Bootstrap::bufferedLogs(),
    ];
    if ($capture->hasEvents()) {
        $response['elicitations'] = $capture->events();
    }
    Bootstrap::json($response);
} catch (InvalidArgumentException $e) {
    safelyClose($client);
    Bootstrap::json([
        'success' => false,
        'error' => $e->getMessage(),
        'logs' => Bootstrap::bufferedLogs(),
    ], 400);
} catch (Throwable $e) {
    safelyClose($client);
    // Tokens can expire, be revoked, or lack scope between connect and any
    // later request — handle that the same way connect.php does so the
    // browser can drive a fresh authorization pass.
    $oauth = $store->handleAuthorizationRedirect($e, $store->serverConfig());
    if ($oauth !== null) {
        Bootstrap::json([
            'success' => false,
            'code' => 'oauth_required',
            'oauth' => $oauth,
            'operation' => $operation,
            'logs' => Bootstrap::bufferedLogs(),
        ], 401);
    }
    $logger->error('Operation failed: ' . $e->getMessage(), [
        'operation' => $operation,
        'class' => get_class($e),
    ]);
    Bootstrap::json([
        'success' => false,
        'error' => $e->getMessage(),
        'operation' => $operation,
        'logs' => Bootstrap::bufferedLogs(),
    ], 500);
}

// ---------- helpers ----------

/**
 * @param array<string, mixed> $args
 */
function dispatchOperation(ClientSession $session, string $operation, array $args): mixed
{
    switch ($operation) {
        case 'list_prompts':
            return toArray($session->listPrompts());

        case 'get_prompt': {
            $name = requireString($args, 'name');
            $promptArgs = normalizeStringMap($args['arguments'] ?? null);
            return toArray($session->getPrompt($name, $promptArgs));
        }

        case 'list_tools':
            return toArray($session->listTools());

        case 'call_tool': {
            $name = requireString($args, 'name');
            $toolArgs = is_array($args['arguments'] ?? null) ? $args['arguments'] : null;
            return toArray($session->callTool($name, $toolArgs));
        }

        case 'list_resources':
            return toArray($session->listResources());

        case 'read_resource': {
            $uri = requireString($args, 'uri');
            return toArray($session->readResource($uri));
        }

        case 'ping':
            $session->sendPing();
            return ['ok' => true];

        default:
            throw new InvalidArgumentException("Unknown operation: {$operation}");
    }
}

/**
 * Serialize an SDK result object into a plain JSON-compatible array.
 * Goes through json_encode so typed sub-objects flatten recursively.
 */
function toArray(mixed $value): mixed
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($encoded === false) {
        return null;
    }
    return json_decode($encoded, true);
}

/**
 * @param array<string, mixed> $args
 */
function requireString(array $args, string $key): string
{
    if (!isset($args[$key]) || !is_string($args[$key]) || $args[$key] === '') {
        throw new InvalidArgumentException("Missing required argument: {$key}");
    }
    return $args[$key];
}

/**
 * `getPrompt` validates that all arguments are strings. Coerce from form input.
 *
 * @return array<string, string>|null
 */
function normalizeStringMap(mixed $raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }
    $out = [];
    foreach ($raw as $k => $v) {
        if (!is_string($k) || $v === null) {
            continue;
        }
        $out[$k] = is_scalar($v) ? (string)$v : json_encode($v);
    }
    return $out === [] ? null : $out;
}

function safelyClose(?Client $client): void
{
    if ($client === null) {
        return;
    }
    try {
        $client->detach();
    } catch (Throwable) {
        // ignore — endpoint is already returning an error
    }
}
