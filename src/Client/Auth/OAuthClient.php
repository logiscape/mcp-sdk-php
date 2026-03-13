<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude Opus 4.5 (Anthropic AI model)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Client/Auth/OAuthClient.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth;

use Mcp\Client\Auth\Callback\AuthorizationCallbackInterface;
use Mcp\Client\Auth\Callback\LoopbackCallbackHandler;
use Mcp\Client\Auth\Discovery\AuthorizationServerMetadata;
use Mcp\Client\Auth\Discovery\MetadataDiscovery;
use Mcp\Client\Auth\Discovery\ProtectedResourceMetadata;
use Mcp\Client\Auth\Exception\AuthorizationRedirectException;
use Mcp\Client\Auth\Pkce\PkceGenerator;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Registration\DynamicClientRegistration;
use Mcp\Client\Auth\Token\TokenSet;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OAuth 2.0 client implementation for MCP.
 *
 * Handles the complete OAuth flow including:
 * - Protected Resource Metadata discovery (RFC9728)
 * - Authorization Server Metadata discovery (RFC8414/OIDC)
 * - PKCE with S256 (RFC7636)
 * - Resource Indicators (RFC8707)
 * - Client registration (pre-registered, CIMD, or DCR)
 * - Token management and refresh
 */
class OAuthClient implements OAuthClientInterface
{
    private OAuthConfiguration $config;
    private LoggerInterface $logger;
    private MetadataDiscovery $discovery;
    private PkceGenerator $pkce;
    private DynamicClientRegistration $dcr;

    /**
     * Cached metadata for resource URLs.
     * @var array<string, ProtectedResourceMetadata>
     */
    private array $resourceMetadataCache = [];

    /**
     * Cached metadata for authorization servers.
     * @var array<string, AuthorizationServerMetadata>
     */
    private array $authServerMetadataCache = [];

    /**
     * Cached client credentials per authorization server.
     * @var array<string, ClientCredentials>
     */
    private array $clientCredentialsCache = [];

    /**
     * @param OAuthConfiguration $config OAuth configuration
     * @param LoggerInterface|null $logger PSR-3 logger
     */
    public function __construct(
        OAuthConfiguration $config,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->discovery = new MetadataDiscovery(
            $config->getTimeout(),
            $config->isVerifyTlsEnabled(),
            $this->logger
        );
        $this->pkce = new PkceGenerator();
        $this->dcr = new DynamicClientRegistration(
            $config->getTimeout(),
            $config->isVerifyTlsEnabled(),
            $this->logger
        );
    }

    /**
     * {@inheritdoc}
     */
    public function handleUnauthorized(string $resourceUrl, array $wwwAuthHeader): TokenSet
    {
        $this->logger->info('Handling 401 Unauthorized', ['resource' => $resourceUrl]);

        // Step 1: Discover Protected Resource Metadata
        $resourceMetadataUrl = $wwwAuthHeader['resource_metadata'] ?? null;
        $resourceMetadata = $this->discoverResourceMetadata($resourceUrl, $resourceMetadataUrl);

        // Step 2: Select authorization server
        $authServerUrl = $resourceMetadata->getPrimaryAuthorizationServer();
        if ($authServerUrl === null) {
            throw new OAuthException(
                'No authorization server found in Protected Resource Metadata'
            );
        }

        // Step 3: Discover Authorization Server Metadata
        $authServerMetadata = $this->discoverAuthorizationServerMetadata($authServerUrl);

        // Step 4: Determine scopes to request (per MCP spec)
        $scopes = $this->determineScopes($wwwAuthHeader, $resourceMetadata);

        // Step 5: Perform authorization flow
        $tokens = $this->performAuthorizationFlow(
            $resourceUrl,
            $resourceMetadata,
            $authServerMetadata,
            $scopes
        );

        // Step 6: Store tokens
        $this->config->getTokenStorage()->store($resourceUrl, $tokens);

        return $tokens;
    }

    /**
     * {@inheritdoc}
     */
    public function handleInsufficientScope(
        string $resourceUrl,
        array $wwwAuthHeader,
        TokenSet $current
    ): TokenSet {
        $this->logger->info('Handling 403 insufficient_scope', ['resource' => $resourceUrl]);

        // Get required scopes from WWW-Authenticate header
        $requiredScope = $wwwAuthHeader['scope'] ?? null;
        if ($requiredScope === null) {
            throw new OAuthException(
                'No scope information in WWW-Authenticate header for insufficient_scope error'
            );
        }

        $requiredScopes = explode(' ', $requiredScope);

        // Merge with current scopes
        $newScopes = array_unique(array_merge($current->scope, $requiredScopes));

        // Discover metadata (may be cached)
        $resourceMetadataUrl = $wwwAuthHeader['resource_metadata'] ?? null;
        $resourceMetadata = $this->discoverResourceMetadata($resourceUrl, $resourceMetadataUrl);

        $authServerUrl = $resourceMetadata->getPrimaryAuthorizationServer();
        if ($authServerUrl === null) {
            throw new OAuthException('No authorization server found');
        }

        $authServerMetadata = $this->discoverAuthorizationServerMetadata($authServerUrl);

        // Perform new authorization with expanded scopes
        $tokens = $this->performAuthorizationFlow(
            $resourceUrl,
            $resourceMetadata,
            $authServerMetadata,
            $newScopes
        );

        // Store new tokens
        $this->config->getTokenStorage()->store($resourceUrl, $tokens);

        return $tokens;
    }

    /**
     * {@inheritdoc}
     */
    public function refreshToken(TokenSet $tokens): TokenSet
    {
        if (!$tokens->canRefresh()) {
            throw OAuthException::tokenRefreshFailed('No refresh token available');
        }

        $issuer = $tokens->issuer;
        if ($issuer === null) {
            throw OAuthException::tokenRefreshFailed('Token issuer unknown');
        }

        $this->logger->debug('Refreshing token', ['issuer' => $issuer]);

        // Get cached AS metadata
        $authServerMetadata = $this->authServerMetadataCache[$issuer] ?? null;
        if ($authServerMetadata === null) {
            $authServerMetadata = $this->discoverAuthorizationServerMetadata($issuer);
        }

        // Get client credentials
        $credentials = $this->getClientCredentials($issuer, $authServerMetadata);

        // Build refresh request
        $params = array_merge(
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $tokens->refreshToken,
            ],
            $credentials->getTokenRequestParams()
        );

        // Execute token request
        $response = $this->executeTokenRequest(
            $authServerMetadata->tokenEndpoint,
            $params,
            $credentials
        );

        $newTokens = TokenSet::fromTokenResponse(
            $response,
            $tokens->resourceUrl,
            $issuer,
            $tokens->scope  // Preserve original scopes per RFC 6749 Section 6
        );

        // If no new refresh token was issued, keep the old one
        if ($newTokens->refreshToken === null && $tokens->refreshToken !== null) {
            $newTokens = new TokenSet(
                accessToken: $newTokens->accessToken,
                refreshToken: $tokens->refreshToken,
                expiresAt: $newTokens->expiresAt,
                tokenType: $newTokens->tokenType,
                scope: $newTokens->scope,
                resourceUrl: $newTokens->resourceUrl,
                issuer: $newTokens->issuer
            );
        }

        // Update storage
        if ($tokens->resourceUrl !== null) {
            $this->config->getTokenStorage()->store($tokens->resourceUrl, $newTokens);
        }

        $this->logger->info('Token refreshed successfully');

        return $newTokens;
    }

    /**
     * {@inheritdoc}
     */
    public function getTokens(string $resourceUrl): ?TokenSet
    {
        return $this->config->getTokenStorage()->retrieve($resourceUrl);
    }

    /**
     * {@inheritdoc}
     */
    public function hasValidToken(string $resourceUrl): bool
    {
        $tokens = $this->getTokens($resourceUrl);
        return $tokens !== null && !$tokens->isExpired();
    }

    /**
     * Check if tokens should be proactively refreshed.
     *
     * @param string $resourceUrl The protected resource URL
     * @return bool True if tokens should be refreshed
     */
    public function shouldRefreshToken(string $resourceUrl): bool
    {
        if (!$this->config->isAutoRefreshEnabled()) {
            return false;
        }

        $tokens = $this->getTokens($resourceUrl);
        if ($tokens === null || !$tokens->canRefresh()) {
            return false;
        }

        return $tokens->willExpireSoon($this->config->getRefreshBuffer());
    }

    /**
     * Proactively refresh tokens if needed.
     *
     * @param string $resourceUrl The protected resource URL
     * @return TokenSet|null Refreshed tokens, or null if refresh not needed/possible
     */
    public function proactiveRefresh(string $resourceUrl): ?TokenSet
    {
        if (!$this->shouldRefreshToken($resourceUrl)) {
            return null;
        }

        $tokens = $this->getTokens($resourceUrl);
        if ($tokens === null) {
            return null;
        }

        try {
            return $this->refreshToken($tokens);
        } catch (OAuthException $e) {
            $this->logger->warning('Proactive token refresh failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Initiate a web-based OAuth authorization flow.
     *
     * This method is designed for web hosting environments where the OAuth flow
     * cannot be completed synchronously. It performs metadata discovery, client
     * registration (if needed), and returns an AuthorizationRequest containing
     * all data needed to complete the flow after the browser redirect.
     *
     * @param string $resourceUrl The protected resource URL
     * @param array<string, string|null> $wwwAuthHeader Parsed WWW-Authenticate header (may include resource_metadata, scope)
     * @return AuthorizationRequest All data needed to complete the OAuth flow
     * @throws OAuthException If metadata discovery or client registration fails
     */
    public function initiateWebAuthorization(
        string $resourceUrl,
        array $wwwAuthHeader = []
    ): AuthorizationRequest {
        $this->logger->info('Initiating web OAuth authorization', ['resource' => $resourceUrl]);

        // Step 1: Discover Protected Resource Metadata
        // Use resource_metadata URL from WWW-Authenticate header if provided (per MCP spec)
        $resourceMetadataUrl = $wwwAuthHeader['resource_metadata'] ?? null;
        $resourceMetadata = $this->discoverResourceMetadata($resourceUrl, $resourceMetadataUrl);

        // Step 2: Select authorization server
        $authServerUrl = $resourceMetadata->getPrimaryAuthorizationServer();
        if ($authServerUrl === null) {
            throw new OAuthException(
                'No authorization server found in Protected Resource Metadata'
            );
        }

        // Step 3: Discover Authorization Server Metadata
        $authServerMetadata = $this->discoverAuthorizationServerMetadata($authServerUrl);

        // Step 4: Get or register client credentials
        $credentials = $this->getClientCredentials($authServerMetadata->issuer, $authServerMetadata);

        // Step 5: Determine scopes (per MCP spec: WWW-Authenticate header has priority)
        $scopes = $this->determineScopes($wwwAuthHeader, $resourceMetadata);

        // Step 6: Generate PKCE pair
        $pkce = $this->pkce->generate();

        // Step 7: Generate state for CSRF protection
        $state = bin2hex(random_bytes(16));

        // Step 8: Determine redirect URI
        $callback = $this->getAuthCallback();
        $redirectUri = $this->config->getRedirectUri();
        if ($redirectUri === null) {
            $redirectUri = $callback->getRedirectUri();
        }

        // Step 9: Build authorization URL
        $authParams = [
            'response_type' => 'code',
            'client_id' => $credentials->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => $pkce['method'],
            // RFC8707: Resource Indicators
            'resource' => $resourceMetadata->resource,
        ];

        if (!empty($scopes)) {
            $authParams['scope'] = implode(' ', $scopes);
        }

        $authUrl = $authServerMetadata->authorizationEndpoint . '?' . http_build_query($authParams);

        $this->logger->debug('Built authorization URL for web flow', [
            'authorization_endpoint' => $authServerMetadata->authorizationEndpoint,
            'scopes' => $scopes,
        ]);

        // Return AuthorizationRequest with all data needed for token exchange
        return new AuthorizationRequest(
            authorizationUrl: $authUrl,
            state: $state,
            codeVerifier: $pkce['verifier'],
            redirectUri: $redirectUri,
            resourceUrl: $resourceUrl,
            resource: $resourceMetadata->resource,
            tokenEndpoint: $authServerMetadata->tokenEndpoint,
            issuer: $authServerMetadata->issuer,
            clientId: $credentials->clientId,
            clientSecret: $credentials->clientSecret,
            tokenEndpointAuthMethod: $credentials->tokenEndpointAuthMethod,
            resourceMetadataUrl: $resourceMetadataUrl
        );
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * This method is designed for web hosting environments where the OAuth flow
     * is completed in two phases. It uses the data from an AuthorizationRequest
     * to exchange the authorization code for tokens.
     *
     * @param AuthorizationRequest $request The authorization request data
     * @param string $code The authorization code received from the callback
     * @return TokenSet The obtained tokens
     * @throws OAuthException If token exchange fails
     */
    public function exchangeCodeForTokens(
        AuthorizationRequest $request,
        string $code
    ): TokenSet {
        $this->logger->info('Exchanging authorization code for tokens', [
            'resource' => $request->resourceUrl,
        ]);

        // Build credentials from AuthorizationRequest
        $credentials = new ClientCredentials(
            clientId: $request->clientId,
            clientSecret: $request->clientSecret,
            tokenEndpointAuthMethod: $request->tokenEndpointAuthMethod
        );

        // Build token request parameters
        $tokenParams = array_merge(
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $request->redirectUri,
                'code_verifier' => $request->codeVerifier,
                // RFC8707: Include resource in token request
                'resource' => $request->resource,
            ],
            $credentials->getTokenRequestParams()
        );

        // Execute token request (handles client_secret_post vs client_secret_basic)
        $tokenResponse = $this->executeTokenRequest(
            $request->tokenEndpoint,
            $tokenParams,
            $credentials
        );

        // Create TokenSet from response
        $tokens = TokenSet::fromTokenResponse(
            $tokenResponse,
            $request->resourceUrl,
            $request->issuer
        );

        // Store tokens
        $this->config->getTokenStorage()->store($request->resourceUrl, $tokens);

        $this->logger->info('Token exchange completed successfully');

        return $tokens;
    }

    /**
     * Discover Protected Resource Metadata.
     *
     * @param string $resourceUrl The protected resource URL
     * @param string|null $metadataUrl Optional metadata URL from header
     * @return ProtectedResourceMetadata
     */
    private function discoverResourceMetadata(
        string $resourceUrl,
        ?string $metadataUrl
    ): ProtectedResourceMetadata {
        $cacheKey = $resourceUrl;

        if (isset($this->resourceMetadataCache[$cacheKey])) {
            return $this->resourceMetadataCache[$cacheKey];
        }

        $metadata = $this->discovery->discoverResourceMetadata($resourceUrl, $metadataUrl);
        $this->resourceMetadataCache[$cacheKey] = $metadata;

        return $metadata;
    }

    /**
     * Discover Authorization Server Metadata.
     *
     * @param string $authServerUrl The authorization server URL
     * @return AuthorizationServerMetadata
     */
    private function discoverAuthorizationServerMetadata(
        string $authServerUrl
    ): AuthorizationServerMetadata {
        if (isset($this->authServerMetadataCache[$authServerUrl])) {
            return $this->authServerMetadataCache[$authServerUrl];
        }

        $metadata = $this->discovery->discoverAuthorizationServerMetadata($authServerUrl);
        $this->authServerMetadataCache[$authServerUrl] = $metadata;

        return $metadata;
    }

    /**
     * Determine scopes to request per MCP spec.
     *
     * Priority:
     * 1. scope from WWW-Authenticate header
     * 2. scopes_supported from Protected Resource Metadata
     * 3. Omit scope parameter if neither available
     *
     * @param array<string, string|null> $wwwAuthHeader Parsed WWW-Authenticate header
     * @param ProtectedResourceMetadata $resourceMetadata Resource metadata
     * @return array<int, string> Scopes to request
     */
    private function determineScopes(
        array $wwwAuthHeader,
        ProtectedResourceMetadata $resourceMetadata
    ): array {
        // Add any additional scopes from configuration
        $additionalScopes = $this->config->getAdditionalScopes();

        // Priority 1: scope from WWW-Authenticate header
        if (isset($wwwAuthHeader['scope'])) {
            $scopes = explode(' ', $wwwAuthHeader['scope']);
            return array_unique(array_merge($scopes, $additionalScopes));
        }

        // Priority 2: scopes_supported from resource metadata
        if ($resourceMetadata->scopesSupported !== null) {
            return array_unique(array_merge($resourceMetadata->scopesSupported, $additionalScopes));
        }

        // Priority 3: only additional scopes (or empty)
        return $additionalScopes;
    }

    /**
     * Get or create client credentials for an authorization server.
     *
     * @param string $issuer The authorization server issuer
     * @param AuthorizationServerMetadata $asMetadata AS metadata
     * @return ClientCredentials
     */
    private function getClientCredentials(
        string $issuer,
        AuthorizationServerMetadata $asMetadata
    ): ClientCredentials {
        // Check cache
        if (isset($this->clientCredentialsCache[$issuer])) {
            return $this->clientCredentialsCache[$issuer];
        }

        // Priority 1: Pre-registered credentials
        if ($this->config->hasClientCredentials()) {
            $credentials = $this->config->getClientCredentials();
            $this->clientCredentialsCache[$issuer] = $credentials;
            return $credentials;
        }

        // Priority 2: CIMD (Client ID Metadata Document)
        if ($this->config->hasCimd() && $asMetadata->supportsCimd()) {
            $this->logger->debug('Using CIMD for client identification');
            $credentials = new ClientCredentials(
                clientId: $this->config->getCimdUrl(),
                clientSecret: null,
                tokenEndpointAuthMethod: 'none'
            );
            $this->clientCredentialsCache[$issuer] = $credentials;
            return $credentials;
        }

        // Priority 3: Dynamic Client Registration
        if ($this->config->isDynamicRegistrationEnabled() && $asMetadata->supportsDynamicRegistration()) {
            $this->logger->debug('Registering client dynamically');

            $callback = $this->getAuthCallback();
            $redirectUri = $callback->getRedirectUri();

            // For auto-port loopback handler, we need to register with actual redirect URIs.
            // Per RFC 8252, authorization servers SHOULD allow any port on loopback interfaces.
            // We register both localhost and 127.0.0.1 variants to maximize compatibility.
            $redirectUris = [$redirectUri];
            if ($callback instanceof LoopbackCallbackHandler && strpos($redirectUri, '{PORT}') !== false) {
                // Register with common loopback URIs - AS should accept any port per RFC 8252
                $redirectUris = [
                    'http://127.0.0.1/callback',
                    'http://localhost/callback',
                ];
            }

            $metadata = DynamicClientRegistration::buildMetadata(
                clientName: 'MCP PHP Client',
                redirectUris: $redirectUris
            );

            $credentials = $this->dcr->register($asMetadata, $metadata);
            $this->clientCredentialsCache[$issuer] = $credentials;
            return $credentials;
        }

        throw new OAuthException(
            'No client credentials available. Configure pre-registered credentials, CIMD, or enable DCR.'
        );
    }

    /**
     * Get the authorization callback handler.
     *
     * @return AuthorizationCallbackInterface
     */
    private function getAuthCallback(): AuthorizationCallbackInterface
    {
        $callback = $this->config->getAuthCallback();
        if ($callback === null) {
            // Default to loopback handler
            $callback = new LoopbackCallbackHandler(0, 120, true, $this->logger);
        }
        return $callback;
    }

    /**
     * Perform the OAuth authorization code flow.
     *
     * @param string $resourceUrl The protected resource URL
     * @param ProtectedResourceMetadata $resourceMetadata Resource metadata
     * @param AuthorizationServerMetadata $asMetadata AS metadata
     * @param array<int, string> $scopes Scopes to request
     * @return TokenSet
     */
    private function performAuthorizationFlow(
        string $resourceUrl,
        ProtectedResourceMetadata $resourceMetadata,
        AuthorizationServerMetadata $asMetadata,
        array $scopes
    ): TokenSet {
        $callback = $this->getAuthCallback();
        $credentials = $this->getClientCredentials($asMetadata->issuer, $asMetadata);

        // Generate PKCE pair
        $pkce = $this->pkce->generate();

        // Generate state for CSRF protection
        $state = bin2hex(random_bytes(16));

        // Determine redirect URI
        $redirectUri = $this->config->getRedirectUri();
        if ($redirectUri === null) {
            $redirectUri = $callback->getRedirectUri();
        }

        // Build authorization URL
        $authParams = [
            'response_type' => 'code',
            'client_id' => $credentials->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => $pkce['method'],
            // RFC8707: Resource Indicators
            'resource' => $resourceMetadata->resource,
        ];

        if (!empty($scopes)) {
            $authParams['scope'] = implode(' ', $scopes);
        }

        $authUrl = $asMetadata->authorizationEndpoint . '?' . http_build_query($authParams);

        $this->logger->debug('Starting authorization flow', [
            'authorization_endpoint' => $asMetadata->authorizationEndpoint,
            'scopes' => $scopes,
        ]);

        // Execute authorization flow via callback handler
        // Note: For LoopbackCallbackHandler with auto-port, the handler will replace
        // the {PORT} placeholder in the auth URL with the actual port
        try {
            $code = $callback->authorize($authUrl, $state);
        } catch (AuthorizationRedirectException $e) {
            // Enrich the exception with an AuthorizationRequest so callers
            // have everything needed to complete the OAuth flow later.
            // Use the exception's values (what the callback actually used)
            // to ensure consistency between the redirect and token exchange.
            throw new AuthorizationRedirectException(
                authorizationUrl: $e->getAuthorizationUrl(),
                state: $e->getState(),
                redirectUri: $e->getRedirectUri(),
                message: $e->getMessage(),
                authorizationRequest: new AuthorizationRequest(
                    authorizationUrl: $e->getAuthorizationUrl(),
                    state: $e->getState(),
                    codeVerifier: $pkce['verifier'],
                    redirectUri: $e->getRedirectUri(),
                    resourceUrl: $resourceUrl,
                    resource: $resourceMetadata->resource,
                    tokenEndpoint: $asMetadata->tokenEndpoint,
                    issuer: $asMetadata->issuer,
                    clientId: $credentials->clientId,
                    clientSecret: $credentials->clientSecret,
                    tokenEndpointAuthMethod: $credentials->tokenEndpointAuthMethod,
                    resourceMetadataUrl: null
                )
            );
        }

        // Get the actual redirect URI used (important for auto-port loopback handler)
        // After authorize() completes, the handler knows the actual port that was used
        if ($callback instanceof LoopbackCallbackHandler) {
            $redirectUri = $callback->getActualRedirectUri();
        }

        // Exchange code for tokens
        $tokenParams = array_merge(
            [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $pkce['verifier'],
                // RFC8707: Include resource in token request
                'resource' => $resourceMetadata->resource,
            ],
            $credentials->getTokenRequestParams()
        );

        $tokenResponse = $this->executeTokenRequest(
            $asMetadata->tokenEndpoint,
            $tokenParams,
            $credentials
        );

        return TokenSet::fromTokenResponse(
            $tokenResponse,
            $resourceUrl,
            $asMetadata->issuer
        );
    }

    /**
     * Execute a token endpoint request.
     *
     * @param string $tokenEndpoint The token endpoint URL
     * @param array<string, string> $params Request parameters
     * @param ClientCredentials $credentials Client credentials
     * @return array<string, mixed> Token response
     */
    private function executeTokenRequest(
        string $tokenEndpoint,
        array $params,
        ClientCredentials $credentials
    ): array {
        $ch = curl_init($tokenEndpoint);
        if ($ch === false) {
            throw new OAuthException('Failed to initialize cURL for token request');
        }

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        // Add Basic auth header if needed
        $authHeader = $credentials->getAuthorizationHeader();
        if ($authHeader !== null) {
            $headers[] = "Authorization: {$authHeader}";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->config->getTimeout(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->config->isVerifyTlsEnabled(),
            CURLOPT_SSL_VERIFYHOST => $this->config->isVerifyTlsEnabled() ? 2 : 0,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new OAuthException("Token request failed: {$error}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OAuthException(
                'Invalid JSON response from token endpoint: ' . json_last_error_msg()
            );
        }

        // Check for error response
        if (isset($data['error'])) {
            throw OAuthException::fromOAuthError($data);
        }

        if ($httpCode !== 200) {
            throw new OAuthException("Token request failed with HTTP {$httpCode}");
        }

        if (!isset($data['access_token'])) {
            throw new OAuthException('Token response missing access_token');
        }

        $this->logger->info('Received access token');

        return $data;
    }
}
