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
 * OAuth 2.1 authorization redirect landing page.
 *
 * Exchanges the authorization code for tokens via the SDK's OAuthClient,
 * persists the issued ClientCredentials alongside the stored tokens so
 * future token refreshes can reuse them, and redirects the browser back to
 * index.php where js/oauth.js resumes the deferred MCP connection.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/Bootstrap.php';
Bootstrap::init();

use Mcp\Client\Auth\AuthorizationRequest;
use Mcp\Client\Auth\OAuthClient;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Registration\ClientCredentials;

$logger = Bootstrap::logger();
$store = new SessionStore($logger);

$baseUrl = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$indexUrl = preg_replace('#/api$#', '', $baseUrl) . '/index.php';

// Errors from the authorization server (access_denied, invalid_scope, ...).
if (isset($_GET['error'])) {
    redirectWithError($indexUrl, (string)$_GET['error'], (string)($_GET['error_description'] ?? ''));
}

$code = isset($_GET['code']) ? (string)$_GET['code'] : '';
$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
if ($code === '' || $state === '') {
    redirectWithError($indexUrl, 'invalid_callback', 'Missing code or state in callback');
}

// Intentionally consumed before the code exchange: on exchange failure the
// user restarts from the connect form, so the pending entry is single-use
// and shouldn't linger in $_SESSION if the flow aborts.
$pending = $store->takePendingOauth($state);
if ($pending === null) {
    redirectWithError($indexUrl, 'unknown_state', 'No pending OAuth flow matching this state');
}

$authReqData = $pending['authorizationRequest'] ?? null;
if (!is_array($authReqData)) {
    redirectWithError($indexUrl, 'invalid_pending', 'Pending OAuth entry missing request data');
}

try {
    $authRequest = AuthorizationRequest::fromArray($authReqData);

    $tokenStorage = new SessionTokenStorage(
        Bootstrap::tokenStoragePath(),
        Bootstrap::encryptionSecret()
    );
    // Honour the operator's verifyTls preference from the original connect
    // form: a developer testing a self-signed / internal OAuth server will
    // have set verifyTls=false, and a default of true would succeed through
    // discovery + authorize only to fail here at the code exchange.
    $serverConfig = $pending['serverConfig'] ?? null;
    $verifyTls = true;
    if (is_array($serverConfig) && array_key_exists('verifyTls', $serverConfig)) {
        $verifyTls = (bool)$serverConfig['verifyTls'];
    }
    // We use a minimal OAuthConfiguration — only tokenStorage + verifyTls
    // matter at exchange time. We explicitly suppress the MemoryTokenStorage
    // warning by providing FileTokenStorage via the SessionTokenStorage
    // wrapper.
    $oauthConfig = new OAuthConfiguration(
        clientCredentials: new ClientCredentials(
            $authRequest->clientId,
            $authRequest->clientSecret,
            $authRequest->tokenEndpointAuthMethod
        ),
        tokenStorage: $tokenStorage,
        verifyTls: $verifyTls,
    );
    $oauthClient = new OAuthClient($oauthConfig, $logger);

    $oauthClient->exchangeCodeForTokens($authRequest, $code);

    // Persist ClientCredentials alongside the stored config so SessionStore
    // can feed them to future OAuthConfiguration instances for token refresh.
    if (is_array($serverConfig)) {
        if (!is_array($serverConfig['oauth'] ?? null)) {
            $serverConfig['oauth'] = ['enabled' => true];
        }
        $serverConfig['oauth']['credentials'] = [
            'clientId' => $authRequest->clientId,
            'clientSecret' => $authRequest->clientSecret,
            'tokenEndpointAuthMethod' => $authRequest->tokenEndpointAuthMethod,
        ];
        $store->storePendingOauth($state, [
            'serverConfig' => $serverConfig,
            'authorizationRequest' => $authReqData,
            'exchanged' => true,
        ]);
    }

    header('Location: ' . $indexUrl . '?oauth=success&oauth_server=' . urlencode($state));
    exit;
} catch (Throwable $e) {
    $logger->error('OAuth callback failed: ' . $e->getMessage(), [
        'class' => get_class($e),
    ]);
    redirectWithError($indexUrl, 'exchange_failed', $e->getMessage());
}

function redirectWithError(string $indexUrl, string $code, string $detail = ''): never
{
    $qs = [
        'oauth' => 'error',
        'oauth_error' => $code,
    ];
    if ($detail !== '') {
        $qs['oauth_error_detail'] = $detail;
    }
    header('Location: ' . $indexUrl . '?' . http_build_query($qs));
    exit;
}
