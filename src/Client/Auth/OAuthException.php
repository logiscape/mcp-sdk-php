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
    public const REASON_DISCOVERY_UNAVAILABLE = 'discovery_unavailable';
    public const REASON_DISCOVERY_VALIDATION_FAILED = 'discovery_validation_failed';
    public const REASON_PKCE_NOT_SUPPORTED = 'pkce_not_supported';

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
     * SDK-specific reason code for branching on protocol failures without
     * parsing human-readable exception messages.
     */
    private ?string $reasonCode;

    /**
     * Create a new OAuthException.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous Previous exception for chaining
     * @param string|null $oauthError OAuth error code
     * @param string|null $oauthErrorDescription OAuth error description
     * @param string|null $oauthErrorUri OAuth error URI
     * @param string|null $reasonCode SDK-specific reason code
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $oauthError = null,
        ?string $oauthErrorDescription = null,
        ?string $oauthErrorUri = null,
        ?string $reasonCode = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->oauthError = $oauthError;
        $this->oauthErrorDescription = $oauthErrorDescription;
        $this->oauthErrorUri = $oauthErrorUri;
        $this->reasonCode = $reasonCode;
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
     * Get the SDK-specific reason code.
     */
    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    /**
     * Check whether this error means metadata could not be discovered.
     */
    public function isDiscoveryUnavailable(): bool
    {
        return $this->reasonCode === self::REASON_DISCOVERY_UNAVAILABLE;
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
        return new self(
            "OAuth discovery failed for {$url}: {$reason}",
            reasonCode: self::REASON_DISCOVERY_UNAVAILABLE
        );
    }

    /**
     * Create an exception for metadata that was found but failed validation.
     *
     * @param string $url The URL associated with the invalid metadata
     * @param string $reason The validation failure reason
     * @return self
     */
    public static function discoveryValidationFailed(string $url, string $reason): self
    {
        return new self(
            "OAuth discovery validation failed for {$url}: {$reason}",
            reasonCode: self::REASON_DISCOVERY_VALIDATION_FAILED
        );
    }

    /**
     * Create an exception for missing PKCE support.
     *
     * @return self
     */
    public static function pkceNotSupported(): self
    {
        return new self(
            'Authorization server does not support PKCE with S256, which is required by MCP',
            reasonCode: self::REASON_PKCE_NOT_SUPPORTED
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
