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
 * Filename: Client/Auth/Discovery/MetadataDiscovery.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Discovery;

use Mcp\Client\Auth\OAuthException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for discovering OAuth metadata.
 *
 * Implements RFC9728 for Protected Resource Metadata and
 * RFC8414/OpenID Connect for Authorization Server Metadata.
 *
 * Includes security validations per RFC 8414:
 * - Issuer validation: returned issuer must match requested issuer URL
 * - Resource validation: returned resource must match or be a prefix of requested URL
 * - Secure redirect handling: blocks cross-host and HTTPS-to-HTTP redirects
 */
class MetadataDiscovery
{
    private LoggerInterface $logger;
    private float $timeout;
    private bool $verifyTls;

    /**
     * Maximum number of redirects to follow.
     */
    private const MAX_REDIRECTS = 5;

    /**
     * @param float $timeout HTTP request timeout in seconds
     * @param bool $verifyTls Whether to verify TLS certificates
     * @param LoggerInterface|null $logger PSR-3 logger
     */
    public function __construct(
        float $timeout = 30.0,
        bool $verifyTls = true,
        ?LoggerInterface $logger = null
    ) {
        $this->timeout = $timeout;
        $this->verifyTls = $verifyTls;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Discover Protected Resource Metadata per RFC9728.
     *
     * @param string $resourceUrl The protected resource URL
     * @param string|null $metadataUrl Optional metadata URL from WWW-Authenticate header
     * @return ProtectedResourceMetadata The discovered metadata
     * @throws OAuthException If discovery fails
     */
    public function discoverResourceMetadata(
        string $resourceUrl,
        ?string $metadataUrl = null
    ): ProtectedResourceMetadata {
        $this->logger->debug("Discovering protected resource metadata for: {$resourceUrl}");

        // Try URLs in order of preference
        $urlsToTry = $this->getResourceMetadataUrls($resourceUrl, $metadataUrl);

        $lastError = null;
        foreach ($urlsToTry as $url) {
            try {
                $this->logger->debug("Trying resource metadata URL: {$url}");
                $data = $this->fetchJson($url);

                if ($this->isValidResourceMetadata($data)) {
                    // Validate resource identifier matches per RFC 9728
                    if (!$this->validateResource($data['resource'], $resourceUrl)) {
                        $this->logger->warning("Resource mismatch: metadata resource {$data['resource']} does not match requested URL {$resourceUrl}");
                        throw OAuthException::discoveryFailed($resourceUrl, 'Resource mismatch in metadata');
                    }
                    $this->logger->info("Found protected resource metadata at: {$url}");
                    return ProtectedResourceMetadata::fromArray($data);
                }
            } catch (\Exception $e) {
                $this->logger->debug("Failed to fetch from {$url}: {$e->getMessage()}");
                $lastError = $e;
            }
        }

        throw OAuthException::discoveryFailed(
            $resourceUrl,
            $lastError?->getMessage() ?? 'No valid metadata found at any location'
        );
    }

    /**
     * Discover Authorization Server Metadata per RFC8414 and OpenID Connect.
     *
     * @param string $issuerUrl The authorization server issuer URL
     * @return AuthorizationServerMetadata The discovered metadata
     * @throws OAuthException If discovery fails or PKCE is not supported
     */
    public function discoverAuthorizationServerMetadata(
        string $issuerUrl
    ): AuthorizationServerMetadata {
        $this->logger->debug("Discovering authorization server metadata for: {$issuerUrl}");

        $urlsToTry = $this->getAuthServerMetadataUrls($issuerUrl);

        $lastError = null;
        foreach ($urlsToTry as $url) {
            try {
                $this->logger->debug("Trying AS metadata URL: {$url}");
                $data = $this->fetchJson($url);

                if ($this->isValidAuthServerMetadata($data)) {
                    // Validate issuer matches per RFC 8414 Section 3
                    if (!$this->validateIssuer($data['issuer'], $issuerUrl)) {
                        $this->logger->warning("Issuer mismatch: expected {$issuerUrl}, got {$data['issuer']}");
                        throw OAuthException::discoveryFailed($issuerUrl, 'Issuer mismatch in metadata');
                    }

                    $metadata = AuthorizationServerMetadata::fromArray($data);

                    // MCP MUST verify PKCE support
                    if (!$metadata->supportsPkce()) {
                        throw OAuthException::pkceNotSupported();
                    }

                    $this->logger->info("Found authorization server metadata at: {$url}");
                    return $metadata;
                }
            } catch (OAuthException $e) {
                // Re-throw OAuth exceptions (like PKCE not supported)
                throw $e;
            } catch (\Exception $e) {
                $this->logger->debug("Failed to fetch from {$url}: {$e->getMessage()}");
                $lastError = $e;
            }
        }

        throw OAuthException::discoveryFailed(
            $issuerUrl,
            $lastError?->getMessage() ?? 'No valid metadata found at any location'
        );
    }

    /**
     * Get the list of URLs to try for Protected Resource Metadata.
     *
     * @param string $resourceUrl The protected resource URL
     * @param string|null $metadataUrl Optional metadata URL from header
     * @return array<int, string> List of URLs to try
     */
    private function getResourceMetadataUrls(string $resourceUrl, ?string $metadataUrl): array
    {
        $urls = [];

        // Priority 1: Explicit metadata URL from WWW-Authenticate header
        if ($metadataUrl !== null) {
            $urls[] = $metadataUrl;
        }

        $parsed = parse_url($resourceUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';
        $path = $parsed['path'] ?? '';

        $origin = "{$scheme}://{$host}{$port}";

        // Priority 2: Path-aware well-known location
        // /.well-known/oauth-protected-resource/{path}
        if ($path !== '' && $path !== '/') {
            $pathWithoutLeadingSlash = ltrim($path, '/');
            $urls[] = "{$origin}/.well-known/oauth-protected-resource/{$pathWithoutLeadingSlash}";
        }

        // Priority 3: Root well-known location
        // /.well-known/oauth-protected-resource
        $urls[] = "{$origin}/.well-known/oauth-protected-resource";

        return $urls;
    }

    /**
     * Get the list of URLs to try for Authorization Server Metadata.
     *
     * @param string $issuerUrl The authorization server issuer URL
     * @return array<int, string> List of URLs to try
     */
    private function getAuthServerMetadataUrls(string $issuerUrl): array
    {
        $urls = [];

        $parsed = parse_url($issuerUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';
        $path = $parsed['path'] ?? '';

        $origin = "{$scheme}://{$host}{$port}";

        // Check if there's a path component
        $hasPath = $path !== '' && $path !== '/';

        if ($hasPath) {
            $pathWithoutLeadingSlash = ltrim($path, '/');

            // RFC8414 path-aware: /.well-known/oauth-authorization-server/{path}
            $urls[] = "{$origin}/.well-known/oauth-authorization-server/{$pathWithoutLeadingSlash}";

            // OIDC path-aware: /.well-known/openid-configuration/{path}
            $urls[] = "{$origin}/.well-known/openid-configuration/{$pathWithoutLeadingSlash}";

            // OIDC suffix: {path}/.well-known/openid-configuration
            $urls[] = "{$origin}/{$pathWithoutLeadingSlash}/.well-known/openid-configuration";
        } else {
            // RFC8414: /.well-known/oauth-authorization-server
            $urls[] = "{$origin}/.well-known/oauth-authorization-server";

            // OIDC: /.well-known/openid-configuration
            $urls[] = "{$origin}/.well-known/openid-configuration";
        }

        return $urls;
    }

    /**
     * Fetch JSON from a URL with secure redirect handling.
     *
     * Implements manual redirect following to prevent security issues:
     * - Blocks cross-host redirects
     * - Blocks HTTPS-to-HTTP downgrades
     *
     * @param string $url The URL to fetch
     * @return array<string, mixed> The decoded JSON
     * @throws \RuntimeException If fetch fails
     */
    private function fetchJson(string $url): array
    {
        $currentUrl = $url;
        $redirectCount = 0;
        $originalParsed = parse_url($url);
        $originalHost = $originalParsed['host'] ?? '';
        $originalScheme = $originalParsed['scheme'] ?? 'https';

        while ($redirectCount < self::MAX_REDIRECTS) {
            $ch = curl_init($currentUrl);
            if ($ch === false) {
                throw new \RuntimeException('Failed to initialize cURL');
            }

            // Disable automatic redirect following for security
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => (int) $this->timeout,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
                CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
                CURLOPT_HEADER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException("HTTP request failed: {$error}");
            }

            // Check for redirect
            if ($httpCode >= 300 && $httpCode < 400) {
                $headers = substr($response, 0, $headerSize);
                $location = $this->extractLocationHeader($headers);

                if ($location === null) {
                    throw new \RuntimeException("Redirect without Location header");
                }

                // Validate redirect security
                $this->validateRedirect($currentUrl, $location, $originalHost, $originalScheme);

                // Resolve relative URLs against current URL
                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                $redirectCount++;
                continue;
            }

            // Extract body
            $body = substr($response, $headerSize);

            if ($httpCode !== 200) {
                throw new \RuntimeException("HTTP {$httpCode} response");
            }

            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response: ' . json_last_error_msg());
            }

            return $data;
        }

        throw new \RuntimeException('Too many redirects');
    }

    /**
     * Extract Location header from response headers.
     *
     * @param string $headers Raw headers string
     * @return string|null The Location header value or null
     */
    private function extractLocationHeader(string $headers): ?string
    {
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Resolve a redirect URL against the current URL.
     *
     * Handles both absolute URLs and relative URLs (including path-relative
     * and absolute-path references) per RFC 3986.
     *
     * @param string $baseUrl The current URL being redirected from
     * @param string $relativeUrl The Location header value (may be relative or absolute)
     * @return string The resolved absolute URL
     */
    private function resolveRedirectUrl(string $baseUrl, string $relativeUrl): string
    {
        // If already absolute, return as-is
        if (preg_match('/^https?:\/\//i', $relativeUrl)) {
            return $relativeUrl;
        }

        $baseParsed = parse_url($baseUrl);
        $scheme = $baseParsed['scheme'] ?? 'https';
        $host = $baseParsed['host'] ?? '';
        $port = isset($baseParsed['port']) ? ":{$baseParsed['port']}" : '';

        // Handle absolute path (starts with /)
        if (str_starts_with($relativeUrl, '/')) {
            return "{$scheme}://{$host}{$port}{$relativeUrl}";
        }

        // Handle relative path
        $basePath = $baseParsed['path'] ?? '/';
        $baseDir = dirname($basePath);
        if ($baseDir === '\\' || $baseDir === '.') {
            $baseDir = '/';
        }
        return "{$scheme}://{$host}{$port}" . rtrim($baseDir, '/') . '/' . $relativeUrl;
    }

    /**
     * Validate that a redirect is secure.
     *
     * @param string $fromUrl The URL being redirected from
     * @param string $toUrl The URL being redirected to
     * @param string $originalHost The original request host
     * @param string $originalScheme The original request scheme
     * @throws \RuntimeException If redirect is not secure
     */
    private function validateRedirect(
        string $fromUrl,
        string $toUrl,
        string $originalHost,
        string $originalScheme
    ): void {
        $toParsed = parse_url($toUrl);
        $toHost = $toParsed['host'] ?? '';
        $toScheme = $toParsed['scheme'] ?? '';

        // Block cross-host redirects
        if ($toHost !== '' && strtolower($toHost) !== strtolower($originalHost)) {
            $this->logger->warning("Blocked cross-host redirect from {$fromUrl} to {$toUrl}");
            throw new \RuntimeException("Cross-host redirect not allowed for OAuth metadata discovery");
        }

        // Block HTTPS-to-HTTP downgrades
        if ($originalScheme === 'https' && $toScheme === 'http') {
            $this->logger->warning("Blocked HTTPS-to-HTTP downgrade redirect from {$fromUrl} to {$toUrl}");
            throw new \RuntimeException("HTTPS-to-HTTP downgrade not allowed for OAuth metadata discovery");
        }
    }

    /**
     * Validate that the returned issuer matches the requested issuer per RFC 8414.
     *
     * @param string $returnedIssuer The issuer returned in metadata
     * @param string $requestedIssuer The issuer URL that was requested
     * @return bool True if valid
     */
    private function validateIssuer(string $returnedIssuer, string $requestedIssuer): bool
    {
        return $this->normalizeUrl($returnedIssuer) === $this->normalizeUrl($requestedIssuer);
    }

    /**
     * Validate that the returned resource matches or is a prefix of the requested URL.
     *
     * @param string $returnedResource The resource returned in metadata
     * @param string $requestedUrl The URL that was requested
     * @return bool True if valid
     */
    private function validateResource(string $returnedResource, string $requestedUrl): bool
    {
        $returned = $this->normalizeUrl($returnedResource);
        $requested = $this->normalizeUrl($requestedUrl);

        // Resource should be equal to or a prefix of the requested URL
        if ($requested === $returned) {
            return true;
        }

        // Check if requested URL starts with the resource (with proper path boundary)
        $returnedWithSlash = rtrim($returned, '/') . '/';
        return str_starts_with($requested, $returnedWithSlash);
    }

    /**
     * Normalize a URL for comparison.
     *
     * - Lowercases scheme and host
     * - Removes default ports (80 for HTTP, 443 for HTTPS)
     * - Removes trailing slash from path
     *
     * @param string $url The URL to normalize
     * @return string The normalized URL
     */
    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host'] ?? '');
        $port = $parsed['port'] ?? null;
        $path = $parsed['path'] ?? '/';

        // Remove default ports
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        // Build normalized URL
        $normalized = "{$scheme}://{$host}";
        if ($port !== null) {
            $normalized .= ":{$port}";
        }
        $normalized .= rtrim($path, '/');

        return $normalized;
    }

    /**
     * Validate that data looks like valid Protected Resource Metadata.
     *
     * @param array<string, mixed> $data The metadata to validate
     * @return bool True if valid
     */
    private function isValidResourceMetadata(array $data): bool
    {
        // Must have 'resource' field
        if (!isset($data['resource'])) {
            return false;
        }

        // Should have authorization_servers (empty array is technically valid)
        if (isset($data['authorization_servers']) && !is_array($data['authorization_servers'])) {
            return false;
        }

        return true;
    }

    /**
     * Validate that data looks like valid Authorization Server Metadata.
     *
     * @param array<string, mixed> $data The metadata to validate
     * @return bool True if valid
     */
    private function isValidAuthServerMetadata(array $data): bool
    {
        // Must have issuer, authorization_endpoint, and token_endpoint
        return isset($data['issuer'])
            && isset($data['authorization_endpoint'])
            && isset($data['token_endpoint']);
    }

    /**
     * Parse the WWW-Authenticate header to extract resource_metadata URL.
     *
     * @param string $header The WWW-Authenticate header value
     * @return array<string, string|null> Parsed header with 'resource_metadata' if present
     */
    public static function parseWwwAuthenticate(string $header): array
    {
        $result = [
            'scheme' => null,
            'realm' => null,
            'resource_metadata' => null,
            'scope' => null,
            'error' => null,
            'error_description' => null,
        ];

        // Extract scheme (Bearer, etc.)
        if (preg_match('/^(\w+)\s+/', $header, $matches)) {
            $result['scheme'] = $matches[1];
            $header = substr($header, strlen($matches[0]));
        }

        // Parse key="value" pairs
        $pattern = '/(\w+)=(?:"([^"]*)"|([\w.-]+))/';
        if (preg_match_all($pattern, $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
