<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * Developed by:
 * - Josh Abbott
 * - Claude Opus 4.6 (Anthropic AI model)
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
 * Filename: Client/Auth/Callback/HeadlessCallbackHandler.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Callback;

use Mcp\Client\Auth\OAuthException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OAuth callback handler for headless/automated environments.
 *
 * Instead of opening a browser and listening on a loopback socket, this handler
 * makes an HTTP GET request to the authorization URL and captures the redirect
 * response. This is designed for authorization servers that auto-approve (e.g.,
 * conformance test mock servers) where no user interaction is needed.
 *
 * The handler:
 * 1. Makes an HTTP GET to the authorization URL without following redirects
 * 2. Captures the Location header from the 302 redirect
 * 3. Extracts the authorization code and state from the redirect URL
 * 4. Validates the state parameter
 * 5. Returns the authorization code
 */
class HeadlessCallbackHandler implements AuthorizationCallbackInterface
{
    private LoggerInterface $logger;
    private string $redirectUri;
    private float $timeout;
    private bool $verifyTls;

    /**
     * @param string $redirectUri The redirect URI to register with the AS
     * @param float $timeout HTTP request timeout in seconds
     * @param bool $verifyTls Whether to verify TLS certificates
     * @param LoggerInterface|null $logger PSR-3 logger
     */
    public function __construct(
        string $redirectUri = 'http://127.0.0.1/callback',
        float $timeout = 30.0,
        bool $verifyTls = true,
        ?LoggerInterface $logger = null
    ) {
        $this->redirectUri = $redirectUri;
        $this->timeout = $timeout;
        $this->verifyTls = $verifyTls;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function authorize(string $authUrl, string $state): string
    {
        $this->logger->info('Headless authorization: requesting authorization URL', [
            'authUrl' => $authUrl,
        ]);

        $ch = curl_init($authUrl);
        if ($ch === false) {
            throw new OAuthException('Failed to initialize cURL for authorization request');
        }

        // Do NOT follow redirects - we want to capture the Location header
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => (int) $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);

        // Capture response headers
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $name = trim(strtolower($parts[0]));
                $value = trim($parts[1]);
                $responseHeaders[$name] = $value;
            }
            return $length;
        });

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new OAuthException("Authorization request failed: {$error}");
        }

        $this->logger->debug("Authorization response: HTTP {$httpCode}", [
            'headers' => $responseHeaders,
        ]);

        // Expect a 302 redirect
        if ($httpCode !== 302 && $httpCode !== 303 && $httpCode !== 307) {
            throw new OAuthException(
                "Expected redirect from authorization endpoint, got HTTP {$httpCode}"
            );
        }

        $location = $responseHeaders['location'] ?? null;
        if ($location === null) {
            throw new OAuthException('No Location header in authorization redirect');
        }

        $this->logger->info('Captured authorization redirect', ['location' => $location]);

        // Parse the redirect URL to extract code and state
        $parts = parse_url($location);
        $queryString = $parts['query'] ?? '';
        parse_str($queryString, $params);

        // Verify the redirect targets our configured redirect URI.
        // Strip the query string from both sides before comparing so that
        // the AS-appended code/state params don't cause a mismatch.
        $expectedParts = parse_url($this->redirectUri);
        if (
            ($parts['scheme'] ?? '') !== ($expectedParts['scheme'] ?? '') ||
            ($parts['host'] ?? '') !== ($expectedParts['host'] ?? '') ||
            ($parts['port'] ?? null) !== ($expectedParts['port'] ?? null) ||
            rtrim($parts['path'] ?? '/', '/') !== rtrim($expectedParts['path'] ?? '/', '/')
        ) {
            $actual = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '')
                . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . ($parts['path'] ?? '/');
            throw OAuthException::authorizationFailed(
                "Authorization server redirected to unexpected URI: {$actual} "
                . "(expected: {$this->redirectUri})"
            );
        }

        // Check for error response
        if (isset($params['error'])) {
            $errorDesc = $params['error_description'] ?? $params['error'];
            throw new OAuthException("Authorization error: {$errorDesc}");
        }

        // Validate state
        if (!isset($params['state']) || $params['state'] !== $state) {
            throw OAuthException::authorizationFailed(
                'State parameter mismatch in authorization redirect'
            );
        }

        // Extract code
        if (!isset($params['code'])) {
            throw OAuthException::authorizationFailed(
                'No authorization code in redirect URL'
            );
        }

        $this->logger->info('Received authorization code via headless flow');

        return $params['code'];
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }
}
