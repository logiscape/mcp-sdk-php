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
 * Filename: Client/Auth/AuthorizationRequest.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth;

/**
 * Value object that encapsulates all data needed to complete a web-based OAuth flow.
 *
 * This class is used to pass authorization request data between the initiation
 * and completion phases of the OAuth flow in web hosting environments where
 * the flow cannot be completed synchronously.
 */
class AuthorizationRequest
{
    /**
     * Create a new AuthorizationRequest.
     *
     * @param string $authorizationUrl The full URL to redirect the user to for authorization
     * @param string $state The state parameter for CSRF protection
     * @param string $codeVerifier The PKCE code verifier (kept secret, used in token exchange)
     * @param string $redirectUri The redirect URI where the authorization server will return
     * @param string $resourceUrl The original resource URL being accessed
     * @param string $resource The resource identifier (RFC 8707)
     * @param string $tokenEndpoint The token endpoint URL
     * @param string $issuer The authorization server issuer
     * @param string $clientId The OAuth client ID
     * @param string|null $clientSecret The OAuth client secret (if any)
     * @param string $tokenEndpointAuthMethod The authentication method for the token endpoint
     * @param string|null $resourceMetadataUrl Optional URL where resource metadata was found
     * @param bool|null $issParameterSupported Whether the authorization server advertised
     *        RFC 9207 authorization_response_iss_parameter_supported in its metadata. When
     *        true, the authorization response MUST carry an iss parameter that matches the
     *        issuer byte-for-byte (SEP-2468). Null when the advertisement is unknown.
     */
    public function __construct(
        public readonly string $authorizationUrl,
        public readonly string $state,
        public readonly string $codeVerifier,
        public readonly string $redirectUri,
        public readonly string $resourceUrl,
        public readonly string $resource,
        public readonly string $tokenEndpoint,
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly ?string $clientSecret,
        public readonly string $tokenEndpointAuthMethod,
        public readonly ?string $resourceMetadataUrl = null,
        public readonly ?bool $issParameterSupported = null
    ) {
    }

    /**
     * Convert to array for storage (e.g., in session).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authorizationUrl' => $this->authorizationUrl,
            'state' => $this->state,
            'codeVerifier' => $this->codeVerifier,
            'redirectUri' => $this->redirectUri,
            'resourceUrl' => $this->resourceUrl,
            'resource' => $this->resource,
            'tokenEndpoint' => $this->tokenEndpoint,
            'issuer' => $this->issuer,
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'tokenEndpointAuthMethod' => $this->tokenEndpointAuthMethod,
            'resourceMetadataUrl' => $this->resourceMetadataUrl,
            'issParameterSupported' => $this->issParameterSupported,
        ];
    }

    /**
     * Create from array (e.g., from session storage).
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws \InvalidArgumentException If required fields are missing
     */
    public static function fromArray(array $data): self
    {
        $required = [
            'authorizationUrl',
            'state',
            'codeVerifier',
            'redirectUri',
            'resourceUrl',
            'resource',
            'tokenEndpoint',
            'issuer',
            'clientId',
            'tokenEndpointAuthMethod',
        ];

        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        return new self(
            authorizationUrl: $data['authorizationUrl'],
            state: $data['state'],
            codeVerifier: $data['codeVerifier'],
            redirectUri: $data['redirectUri'],
            resourceUrl: $data['resourceUrl'],
            resource: $data['resource'],
            tokenEndpoint: $data['tokenEndpoint'],
            issuer: $data['issuer'],
            clientId: $data['clientId'],
            clientSecret: $data['clientSecret'] ?? null,
            tokenEndpointAuthMethod: $data['tokenEndpointAuthMethod'],
            resourceMetadataUrl: $data['resourceMetadataUrl'] ?? null,
            issParameterSupported: $data['issParameterSupported'] ?? null
        );
    }
}
