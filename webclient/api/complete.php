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
 * POST /api/complete.php
 *
 * Body:
 *   {
 *     "ref":      { "type": "ref/prompt", "name": "..." }
 *                 | { "type": "ref/resource", "uri": "..." },
 *     "argument": { "name": "fieldName", "value": "partial-text" }
 *   }
 *
 * Response (success): { success: true, result: { completion: {...} }, logs: [...] }
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/Bootstrap.php';
Bootstrap::init();

use Mcp\Client\Client;
use Mcp\Types\PromptReference;
use Mcp\Types\ResourceReference;

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Bootstrap::json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$logger = Bootstrap::logger();
$store = new SessionStore($logger);

if (!$store->isActive()) {
    Bootstrap::json([
        'success' => false,
        'error' => 'No active MCP connection',
        'code' => 'not_connected',
    ], 409);
}

$body = Bootstrap::jsonBody();
$refIn = is_array($body['ref'] ?? null) ? $body['ref'] : null;
$argIn = is_array($body['argument'] ?? null) ? $body['argument'] : null;

if ($refIn === null || $argIn === null) {
    Bootstrap::json([
        'success' => false,
        'error' => 'Request requires "ref" and "argument" objects',
    ], 400);
}

$ref = buildRef($refIn);
$argument = normalizeArgument($argIn);

$client = null;
try {
    $client = $store->resumeOrConnect();
    $session = $client->getSession();
    if ($session === null) {
        throw new RuntimeException('Failed to obtain client session');
    }
    $completeResult = $session->complete($ref, $argument);
    $store->persist($client);
    $client->detach();

    $encoded = json_encode($completeResult, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $resultArray = $encoded !== false ? json_decode($encoded, true) : null;

    Bootstrap::json([
        'success' => true,
        'result' => $resultArray,
        'logs' => Bootstrap::bufferedLogs(),
    ]);
} catch (InvalidArgumentException $e) {
    safeDetach($client);
    Bootstrap::json([
        'success' => false,
        'error' => $e->getMessage(),
        'logs' => Bootstrap::bufferedLogs(),
    ], 400);
} catch (Throwable $e) {
    safeDetach($client);
    // A protected server can raise AuthorizationRedirectException here too
    // (token expired, revoked, missing, or under-scoped). The browser
    // handles oauth_required by navigating, which aborts the debounce
    // loop, so at most one pending entry is stored per expiry event.
    $oauth = $store->handleAuthorizationRedirect($e, $store->serverConfig());
    if ($oauth !== null) {
        Bootstrap::json([
            'success' => false,
            'code' => 'oauth_required',
            'oauth' => $oauth,
            'logs' => Bootstrap::bufferedLogs(),
        ], 401);
    }
    $logger->warning('Complete failed: ' . $e->getMessage());
    Bootstrap::json([
        'success' => false,
        'error' => $e->getMessage(),
        'logs' => Bootstrap::bufferedLogs(),
    ], 500);
}

/**
 * @param array<string, mixed> $refIn
 */
function buildRef(array $refIn): ResourceReference|PromptReference
{
    $type = (string)($refIn['type'] ?? '');
    return match ($type) {
        'ref/prompt' => new PromptReference((string)($refIn['name'] ?? '')),
        'ref/resource' => new ResourceReference((string)($refIn['uri'] ?? '')),
        default => throw new InvalidArgumentException("Invalid reference type: {$type}"),
    };
}

/**
 * @param array<string, mixed> $in
 * @return array{name: string, value: string}
 */
function normalizeArgument(array $in): array
{
    $name = (string)($in['name'] ?? '');
    $value = $in['value'] ?? '';
    if ($name === '') {
        throw new InvalidArgumentException('argument.name is required');
    }
    return [
        'name' => $name,
        'value' => is_scalar($value) ? (string)$value : '',
    ];
}

function safeDetach(?Client $client): void
{
    if ($client === null) {
        return;
    }
    try {
        $client->detach();
    } catch (Throwable) {
        // ignore
    }
}
