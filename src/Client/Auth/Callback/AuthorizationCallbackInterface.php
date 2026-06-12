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
 * Filename: Client/Auth/Callback/AuthorizationCallbackInterface.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Callback;

use Mcp\Client\Auth\OAuthException;

/**
 * Interface for handling OAuth authorization callbacks.
 *
 * Implementations handle user interaction during the OAuth authorization flow,
 * presenting the authorization URL to the user and receiving the callback.
 */
interface AuthorizationCallbackInterface
{
    /**
     * Perform the authorization flow.
     *
     * This method should:
     * 1. Present the authorization URL to the user (browser, CLI prompt, etc.)
     * 2. Wait for the user to complete authorization
     * 3. Receive the callback with the authorization response parameters
     * 4. Return the result
     *
     * Implementations SHOULD return an AuthorizationCallbackResult carrying the
     * raw callback parameters (including the RFC 9207 iss parameter and any
     * error parameters) WITHOUT interpreting error parameters themselves — the
     * OAuthClient validates the iss parameter against the expected issuer
     * before acting on either the code or the error content (SEP-2468).
     *
     * Returning a plain authorization code string is still supported for
     * backward compatibility; it is treated as a callback that carried no iss
     * parameter and no error.
     *
     * @param string $authUrl The complete authorization URL
     * @param string $state The state parameter to validate in the callback
     * @return string|AuthorizationCallbackResult The callback result, or the
     *         bare authorization code (legacy)
     * @throws OAuthException If authorization fails or is cancelled
     */
    public function authorize(string $authUrl, string $state): string|AuthorizationCallbackResult;

    /**
     * Get the redirect URI for this callback handler.
     *
     * @return string The redirect URI to use in authorization requests
     */
    public function getRedirectUri(): string;
}
