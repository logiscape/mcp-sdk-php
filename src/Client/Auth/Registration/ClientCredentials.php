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
 * Filename: Client/Auth/Registration/ClientCredentials.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Registration;

/**
 * Pre-registered OAuth client credentials.
 *
 * Use this class when the client has been pre-registered with the
 * authorization server and has known credentials.
 */
class ClientCredentials
{
    /**
     * Token endpoint authentication methods.
     */
    public const AUTH_METHOD_NONE = 'none';
    public const AUTH_METHOD_CLIENT_SECRET_POST = 'client_secret_post';
    public const AUTH_METHOD_CLIENT_SECRET_BASIC = 'client_secret_basic';

    /**
     * Sentinel value: discover the auth method from AS metadata at runtime.
     *
     * Use this when the caller has credentials but does not know which auth
     * method the authorization server supports. The OAuthClient will resolve
     * it from the AS's token_endpoint_auth_methods_supported field.
     */
    public const AUTH_METHOD_AUTO = 'auto';

    /**
     * @param string $clientId The client identifier
     * @param string|null $clientSecret The client secret (if applicable)
     * @param string $tokenEndpointAuthMethod The authentication method for the token endpoint
     */
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $clientSecret = null,
        public readonly string $tokenEndpointAuthMethod = self::AUTH_METHOD_CLIENT_SECRET_POST
    ) {
    }

    /**
     * Check if this client is a public client (no secret).
     *
     * @return bool True if this is a public client
     */
    public function isPublicClient(): bool
    {
        return $this->clientSecret === null ||
               $this->tokenEndpointAuthMethod === self::AUTH_METHOD_NONE;
    }

    /**
     * Get the parameters to add to a token request for authentication.
     *
     * @return array<string, string> The authentication parameters
     */
    public function getTokenRequestParams(): array
    {
        $params = ['client_id' => $this->clientId];

        if ($this->tokenEndpointAuthMethod === self::AUTH_METHOD_CLIENT_SECRET_POST
            && $this->clientSecret !== null
        ) {
            $params['client_secret'] = $this->clientSecret;
        }

        return $params;
    }

    /**
     * Get the Authorization header for token requests (for client_secret_basic).
     *
     * @return string|null The Authorization header value, or null if not using basic auth
     */
    public function getAuthorizationHeader(): ?string
    {
        if ($this->tokenEndpointAuthMethod !== self::AUTH_METHOD_CLIENT_SECRET_BASIC
            || $this->clientSecret === null
        ) {
            return null;
        }

        $credentials = base64_encode(
            urlencode($this->clientId) . ':' . urlencode($this->clientSecret)
        );

        return "Basic {$credentials}";
    }
}
