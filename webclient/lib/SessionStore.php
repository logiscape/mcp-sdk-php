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

declare(strict_types=1);

use Mcp\Client\Auth\Exception\AuthorizationRedirectException;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Client;
use Mcp\Client\ClientSession;
use Mcp\Client\Transport\StreamableHttpTransport;
use Psr\Log\LoggerInterface;

/**
 * Per-PHP-session persistence for the webclient's single active MCP connection.
 *
 * The MCP SDK's `Client::resumeHttpSession()` rebuilds a live session from a
 * snapshot taken at the end of the previous request. This class is the glue
 * that stores that snapshot in `$_SESSION`, reconstructs `OAuthConfiguration`
 * on each resume (including the client credentials needed for token refresh),
 * and handles the stdio case where each operation spawns a fresh subprocess.
 *
 * Session layout under `$_SESSION['webclient']`:
 *
 *   server       array   normalized connection params (type + transport opts + oauth config)
 *   mcp          array   HTTP-only: sessionManagerState + initResultData + protocolVersion + nextRequestId
 *   catalog      array   cached prompts/tools/resources lists (optional stdio speed-up)
 *   elicited     array   tool names that have triggered an elicitation in this session
 *   pendingOauth array   serialized AuthorizationRequest + server config, keyed by server id
 */
final class SessionStore
{
    private const ROOT = 'webclient';

    public function __construct(private LoggerInterface $logger)
    {
    }

    // ------------------------------------------------------------------
    // Query
    // ------------------------------------------------------------------

    public function isActive(): bool
    {
        return isset($_SESSION[self::ROOT]['server']);
    }

    public function transportType(): ?string
    {
        return $_SESSION[self::ROOT]['server']['type'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function serverInfo(): ?array
    {
        return $_SESSION[self::ROOT]['serverInfo'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function serverConfig(): array
    {
        return $_SESSION[self::ROOT]['server'] ?? [];
    }

    // ------------------------------------------------------------------
    // Connection lifecycle
    // ------------------------------------------------------------------

    /**
     * Open a fresh connection based on UI-submitted params. On success, persists
     * params + resumable state into $_SESSION. On OAuth redirect, bubbles the
     * exception (caller stores pending state + redirects the browser).
     *
     * @param array<string, mixed> $params Normalized connection parameters.
     *        See normalizeParams() for the shape.
     */
    public function beginConnect(array $params, ?ElicitationCapture $capture = null): Client
    {
        $normalized = $this->normalizeParams($params);

        $client = new Client($this->logger);
        if ($capture !== null) {
            $client->onElicit($capture);
        }

        if ($normalized['type'] === 'http') {
            $headers = $normalized['headers'];
            $httpOptions = $this->buildHttpOptions($normalized);
            $client->connect(
                $normalized['url'],
                $headers,
                $httpOptions,
                $normalized['readTimeout'] ?? null
            );
        } else {
            $client->connect(
                $normalized['command'],
                $normalized['args'],
                $normalized['env'] ?: null,
                $normalized['readTimeout'] ?? null
            );
        }

        $_SESSION[self::ROOT]['server'] = $normalized;
        $this->persist($client);
        return $client;
    }

    /**
     * For subsequent operations: HTTP resumes via the SDK; stdio runs a fresh
     * `Client::connect()` with cached params.
     */
    public function resumeOrConnect(?ElicitationCapture $capture = null): Client
    {
        if (!$this->isActive()) {
            throw new RuntimeException('No active MCP connection in this session');
        }
        $config = $_SESSION[self::ROOT]['server'];

        $client = new Client($this->logger);
        if ($capture !== null) {
            $client->onElicit($capture);
        }

        if ($config['type'] === 'http') {
            $mcp = $_SESSION[self::ROOT]['mcp'] ?? null;
            if ($mcp === null) {
                // Rare: HTTP server but no snapshot yet. Fall through to fresh connect.
                $client->connect(
                    $config['url'],
                    $config['headers'],
                    $this->buildHttpOptions($config),
                    $config['readTimeout'] ?? null
                );
                $this->persist($client);
                return $client;
            }

            $initResultData = $this->decodeInitResult($mcp['initResult']);
            $client->resumeHttpSession(
                $config['url'],
                $mcp['sessionManagerState'],
                $initResultData,
                $mcp['protocolVersion'],
                (int)$mcp['nextRequestId'],
                $config['headers'],
                $this->buildHttpOptions($config)
            );
            return $client;
        }

        // stdio: each operation runs a fresh subprocess + handshake.
        $client->connect(
            $config['command'],
            $config['args'],
            $config['env'] ?: null,
            $config['readTimeout'] ?? null
        );
        return $client;
    }

    /**
     * Snapshot resumable state back into $_SESSION. Must be called after every
     * successful SDK operation on an HTTP connection. No-op for stdio.
     */
    public function persist(Client $client): void
    {
        $session = $client->getSession();
        if ($session === null) {
            return;
        }

        // Always cache server info so the UI can render it without an extra call.
        try {
            $_SESSION[self::ROOT]['serverInfo'] = $this->buildServerInfo($session);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to cache serverInfo: ' . $e->getMessage());
        }

        $transport = $client->getTransport();
        if (!$transport instanceof StreamableHttpTransport) {
            return;
        }

        try {
            $_SESSION[self::ROOT]['mcp'] = [
                'sessionManagerState' => $transport->getSessionManager()->toArray(),
                'initResult' => json_encode(
                    $session->getInitializeResult(),
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'protocolVersion' => $session->getNegotiatedProtocolVersion(),
                'nextRequestId' => $session->getNextRequestId(),
            ];
        } catch (Throwable $e) {
            $this->logger->error('Failed to persist MCP session: ' . $e->getMessage());
        }
    }

    /**
     * Close out the stored connection: forget all state and wipe any stored
     * OAuth tokens for this PHP session.
     */
    public function clear(): void
    {
        unset($_SESSION[self::ROOT]);
        try {
            $tokenStore = new SessionTokenStorage(
                Bootstrap::tokenStoragePath(),
                Bootstrap::encryptionSecret()
            );
            $tokenStore->clear();
        } catch (Throwable $e) {
            $this->logger->warning('Token storage cleanup failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Elicitation badge memory
    // ------------------------------------------------------------------

    public function markToolElicited(string $toolName): void
    {
        $_SESSION[self::ROOT]['elicited'][$toolName] = true;
    }

    /**
     * @return array<int, string>
     */
    public function elicitingTools(): array
    {
        return array_keys($_SESSION[self::ROOT]['elicited'] ?? []);
    }

    // ------------------------------------------------------------------
    // Pending OAuth (state that survives the browser redirect round-trip)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     */
    public function storePendingOauth(string $serverId, array $payload): void
    {
        $_SESSION[self::ROOT]['pendingOauth'][$serverId] = $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function takePendingOauth(string $serverId): ?array
    {
        $payload = $_SESSION[self::ROOT]['pendingOauth'][$serverId] ?? null;
        if ($payload !== null) {
            unset($_SESSION[self::ROOT]['pendingOauth'][$serverId]);
        }
        return $payload;
    }

    /**
     * Persist the OAuth `ClientCredentials` discovered or confirmed during the
     * callback exchange so `buildOAuthConfiguration()` can feed them to every
     * subsequent `OAuthConfiguration` instance that drives token refresh.
     *
     * @param array{clientId: string, clientSecret: ?string, tokenEndpointAuthMethod: string} $credentials
     */
    public function rememberClientCredentials(array $credentials): void
    {
        $_SESSION[self::ROOT]['server']['oauth']['credentials'] = $credentials;
    }

    /**
     * If $e is (or wraps) an AuthorizationRedirectException, persist the pending
     * state and return the oauth payload for the browser. Returns null when no
     * redirect is required — callers fall through to their normal error path.
     *
     * Operation-time catches use this to cover the case where a stored token
     * has expired, been revoked, or lacks the scope a later request needs.
     * The chain walk is defensive: `Client::resumeHttpSession()` wraps every
     * exception as RuntimeException with the original as `getPrevious()`, so a
     * redirect raised inside resume arrives here already re-thrown.
     *
     * @param array<string, mixed> $serverConfig Config used to reopen the
     *        connection after OAuth completes (incoming body for connect,
     *        $_SESSION-stored config for operation-time redirects).
     * @return array{authUrl: string, state: string, serverId: string}|null
     */
    public function handleAuthorizationRedirect(\Throwable $e, array $serverConfig): ?array
    {
        $cursor = $e;
        while ($cursor !== null && !$cursor instanceof AuthorizationRedirectException) {
            $cursor = $cursor->getPrevious();
        }
        if ($cursor === null) {
            return null;
        }
        $authReq = $cursor->getAuthorizationRequest();
        if ($authReq === null) {
            $this->logger->error('AuthorizationRedirectException missing AuthorizationRequest');
            return null;
        }
        $state = $authReq->state;
        $this->storePendingOauth($state, [
            'serverConfig' => $serverConfig,
            'authorizationRequest' => $authReq->toArray(),
        ]);
        return [
            'authUrl' => $cursor->getAuthorizationUrl(),
            'state' => $state,
            'serverId' => $state,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeParams(array $raw): array
    {
        $type = $raw['type'] ?? 'stdio';
        if ($type !== 'stdio' && $type !== 'http') {
            throw new InvalidArgumentException("Unknown server type: {$type}");
        }
        if ($type === 'stdio') {
            $command = (string)($raw['command'] ?? '');
            if ($command === '') {
                throw new InvalidArgumentException('Stdio server requires a command');
            }
            return [
                'type' => 'stdio',
                'command' => $command,
                'args' => array_values(array_map('strval', (array)($raw['args'] ?? []))),
                'env' => $this->normalizeEnv((array)($raw['env'] ?? [])),
                'readTimeout' => isset($raw['readTimeout']) ? (float)$raw['readTimeout'] : null,
            ];
        }
        $url = (string)($raw['url'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('HTTP server requires a valid URL');
        }
        // Without this, non-http(s) URLs would be routed through the stdio
        // path by Client::connect() and treated as a command.
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('HTTP server URL must use http or https scheme');
        }
        return [
            'type' => 'http',
            'url' => $url,
            'headers' => $this->normalizeHeaders((array)($raw['headers'] ?? [])),
            'connectionTimeout' => (float)($raw['connectionTimeout'] ?? 30.0),
            'readTimeout' => (float)($raw['readTimeout'] ?? 60.0),
            'verifyTls' => (bool)($raw['verifyTls'] ?? true),
            'oauth' => $this->normalizeOauth($raw['oauth'] ?? null),
        ];
    }

    /**
     * @param array<int|string, mixed> $env
     * @return array<string, string>
     */
    private function normalizeEnv(array $env): array
    {
        $out = [];
        foreach ($env as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $out[$k] = (string)$v;
        }
        return $out;
    }

    /**
     * @param array<int|string, mixed> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $out[$k] = (string)$v;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeOauth(mixed $oauth): ?array
    {
        if (!is_array($oauth) || empty($oauth['enabled'])) {
            return null;
        }
        $out = ['enabled' => true];
        if (!empty($oauth['clientId'])) {
            $out['clientId'] = (string)$oauth['clientId'];
        }
        if (!empty($oauth['clientSecret'])) {
            $out['clientSecret'] = (string)$oauth['clientSecret'];
        }
        if (!empty($oauth['credentials']) && is_array($oauth['credentials'])) {
            $out['credentials'] = $oauth['credentials'];
        }
        return $out;
    }

    /**
     * Build the httpOptions array for `Client::connect()` / `::resumeHttpSession()`.
     * Leaves `enableSse` at the SDK default (true) so interleaved
     * server-initiated requests (elicitation, sampling) can stream back over
     * the same HTTP connection.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildHttpOptions(array $config): array
    {
        $opts = [
            'connectionTimeout' => $config['connectionTimeout'] ?? 30.0,
            'readTimeout' => $config['readTimeout'] ?? 60.0,
            'verifyTls' => $config['verifyTls'] ?? true,
            // The webclient issues one synchronous operation per PHP request
            // and then detaches. A standalone GET SSE process cannot outlive
            // that request usefully, and on cPanel/Apache it may be created
            // with pcntl_fork(), inheriting the PHP session/log state from the
            // request. Keep POST handling unchanged, but do not open that
            // background channel for the wrapper UI.
            'autoSse' => false,
        ];
        $oauthCfg = $this->buildOAuthConfiguration($config);
        if ($oauthCfg !== null) {
            $opts['oauth'] = $oauthCfg;
        }
        return $opts;
    }

    /**
     * Rebuild OAuthConfiguration from persisted config. Returns null if OAuth
     * isn't enabled for this connection.
     *
     * @param array<string, mixed> $config
     */
    private function buildOAuthConfiguration(array $config): ?OAuthConfiguration
    {
        $oauth = $config['oauth'] ?? null;
        if (!is_array($oauth) || empty($oauth['enabled'])) {
            return null;
        }

        // Prefer credentials captured at callback time (includes the
        // tokenEndpointAuthMethod the AS told us to use); fall back to
        // whatever the user entered on the connection form.
        $credentials = null;
        $stored = $oauth['credentials'] ?? null;
        if (is_array($stored) && !empty($stored['clientId'])) {
            $credentials = new ClientCredentials(
                (string)$stored['clientId'],
                isset($stored['clientSecret']) ? (string)$stored['clientSecret'] : null,
                (string)($stored['tokenEndpointAuthMethod'] ?? ClientCredentials::AUTH_METHOD_AUTO)
            );
        } elseif (!empty($oauth['clientId'])) {
            $credentials = new ClientCredentials(
                (string)$oauth['clientId'],
                isset($oauth['clientSecret']) ? (string)$oauth['clientSecret'] : null,
                ClientCredentials::AUTH_METHOD_AUTO
            );
        }

        $tokenStorage = new SessionTokenStorage(
            Bootstrap::tokenStoragePath(),
            Bootstrap::encryptionSecret()
        );
        $callback = new WebCallbackHandler(Bootstrap::oauthCallbackUrl());

        return new OAuthConfiguration(
            clientCredentials: $credentials,
            tokenStorage: $tokenStorage,
            authCallback: $callback,
            verifyTls: (bool)($config['verifyTls'] ?? true),
        );
    }

    /**
     * @param string|array<string, mixed> $encoded
     * @return array<string, mixed>
     */
    private function decodeInitResult(mixed $encoded): array
    {
        if (is_array($encoded)) {
            return $encoded;
        }
        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Stored InitializeResult is empty');
        }
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored InitializeResult is not a JSON object');
        }
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildServerInfo(ClientSession $session): array
    {
        $initResult = $session->getInitializeResult();
        $encoded = json_encode($initResult, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $decoded = $encoded !== false ? json_decode($encoded, true) : null;
        $info = is_array($decoded) ? $decoded : [];
        $info['negotiatedProtocolVersion'] = $session->getNegotiatedProtocolVersion();
        return $info;
    }
}
