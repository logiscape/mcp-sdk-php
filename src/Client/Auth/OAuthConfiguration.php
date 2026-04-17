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
 * Filename: Client/Auth/OAuthConfiguration.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth;

use Mcp\Client\Auth\Callback\AuthorizationCallbackInterface;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Token\MemoryTokenStorage;
use Mcp\Client\Auth\Token\TokenStorageInterface;

/**
 * Configuration for OAuth client.
 *
 * This class holds all configuration options for the OAuth client,
 * including client credentials, token storage, and behavior settings.
 */
class OAuthConfiguration
{
    private TokenStorageInterface $tokenStorage;

    /**
     * Create OAuth configuration.
     *
     * @param ClientCredentials|null $clientCredentials Pre-registered client credentials
     * @param TokenStorageInterface|null $tokenStorage Token persistence storage.
     *        NOTE: Default MemoryTokenStorage only persists tokens for the PHP
     *        process lifetime. For web applications, use FileTokenStorage instead.
     * @param AuthorizationCallbackInterface|null $authCallback Handler for user authorization
     * @param bool $enableCimd Enable Client ID Metadata Document support
     * @param bool $enableDynamicRegistration Enable Dynamic Client Registration
     * @param string|null $cimdUrl URL for Client ID Metadata Document
     * @param array<int, string> $additionalScopes Extra scopes to request
     * @param float $timeout HTTP timeout for OAuth requests (seconds)
     * @param bool $autoRefresh Automatically refresh expiring tokens
     * @param int $refreshBuffer Seconds before expiry to trigger refresh
     * @param string|null $redirectUri Override redirect URI (default: from callback handler)
     * @param bool $verifyTls Whether to verify TLS certificates (default: true for security)
     * @param string|null $authorizationServerUrl Pre-configured authorization server URL, used as
     *        fallback when RFC 9728 resource metadata discovery fails (e.g., for servers that don't
     *        publish protected resource metadata)
     * @param bool $enableLegacyOAuthFallback Enable MCP 2025-03-26 OAuth backwards-compatibility
     *        fallback. When true and resource metadata discovery fails, the AS base URL is derived
     *        from the MCP server URL (path discarded). When true and AS metadata discovery also
     *        fails, the client synthesizes an AS metadata document pointing at the legacy default
     *        endpoints (/authorize, /token, /register) at the server root. Default false. MCP
     *        2025-06-18+ requires RFC 9728 PRM, so this flag MUST remain false for 2025-06-18+
     *        servers.
     */
    public function __construct(
        private ?ClientCredentials $clientCredentials = null,
        ?TokenStorageInterface $tokenStorage = null,
        private ?AuthorizationCallbackInterface $authCallback = null,
        private bool $enableCimd = true,
        private bool $enableDynamicRegistration = true,
        private ?string $cimdUrl = null,
        private array $additionalScopes = [],
        private float $timeout = 30.0,
        private bool $autoRefresh = true,
        private int $refreshBuffer = 60,
        private ?string $redirectUri = null,
        private bool $verifyTls = true,
        private ?string $authorizationServerUrl = null,
        private bool $enableLegacyOAuthFallback = false,
    ) {
        if ($tokenStorage === null) {
            // Warn about MemoryTokenStorage in web contexts
            if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
                trigger_error(
                    'OAuthConfiguration: MemoryTokenStorage in web context will lose tokens between requests. ' .
                    'Use FileTokenStorage for web applications.',
                    E_USER_NOTICE
                );
            }
            $this->tokenStorage = new MemoryTokenStorage();
        } else {
            $this->tokenStorage = $tokenStorage;
        }
    }

    /**
     * Get pre-registered client credentials.
     */
    public function getClientCredentials(): ?ClientCredentials
    {
        return $this->clientCredentials;
    }

    /**
     * Get token storage.
     */
    public function getTokenStorage(): TokenStorageInterface
    {
        return $this->tokenStorage;
    }

    /**
     * Get authorization callback handler.
     */
    public function getAuthCallback(): ?AuthorizationCallbackInterface
    {
        return $this->authCallback;
    }

    /**
     * Check if CIMD is enabled.
     */
    public function isCimdEnabled(): bool
    {
        return $this->enableCimd;
    }

    /**
     * Check if dynamic client registration is enabled.
     */
    public function isDynamicRegistrationEnabled(): bool
    {
        return $this->enableDynamicRegistration;
    }

    /**
     * Get CIMD URL.
     */
    public function getCimdUrl(): ?string
    {
        return $this->cimdUrl;
    }

    /**
     * Get additional scopes to request.
     *
     * @return array<int, string>
     */
    public function getAdditionalScopes(): array
    {
        return $this->additionalScopes;
    }

    /**
     * Get HTTP timeout.
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * Check if auto-refresh is enabled.
     */
    public function isAutoRefreshEnabled(): bool
    {
        return $this->autoRefresh;
    }

    /**
     * Get refresh buffer time in seconds.
     */
    public function getRefreshBuffer(): int
    {
        return $this->refreshBuffer;
    }

    /**
     * Get override redirect URI.
     */
    public function getRedirectUri(): ?string
    {
        return $this->redirectUri;
    }

    /**
     * Check if the configuration has client credentials from any source.
     */
    public function hasClientCredentials(): bool
    {
        return $this->clientCredentials !== null;
    }

    /**
     * Check if CIMD is configured.
     */
    public function hasCimd(): bool
    {
        return $this->enableCimd && $this->cimdUrl !== null;
    }

    /**
     * Check if TLS verification is enabled.
     */
    public function isVerifyTlsEnabled(): bool
    {
        return $this->verifyTls;
    }

    /**
     * Get pre-configured authorization server URL.
     */
    public function getAuthorizationServerUrl(): ?string
    {
        return $this->authorizationServerUrl;
    }

    /**
     * Check if a pre-configured authorization server is available.
     */
    public function hasAuthorizationServer(): bool
    {
        return $this->authorizationServerUrl !== null;
    }

    /**
     * Check if the MCP 2025-03-26 legacy OAuth fallback is enabled.
     */
    public function isLegacyOAuthFallbackEnabled(): bool
    {
        return $this->enableLegacyOAuthFallback;
    }
}
