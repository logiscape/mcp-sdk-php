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
 * Filename: Client/Auth/Callback/LoopbackCallbackHandler.php
 */

declare(strict_types=1);

namespace Mcp\Client\Auth\Callback;

use Mcp\Client\Auth\OAuthException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OAuth callback handler using a local loopback server.
 *
 * Opens a temporary HTTP server on 127.0.0.1 to receive the OAuth callback.
 * Suitable for CLI applications. Opens the authorization URL in the default
 * browser or prints it for manual navigation.
 *
 * IMPORTANT: This handler is designed for CLI applications ONLY.
 *
 * This handler is NOT compatible with web hosting environments (Apache, nginx, cPanel)
 * because it requires:
 * - PHP socket permissions (socket_create, socket_bind, socket_listen)
 * - Long-running processes that block waiting for OAuth callback
 * - Direct console output for user interaction
 *
 * For web hosting environments, use the asynchronous OAuth flow instead:
 *
 * 1. Call OAuthClient::initiateWebAuthorization() to get an AuthorizationRequest
 *    containing the authorization URL and all data needed for token exchange.
 *
 * 2. Redirect the user's browser to the authorization URL.
 *
 * 3. In your OAuth callback endpoint, call OAuthClient::exchangeCodeForTokens()
 *    with the AuthorizationRequest data and the authorization code.
 *
 * See the webclient/ directory for a complete reference implementation of the
 * web-based OAuth flow, including session handling and token storage.
 */
class LoopbackCallbackHandler implements AuthorizationCallbackInterface
{
    private LoggerInterface $logger;
    private int $port;
    private string $host;
    private int $timeout;
    private bool $openBrowser;

    /**
     * The actual port used after the server starts (for auto-port mode).
     */
    private ?int $lastUsedPort = null;

    /**
     * @param int $port The port to listen on (default: auto-select)
     * @param int $timeout Timeout in seconds for waiting for callback
     * @param bool $openBrowser Whether to attempt to open the browser automatically
     * @param LoggerInterface|null $logger PSR-3 logger
     */
    public function __construct(
        int $port = 0,
        int $timeout = 120,
        bool $openBrowser = true,
        ?LoggerInterface $logger = null
    ) {
        $this->port = $port;
        $this->host = '127.0.0.1';
        $this->timeout = $timeout;
        $this->openBrowser = $openBrowser;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritdoc}
     */
    public function authorize(string $authUrl, string $state): AuthorizationCallbackResult
    {
        // Create socket server
        $socket = $this->createServer();
        $actualPort = $this->getSocketPort($socket);

        // Store the actual port for later retrieval
        $this->lastUsedPort = $actualPort;

        $this->logger->info("Started callback server on {$this->host}:{$actualPort}");

        // Replace {PORT} placeholder with actual port if present
        $authUrl = str_replace('{PORT}', (string) $actualPort, $authUrl);

        try {
            // Present authorization URL to user
            $this->presentAuthorizationUrl($authUrl);

            // Wait for callback
            return $this->waitForCallback($socket, $state);
        } finally {
            socket_close($socket);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUri(): string
    {
        // If we have a last used port, use it
        if ($this->lastUsedPort !== null) {
            return "http://{$this->host}:{$this->lastUsedPort}/callback";
        }

        // If port is auto-selected and not yet determined, return placeholder
        if ($this->port === 0) {
            return "http://{$this->host}:{PORT}/callback";
        }

        return "http://{$this->host}:{$this->port}/callback";
    }

    /**
     * Get the redirect URI with the actual port after authorization.
     *
     * Call this after authorize() has been invoked to get the actual
     * redirect URI that was used. This is needed for the token request.
     *
     * @return string The redirect URI with the actual port
     * @throws OAuthException If called before authorize()
     */
    public function getActualRedirectUri(): string
    {
        if ($this->lastUsedPort === null) {
            if ($this->port === 0) {
                throw new OAuthException(
                    'Cannot get actual redirect URI before authorize() is called'
                );
            }
            return "http://{$this->host}:{$this->port}/callback";
        }

        return "http://{$this->host}:{$this->lastUsedPort}/callback";
    }

    /**
     * Get the last used port (after authorize() was called).
     *
     * @return int|null The port number, or null if not yet determined
     */
    public function getLastUsedPort(): ?int
    {
        return $this->lastUsedPort;
    }

    /**
     * Create the server socket.
     *
     * @return \Socket
     * @throws OAuthException If socket creation fails
     */
    private function createServer()
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new OAuthException(
                'Failed to create socket: ' . socket_strerror(socket_last_error())
            );
        }

        // Allow socket reuse
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        // Bind to the host and port
        if (!@socket_bind($socket, $this->host, $this->port)) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new OAuthException("Failed to bind socket: {$error}");
        }

        // Start listening
        if (!@socket_listen($socket, 1)) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new OAuthException("Failed to listen on socket: {$error}");
        }

        return $socket;
    }

    /**
     * Get the actual port the socket is bound to.
     *
     * @param \Socket $socket The socket
     * @return int The port number
     */
    private function getSocketPort($socket): int
    {
        if (!socket_getsockname($socket, $addr, $port)) {
            throw new OAuthException('Failed to get socket port');
        }
        return $port;
    }

    /**
     * Present the authorization URL to the user.
     *
     * @param string $authUrl The authorization URL
     */
    private function presentAuthorizationUrl(string $authUrl): void
    {
        echo "\n";
        echo "=== OAuth Authorization Required ===\n";
        echo "\n";

        $browserOpened = false;

        if ($this->openBrowser) {
            $browserOpened = $this->openBrowser($authUrl);
        }

        if ($browserOpened) {
            echo "A browser window should have opened for authorization.\n";
            echo "If not, please open this URL manually:\n";
        } else {
            echo "Please open the following URL in your browser:\n";
        }

        echo "\n";
        echo $authUrl . "\n";
        echo "\n";
        echo "Waiting for authorization...\n";
        echo "\n";
    }

    /**
     * Attempt to open the URL in the default browser.
     *
     * @param string $url The URL to open
     * @return bool True if the browser was opened
     */
    private function openBrowser(string $url): bool
    {
        // Escape the URL for shell commands
        $escapedUrl = escapeshellarg($url);

        // Try different methods based on OS
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows
            $result = pclose(popen("start {$escapedUrl}", 'r'));
            return $result !== false;
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // macOS
            exec("open {$escapedUrl} > /dev/null 2>&1 &", $output, $result);
            return $result === 0;
        } else {
            // Linux and others
            exec("xdg-open {$escapedUrl} > /dev/null 2>&1 &", $output, $result);
            return $result === 0;
        }
    }

    /**
     * Wait for the OAuth callback.
     *
     * @param \Socket $socket The server socket
     * @param string $expectedState The expected state parameter
     * @return AuthorizationCallbackResult The raw callback result
     * @throws OAuthException If callback fails or times out
     */
    private function waitForCallback($socket, string $expectedState): AuthorizationCallbackResult
    {
        // Set socket timeout
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => $this->timeout,
            'usec' => 0,
        ]);

        // Accept connection
        $client = @socket_accept($socket);
        if ($client === false) {
            throw OAuthException::authorizationFailed(
                'Timed out waiting for authorization callback'
            );
        }

        try {
            // Read the request
            $request = '';
            while (($data = @socket_read($client, 1024)) !== false && $data !== '') {
                $request .= $data;
                // Stop reading when we have the full HTTP request header
                if (strpos($request, "\r\n\r\n") !== false) {
                    break;
                }
            }

            // Parse the request to get the raw callback parameters
            $result = $this->parseCallback($request, $expectedState);

            // Send response page (failure page when the AS returned an error)
            $this->sendResponse($client, !$result->hasError() && $result->code !== null);

            return $result;
        } finally {
            socket_close($client);
        }
    }

    /**
     * Parse the callback request.
     *
     * Deliberately does NOT interpret error parameters or require a code:
     * per SEP-2468 the iss parameter must be validated against the expected
     * issuer BEFORE acting on any error or code content, and only the
     * OAuthClient knows the expected issuer. The raw parameters are returned
     * for the OAuthClient to validate and dispatch.
     *
     * @param string $request The HTTP request
     * @param string $expectedState The expected state parameter
     * @return AuthorizationCallbackResult The raw callback result
     * @throws OAuthException If parsing fails or state doesn't match
     */
    private function parseCallback(string $request, string $expectedState): AuthorizationCallbackResult
    {
        // Parse the request line
        if (!preg_match('/^GET\s+([^\s]+)\s+HTTP/', $request, $matches)) {
            throw OAuthException::authorizationFailed('Invalid callback request');
        }

        $uri = $matches[1];
        $parts = parse_url($uri);
        $queryString = $parts['query'] ?? '';

        parse_str($queryString, $params);

        // Validate state (CSRF protection). This is independent of the
        // error/iss handling deferred to the OAuthClient: a response that
        // doesn't carry our state is not a response to our request at all.
        if (!isset($params['state']) || $params['state'] !== $expectedState) {
            throw OAuthException::authorizationFailed(
                'State parameter mismatch - possible CSRF attack'
            );
        }

        $this->logger->info('Received authorization callback');

        return new AuthorizationCallbackResult(
            code: isset($params['code']) && is_string($params['code']) ? $params['code'] : null,
            iss: isset($params['iss']) && is_string($params['iss']) ? $params['iss'] : null,
            params: $params
        );
    }

    /**
     * Send HTTP response to the browser.
     *
     * @param \Socket $client The client socket
     * @param bool $success Whether authorization was successful
     */
    private function sendResponse($client, bool $success): void
    {
        if ($success) {
            $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Authorization Successful</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: #f5f5f5;
        }
        .container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #2e7d32; margin-bottom: 16px; }
        p { color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Authorization Successful</h1>
        <p>You can close this window and return to the application.</p>
    </div>
</body>
</html>
HTML;
        } else {
            $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Authorization Failed</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: #f5f5f5;
        }
        .container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #c62828; margin-bottom: 16px; }
        p { color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Authorization Failed</h1>
        <p>Please check the application for error details.</p>
    </div>
</body>
</html>
HTML;
        }

        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: text/html; charset=utf-8\r\n";
        $response .= "Content-Length: " . strlen($body) . "\r\n";
        $response .= "Connection: close\r\n";
        $response .= "\r\n";
        $response .= $body;

        socket_write($client, $response, strlen($response));
    }
}
