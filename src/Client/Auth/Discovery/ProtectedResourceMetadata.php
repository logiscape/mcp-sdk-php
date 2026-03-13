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
 * Filename: Client/Auth/Discovery/ProtectedResourceMetadata.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Discovery;

/**
 * RFC9728 Protected Resource Metadata.
 *
 * This class represents the metadata for an OAuth-protected resource,
 * including the authorization servers that can be used to obtain tokens.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728
 */
class ProtectedResourceMetadata
{
    /**
     * @param string $resource The protected resource identifier
     * @param array<int, string> $authorizationServers List of authorization server URLs
     * @param array<int, string>|null $scopesSupported Supported scopes (null means unknown)
     * @param array<int, string>|null $bearerMethodsSupported Supported bearer token methods
     * @param string|null $resourceDocumentation URL of documentation
     * @param string|null $resourceSigningAlgValuesSupported Signing algorithms supported
     */
    public function __construct(
        public readonly string $resource,
        public readonly array $authorizationServers,
        public readonly ?array $scopesSupported = null,
        public readonly ?array $bearerMethodsSupported = null,
        public readonly ?string $resourceDocumentation = null,
        public readonly ?string $resourceSigningAlgValuesSupported = null
    ) {
    }

    /**
     * Get the primary (first) authorization server URL.
     *
     * @return string|null The primary authorization server URL, or null if none
     */
    public function getPrimaryAuthorizationServer(): ?string
    {
        return $this->authorizationServers[0] ?? null;
    }

    /**
     * Check if specific scopes are supported.
     *
     * If scopes_supported is not defined, returns true (unknown means allow).
     *
     * @param array<int, string> $scopes The scopes to check
     * @return bool True if all scopes are supported or unknown
     */
    public function supportsScopes(array $scopes): bool
    {
        if ($this->scopesSupported === null) {
            return true;
        }

        foreach ($scopes as $scope) {
            if (!in_array($scope, $this->scopesSupported, true)) {
                return false;
            }
        }

        return true;
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
            resource: $data['resource'],
            authorizationServers: $data['authorization_servers'] ?? [],
            scopesSupported: $data['scopes_supported'] ?? null,
            bearerMethodsSupported: $data['bearer_methods_supported'] ?? null,
            resourceDocumentation: $data['resource_documentation'] ?? null,
            resourceSigningAlgValuesSupported: $data['resource_signing_alg_values_supported'] ?? null
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
            'resource' => $this->resource,
            'authorization_servers' => $this->authorizationServers,
        ];

        if ($this->scopesSupported !== null) {
            $data['scopes_supported'] = $this->scopesSupported;
        }

        if ($this->bearerMethodsSupported !== null) {
            $data['bearer_methods_supported'] = $this->bearerMethodsSupported;
        }

        if ($this->resourceDocumentation !== null) {
            $data['resource_documentation'] = $this->resourceDocumentation;
        }

        if ($this->resourceSigningAlgValuesSupported !== null) {
            $data['resource_signing_alg_values_supported'] = $this->resourceSigningAlgValuesSupported;
        }

        return $data;
    }
}
