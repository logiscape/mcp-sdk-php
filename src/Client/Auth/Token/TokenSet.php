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
 * Filename: Client/Auth/Token/TokenSet.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Token;

/**
 * Container for OAuth tokens.
 *
 * Holds the access token, optional refresh token, and metadata about
 * token expiration, type, and associated scopes.
 */
class TokenSet
{
    /**
     * @param string $accessToken The access token
     * @param string|null $refreshToken The refresh token (if available)
     * @param int|null $expiresAt Unix timestamp when the token expires
     * @param string $tokenType The token type (typically 'Bearer')
     * @param array<int, string> $scope The granted scopes
     * @param string|null $resourceUrl The protected resource URL this token is for
     * @param string|null $issuer The authorization server issuer URL
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?int $expiresAt = null,
        public readonly string $tokenType = 'Bearer',
        public readonly array $scope = [],
        public readonly ?string $resourceUrl = null,
        public readonly ?string $issuer = null
    ) {
    }

    /**
     * Check if the token has expired.
     *
     * @return bool True if the token has expired, false otherwise
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return time() >= $this->expiresAt;
    }

    /**
     * Check if the token will expire soon.
     *
     * @param int $buffer Number of seconds before expiry to consider "soon"
     * @return bool True if the token will expire within the buffer period
     */
    public function willExpireSoon(int $buffer = 60): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return time() >= ($this->expiresAt - $buffer);
    }

    /**
     * Check if this token set can be refreshed.
     *
     * @return bool True if a refresh token is available
     */
    public function canRefresh(): bool
    {
        return $this->refreshToken !== null;
    }

    /**
     * Check if the token has a specific scope.
     *
     * @param string $requiredScope The scope to check for
     * @return bool True if the scope is present
     */
    public function hasScope(string $requiredScope): bool
    {
        return in_array($requiredScope, $this->scope, true);
    }

    /**
     * Check if the token has all required scopes.
     *
     * @param array<int, string> $requiredScopes The scopes to check for
     * @return bool True if all scopes are present
     */
    public function hasAllScopes(array $requiredScopes): bool
    {
        foreach ($requiredScopes as $scope) {
            if (!$this->hasScope($scope)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the Authorization header value.
     *
     * @return string The header value (e.g., "Bearer token123")
     */
    public function getAuthorizationHeader(): string
    {
        return "{$this->tokenType} {$this->accessToken}";
    }

    /**
     * Create a TokenSet from a token endpoint response.
     *
     * @param array<string, mixed> $response The token endpoint response
     * @param string|null $resourceUrl The protected resource URL
     * @param string|null $issuer The authorization server issuer
     * @param array<int, string> $originalScope Original scopes to preserve on refresh per RFC 6749 Section 6.
     *        If the response doesn't include a scope, the original scopes are preserved.
     * @return self
     */
    public static function fromTokenResponse(
        array $response,
        ?string $resourceUrl = null,
        ?string $issuer = null,
        array $originalScope = []
    ): self {
        $expiresAt = null;
        if (isset($response['expires_in'])) {
            $expiresAt = time() + (int) $response['expires_in'];
        }

        $scope = [];
        if (isset($response['scope'])) {
            $scope = explode(' ', $response['scope']);
        } elseif (!empty($originalScope)) {
            // RFC 6749 Section 6: If the scope is omitted in the refresh response,
            // the originally granted scopes should be preserved
            $scope = $originalScope;
        }

        return new self(
            accessToken: $response['access_token'],
            refreshToken: $response['refresh_token'] ?? null,
            expiresAt: $expiresAt,
            tokenType: $response['token_type'] ?? 'Bearer',
            scope: $scope,
            resourceUrl: $resourceUrl,
            issuer: $issuer
        );
    }

    /**
     * Convert the token set to an array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'token_type' => $this->tokenType,
            'scope' => $this->scope,
            'resource_url' => $this->resourceUrl,
            'issuer' => $this->issuer,
        ];
    }

    /**
     * Create a TokenSet from a stored array.
     *
     * @param array<string, mixed> $data The stored data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: $data['access_token'],
            refreshToken: $data['refresh_token'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            tokenType: $data['token_type'] ?? 'Bearer',
            scope: $data['scope'] ?? [],
            resourceUrl: $data['resource_url'] ?? null,
            issuer: $data['issuer'] ?? null
        );
    }
}
