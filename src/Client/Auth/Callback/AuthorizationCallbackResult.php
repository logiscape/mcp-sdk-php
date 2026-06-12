<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude Fable 5 (Anthropic AI model)
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
 * Filename: Client/Auth/Callback/AuthorizationCallbackResult.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Callback;

/**
 * Result of an OAuth authorization callback.
 *
 * Carries the raw outcome of the authorization response so the OAuthClient
 * can validate the RFC 9207 / SEP-2468 iss parameter BEFORE acting on either
 * the authorization code or any error/error_description/error_uri parameters.
 *
 * Callback handlers should populate this object from the redirect parameters
 * without interpreting error parameters themselves: a response whose iss does
 * not match the expected issuer must be rejected without surfacing its error
 * content, which only the OAuthClient (which knows the validated issuer for
 * the request) can decide.
 */
class AuthorizationCallbackResult
{
    /**
     * @param string|null $code The authorization code, if present in the callback
     * @param string|null $iss The RFC 9207 iss parameter, if present in the callback
     *        (form-urldecoded, exactly as parsed from the query string)
     * @param array<string, mixed> $params All raw callback query parameters
     *        (form-urldecoded), including error/error_description/error_uri if present
     */
    public function __construct(
        public readonly ?string $code = null,
        public readonly ?string $iss = null,
        public readonly array $params = []
    ) {
    }

    /**
     * Check whether the callback carried an OAuth error response.
     *
     * @return bool True if an error parameter is present
     */
    public function hasError(): bool
    {
        return isset($this->params['error']);
    }
}
