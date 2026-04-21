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
 * POST   /api/connect.php  — open a new MCP connection (stdio or HTTP).
 * DELETE /api/connect.php  — close the current connection.
 *
 * Request body (POST) is a JSON object matching SessionStore::normalizeParams()
 * plus an optional `action: "resume_oauth"` to complete a deferred OAuth flow.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/Bootstrap.php';
Bootstrap::init();

use Mcp\Client\Client;

$logger = Bootstrap::logger();
$store = new SessionStore($logger);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'DELETE') {
    disconnect($store);
}

if ($method !== 'POST') {
    Bootstrap::json(['success' => false, 'error' => 'Method not allowed'], 405);
}

$body = Bootstrap::jsonBody();
$action = isset($body['action']) ? (string)$body['action'] : 'connect';

if ($action === 'disconnect') {
    disconnect($store);
}

if ($action === 'resume_oauth') {
    resumeOauth($store, $logger, $body);
}

// Default action: open a new connection.
try {
    $capture = new ElicitationCapture();
    $client = $store->beginConnect($body, $capture);
    $response = buildConnectSuccess($store, $client);
    if ($capture->hasEvents()) {
        $response['elicitations'] = $capture->events();
    }
    $client->detach();
    $response['logs'] = Bootstrap::bufferedLogs();
    Bootstrap::json($response);
} catch (InvalidArgumentException $e) {
    Bootstrap::json([
        'success' => false,
        'error' => $e->getMessage(),
        'logs' => Bootstrap::bufferedLogs(),
    ], 400);
} catch (Throwable $e) {
    // AuthorizationRedirectException may arrive here directly or wrapped
    // inside a RuntimeException thrown by Client::resumeHttpSession().
    // handleAuthorizationRedirect() walks the chain in both cases.
    $oauth = $store->handleAuthorizationRedirect($e, $body);
    if ($oauth !== null) {
        Bootstrap::json([
            'success' => false,
            'code' => 'oauth_required',
            'oauth' => $oauth,
            'logs' => Bootstrap::bufferedLogs(),
        ], 401);
    }
    $logger->error('Connect failed: ' . $e->getMessage(), [
        'class' => get_class($e),
    ]);
    Bootstrap::json([
        'success' => false,
        'error' => $e->getMessage(),
        'logs' => Bootstrap::bufferedLogs(),
    ], 500);
}

// ---------- helpers ----------

/**
 * Resume a connection that deferred via a browser OAuth redirect.
 *
 * By the time this runs, oauth_callback.php has already exchanged the
 * authorization code and stored the tokens via SessionTokenStorage. The
 * server config we remembered during the original connect attempt now
 * includes the ClientCredentials, so a fresh beginConnect should succeed
 * without re-triggering the redirect.
 *
 * @param array<string, mixed> $body
 */
function resumeOauth(SessionStore $store, \Psr\Log\LoggerInterface $logger, array $body): never
{
    $serverId = isset($body['serverId']) ? (string)$body['serverId'] : '';
    if ($serverId === '') {
        Bootstrap::json([
            'success' => false,
            'error' => 'Missing serverId',
        ], 400);
    }
    $pending = $store->takePendingOauth($serverId);
    if ($pending === null) {
        Bootstrap::json([
            'success' => false,
            'error' => 'No pending OAuth resume for this serverId',
            'code' => 'oauth_resume_unknown',
        ], 404);
    }
    $serverConfig = $pending['serverConfig'] ?? null;
    if (!is_array($serverConfig)) {
        Bootstrap::json([
            'success' => false,
            'error' => 'Pending OAuth state is missing server config',
        ], 500);
    }
    try {
        $capture = new ElicitationCapture();
        $client = $store->beginConnect($serverConfig, $capture);
        $response = buildConnectSuccess($store, $client);
        if ($capture->hasEvents()) {
            $response['elicitations'] = $capture->events();
        }
        $client->detach();
        $response['logs'] = Bootstrap::bufferedLogs();
        Bootstrap::json($response);
    } catch (Throwable $e) {
        // The AS may redirect again (e.g. insufficient scope on the first
        // grant); treat that as another oauth_required response so the
        // browser can complete a second pass.
        $oauth = $store->handleAuthorizationRedirect($e, $serverConfig);
        if ($oauth !== null) {
            Bootstrap::json([
                'success' => false,
                'code' => 'oauth_required',
                'oauth' => $oauth,
                'logs' => Bootstrap::bufferedLogs(),
            ], 401);
        }
        $logger->error('OAuth resume failed: ' . $e->getMessage(), [
            'class' => get_class($e),
        ]);
        Bootstrap::json([
            'success' => false,
            'error' => $e->getMessage(),
            'logs' => Bootstrap::bufferedLogs(),
        ], 500);
    }
}

function disconnect(SessionStore $store): never
{
    $logger = Bootstrap::logger();
    // Best-effort: if an HTTP server session is still alive, tell the server we're gone.
    if ($store->isActive() && $store->transportType() === 'http') {
        try {
            $client = $store->resumeOrConnect();
            $client->close();
        } catch (Throwable $e) {
            $logger->warning('Disconnect close failed (continuing): ' . $e->getMessage());
        }
    }
    $store->clear();
    Bootstrap::json([
        'success' => true,
        'logs' => Bootstrap::bufferedLogs(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function buildConnectSuccess(SessionStore $store, Client $client): array
{
    $session = $client->getSession();
    if ($session === null) {
        throw new RuntimeException('Client did not return a session');
    }
    $initResult = $session->getInitializeResult();
    $caps = $initResult->capabilities;
    $serverInfo = $store->serverInfo() ?? [];

    return [
        'success' => true,
        'transportType' => $store->transportType(),
        'serverInfo' => $serverInfo,
        'capabilities' => [
            'prompts' => $caps->prompts !== null,
            'tools' => $caps->tools !== null,
            'resources' => $caps->resources !== null,
            'logging' => $caps->logging !== null,
            'completions' => $caps->completions !== null,
        ],
        'elicitingTools' => $store->elicitingTools(),
    ];
}
