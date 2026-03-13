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
 * Filename: Client/Auth/Discovery/AuthorizationServerMetadata.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Discovery;

/**
 * RFC8414/OpenID Connect Authorization Server Metadata.
 *
 * This class represents the metadata for an OAuth 2.0 authorization server,
 * including endpoints and supported features.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414
 * @see https://openid.net/specs/openid-connect-discovery-1_0.html
 */
class AuthorizationServerMetadata
{
    /**
     * PKCE challenge method required by MCP.
     */
    public const REQUIRED_PKCE_METHOD = 'S256';

    /**
     * @param string $issuer The authorization server issuer
     * @param string $authorizationEndpoint The authorization endpoint URL
     * @param string $tokenEndpoint The token endpoint URL
     * @param string|null $registrationEndpoint Dynamic client registration endpoint
     * @param array<int, string> $codeChallengeMethodsSupported Supported PKCE code challenge methods
     * @param bool $clientIdMetadataDocumentSupported Whether CIMD is supported
     * @param array<int, string> $responseTypesSupported Supported OAuth response types
     * @param array<int, string> $grantTypesSupported Supported OAuth grant types
     * @param array<int, string> $tokenEndpointAuthMethodsSupported Supported token endpoint auth methods
     * @param array<int, string>|null $scopesSupported Supported scopes
     * @param string|null $revocationEndpoint Token revocation endpoint
     * @param string|null $introspectionEndpoint Token introspection endpoint
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly ?string $registrationEndpoint = null,
        public readonly array $codeChallengeMethodsSupported = [],
        public readonly bool $clientIdMetadataDocumentSupported = false,
        public readonly array $responseTypesSupported = ['code'],
        public readonly array $grantTypesSupported = ['authorization_code'],
        public readonly array $tokenEndpointAuthMethodsSupported = ['client_secret_post'],
        public readonly ?array $scopesSupported = null,
        public readonly ?string $revocationEndpoint = null,
        public readonly ?string $introspectionEndpoint = null
    ) {
    }

    /**
     * Check if PKCE with S256 is supported.
     *
     * MCP requires PKCE with S256 code challenge method.
     *
     * @return bool True if S256 PKCE is supported
     */
    public function supportsPkce(): bool
    {
        return in_array(self::REQUIRED_PKCE_METHOD, $this->codeChallengeMethodsSupported, true);
    }

    /**
     * Check if Client ID Metadata Document is supported.
     *
     * @return bool True if CIMD is supported
     */
    public function supportsCimd(): bool
    {
        return $this->clientIdMetadataDocumentSupported;
    }

    /**
     * Check if Dynamic Client Registration is supported.
     *
     * @return bool True if DCR endpoint is available
     */
    public function supportsDynamicRegistration(): bool
    {
        return $this->registrationEndpoint !== null;
    }

    /**
     * Check if a specific grant type is supported.
     *
     * @param string $grantType The grant type to check
     * @return bool True if the grant type is supported
     */
    public function supportsGrantType(string $grantType): bool
    {
        return in_array($grantType, $this->grantTypesSupported, true);
    }

    /**
     * Check if refresh tokens are supported.
     *
     * @return bool True if refresh_token grant is supported
     */
    public function supportsRefreshToken(): bool
    {
        return $this->supportsGrantType('refresh_token');
    }

    /**
     * Check if a specific token endpoint auth method is supported.
     *
     * @param string $method The auth method to check
     * @return bool True if the method is supported
     */
    public function supportsTokenEndpointAuthMethod(string $method): bool
    {
        return in_array($method, $this->tokenEndpointAuthMethodsSupported, true);
    }

    /**
     * Create from an array (typically from JSON response).
     *
     * @param array<string, mixed> $data The metadata array
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            issuer: $data['issuer'],
            authorizationEndpoint: $data['authorization_endpoint'],
            tokenEndpoint: $data['token_endpoint'],
            registrationEndpoint: $data['registration_endpoint'] ?? null,
            codeChallengeMethodsSupported: $data['code_challenge_methods_supported'] ?? [],
            clientIdMetadataDocumentSupported: $data['client_id_metadata_document_supported'] ?? false,
            responseTypesSupported: $data['response_types_supported'] ?? ['code'],
            grantTypesSupported: $data['grant_types_supported'] ?? ['authorization_code'],
            tokenEndpointAuthMethodsSupported: $data['token_endpoint_auth_methods_supported'] ?? ['client_secret_post'],
            scopesSupported: $data['scopes_supported'] ?? null,
            revocationEndpoint: $data['revocation_endpoint'] ?? null,
            introspectionEndpoint: $data['introspection_endpoint'] ?? null
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'code_challenge_methods_supported' => $this->codeChallengeMethodsSupported,
            'client_id_metadata_document_supported' => $this->clientIdMetadataDocumentSupported,
            'response_types_supported' => $this->responseTypesSupported,
            'grant_types_supported' => $this->grantTypesSupported,
            'token_endpoint_auth_methods_supported' => $this->tokenEndpointAuthMethodsSupported,
        ];

        if ($this->registrationEndpoint !== null) {
            $data['registration_endpoint'] = $this->registrationEndpoint;
        }

        if ($this->scopesSupported !== null) {
            $data['scopes_supported'] = $this->scopesSupported;
        }

        if ($this->revocationEndpoint !== null) {
            $data['revocation_endpoint'] = $this->revocationEndpoint;
        }

        if ($this->introspectionEndpoint !== null) {
            $data['introspection_endpoint'] = $this->introspectionEndpoint;
        }

        return $data;
    }
}
