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
 * Filename: Client/Auth/OAuthClientInterface.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth;

use Mcp\Client\Auth\Token\TokenSet;

/**
 * Interface for OAuth client operations.
 *
 * This interface defines the operations that the HTTP transport uses
 * to handle OAuth authentication flows.
 */
interface OAuthClientInterface
{
    /**
     * Handle a 401 Unauthorized response.
     *
     * This method is called when the server returns a 401 response,
     * indicating that authentication is required. It should perform
     * the OAuth authorization flow and return tokens.
     *
     * @param string $resourceUrl The URL of the protected resource
     * @param array<string, string|null> $wwwAuthHeader Parsed WWW-Authenticate header
     * @return TokenSet The obtained tokens
     * @throws OAuthException If authorization fails
     */
    public function handleUnauthorized(string $resourceUrl, array $wwwAuthHeader): TokenSet;

    /**
     * Handle a 403 Forbidden response with insufficient_scope error.
     *
     * This method is called when the server returns a 403 response
     * indicating that the current token lacks required scopes. It
     * should perform step-up authorization to obtain additional scopes.
     *
     * @param string $resourceUrl The URL of the protected resource
     * @param array<string, string|null> $wwwAuthHeader Parsed WWW-Authenticate header containing required scope
     * @param TokenSet $current The current token set
     * @return TokenSet New tokens with expanded scopes
     * @throws OAuthException If authorization fails
     */
    public function handleInsufficientScope(
        string $resourceUrl,
        array $wwwAuthHeader,
        TokenSet $current
    ): TokenSet;

    /**
     * Refresh an existing token.
     *
     * @param TokenSet $tokens The tokens containing a refresh token
     * @return TokenSet New tokens from the refresh
     * @throws OAuthException If refresh fails or no refresh token is available
     */
    public function refreshToken(TokenSet $tokens): TokenSet;

    /**
     * Get stored tokens for a resource URL.
     *
     * @param string $resourceUrl The URL of the protected resource
     * @return TokenSet|null The stored tokens, or null if not found
     */
    public function getTokens(string $resourceUrl): ?TokenSet;

    /**
     * Check if valid tokens exist for a resource URL.
     *
     * @param string $resourceUrl The URL of the protected resource
     * @return bool True if valid (non-expired) tokens exist
     */
    public function hasValidToken(string $resourceUrl): bool;
}
