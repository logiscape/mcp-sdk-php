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
 * Filename: Client/Auth/OAuthException.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth;

use RuntimeException;

/**
 * Exception thrown for OAuth-related errors.
 *
 * This exception is used to signal errors during the OAuth flow,
 * including discovery failures, token errors, and authorization failures.
 */
class OAuthException extends RuntimeException
{
    /**
     * OAuth error code (if available from the authorization server).
     */
    private ?string $oauthError;

    /**
     * OAuth error description (if available from the authorization server).
     */
    private ?string $oauthErrorDescription;

    /**
     * OAuth error URI (if available from the authorization server).
     */
    private ?string $oauthErrorUri;

    /**
     * Create a new OAuthException.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous Previous exception for chaining
     * @param string|null $oauthError OAuth error code
     * @param string|null $oauthErrorDescription OAuth error description
     * @param string|null $oauthErrorUri OAuth error URI
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $oauthError = null,
        ?string $oauthErrorDescription = null,
        ?string $oauthErrorUri = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->oauthError = $oauthError;
        $this->oauthErrorDescription = $oauthErrorDescription;
        $this->oauthErrorUri = $oauthErrorUri;
    }

    /**
     * Get the OAuth error code.
     *
     * @return string|null
     */
    public function getOAuthError(): ?string
    {
        return $this->oauthError;
    }

    /**
     * Get the OAuth error description.
     *
     * @return string|null
     */
    public function getOAuthErrorDescription(): ?string
    {
        return $this->oauthErrorDescription;
    }

    /**
     * Get the OAuth error URI.
     *
     * @return string|null
     */
    public function getOAuthErrorUri(): ?string
    {
        return $this->oauthErrorUri;
    }

    /**
     * Create an exception from an OAuth error response.
     *
     * @param array<string, mixed> $errorData The error data from the authorization server
     * @return self
     */
    public static function fromOAuthError(array $errorData): self
    {
        $error = $errorData['error'] ?? 'unknown_error';
        $description = $errorData['error_description'] ?? null;
        $uri = $errorData['error_uri'] ?? null;

        $message = "OAuth error: {$error}";
        if ($description !== null) {
            $message .= " - {$description}";
        }

        return new self(
            $message,
            0,
            null,
            $error,
            $description,
            $uri
        );
    }

    /**
     * Create an exception for discovery failure.
     *
     * @param string $url The URL that failed to load
     * @param string $reason The reason for the failure
     * @return self
     */
    public static function discoveryFailed(string $url, string $reason): self
    {
        return new self("OAuth discovery failed for {$url}: {$reason}");
    }

    /**
     * Create an exception for missing PKCE support.
     *
     * @return self
     */
    public static function pkceNotSupported(): self
    {
        return new self(
            'Authorization server does not support PKCE with S256, which is required by MCP'
        );
    }

    /**
     * Create an exception for token refresh failure.
     *
     * @param string $reason The reason for the failure
     * @return self
     */
    public static function tokenRefreshFailed(string $reason): self
    {
        return new self("Token refresh failed: {$reason}");
    }

    /**
     * Create an exception for authorization failure.
     *
     * @param string $reason The reason for the failure
     * @return self
     */
    public static function authorizationFailed(string $reason): self
    {
        return new self("Authorization failed: {$reason}");
    }
}
