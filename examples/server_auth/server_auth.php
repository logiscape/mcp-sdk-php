<?php

/**
 * Example MCP HTTP Server That Requires Authentication
 *
 * An OAuth 2.1-protected MCP server built on the McpServer convenience
 * wrapper, designed for standard PHP hosting environments (cPanel/Apache).
 * withAuth() enables bearer-token validation on every MCP request and serves
 * the RFC 9728 protected-resource metadata at
 * /.well-known/oauth-protected-resource (see README.md for the .htaccess
 * rules that route it here and forward the Authorization header).
 *
 * Configuration (issuer, resource id, JWT algorithm/secret/JWKS) is loaded
 * from mcp-config.php.
 *
 * (c) 2025 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 */

// Autoload dependencies — deployment layout first (vendor/ uploaded next to
// this script, per README.md), falling back to the repository checkout so the
// example also runs from the repo root:
//   php -S localhost:8000 examples/server_auth/server_auth.php
$autoload = file_exists(__DIR__ . '/vendor/autoload.php')
    ? __DIR__ . '/vendor/autoload.php'
    : __DIR__ . '/../../vendor/autoload.php';
require $autoload;

use Mcp\Server\Auth\JwtTokenValidator;
use Mcp\Server\McpServer;
use Mcp\Server\Transport\Http\Environment;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

// Only serve MCP traffic on the expected endpoints. The well-known paths are
// answered by the SDK with the OAuth protected-resource metadata; everything
// else on this vhost gets a plain 404.
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$allowedPaths = [
    '/server_auth.php',
    '/.well-known/oauth-protected-resource',
    '/.well-known/oauth-protected-resource/server_auth.php'
];

if (!in_array($requestUri, $allowedPaths)) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Include auth configuration file
require_once __DIR__ . '/mcp-config.php';

// Create JWT validator based on algorithm
if (MCP_JWT_ALGORITHM === 'RS256') {
    // RS256 with JWKS (Auth0, Okta, Keycloak, etc.)
    $tokenValidator = new JwtTokenValidator(
        key: '',  // Not used for JWKS-based validation
        algorithm: 'RS256',
        issuer: MCP_AUTH_ISSUER,
        audience: MCP_RESOURCE_ID,
        jwksUri: MCP_JWKS_URI
    );
} else {
    // HS256 with shared secret (pairs with generate-token.php for testing)
    $tokenValidator = new JwtTokenValidator(
        key: MCP_JWT_SECRET,
        algorithm: 'HS256',
        issuer: MCP_AUTH_ISSUER,
        audience: MCP_RESOURCE_ID
    );
}

try {
    $server = new McpServer('mcp-example-auth-server');

    $server
        // Tools — input schemas are generated from the callback signatures.
        ->tool('add-numbers', 'Adds two numbers together', function (float $num1, float $num2): string {
            return "The sum of {$num1} and {$num2} is " . ($num1 + $num2);
        })
        ->tool('uppercase', 'Converts text to uppercase', function (string $text): CallToolResult {
            if (trim($text) === '') {
                return new CallToolResult(
                    content: [new TextContent(text: 'Error: Text cannot be empty')],
                    isError: true
                );
            }
            return new CallToolResult(
                content: [new TextContent(text: 'Uppercase version: ' . strtoupper($text))]
            );
        })

        // A prompt with one required argument.
        ->prompt('example-prompt', 'An example prompt template', function (string $arg1): string {
            return "Example prompt text with argument: {$arg1}";
        })

        // Resources
        ->resource(
            uri: 'example://greeting',
            name: 'Greeting Text',
            callback: fn (): string => 'Hello from the example MCP HTTP server!',
            description: 'A simple greeting message'
        )
        ->resource(
            uri: 'example://server-info',
            name: 'Server Information',
            callback: fn (): string => implode("\n", [
                'Server Time: ' . date('Y-m-d H:i:s'),
                'PHP Version: ' . PHP_VERSION,
                'Server Software: ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'),
                'Environment: ' . (Environment::isSharedHosting() ? 'Shared Hosting' : 'Standard Server'),
                'Max Execution Time: ' . (Environment::detectMaxExecutionTime() ?: 'No limit') . ' seconds',
            ]),
            description: 'Information about the server environment'
        )

        // Require a valid bearer token on every MCP request and advertise the
        // authorization server in the protected-resource metadata.
        ->withAuth($tokenValidator, MCP_AUTH_ISSUER, MCP_RESOURCE_ID)

        // Conservative HTTP options for shared hosting. To enable DNS-rebinding
        // protection on a production host, also set
        // 'allowed_origins' => ['yourdomain.com'].
        ->httpOptions([
            'session_timeout' => 1800, // Legacy-session lifetime: 30 minutes
            'enable_sse' => false,     // Plain JSON responses for compatibility
            'shared_hosting' => true,  // Assume shared hosting for max compatibility
        ])

        // Legacy-era clients get file-backed sessions next to this script;
        // 2026-07-28 clients are served statelessly and never touch the store.
        ->sessionStore(new FileSessionStore(__DIR__ . '/mcp_sessions'))

        // Handle the current HTTP request.
        ->runHttp();
} catch (\Exception $e) {
    // Log error to error_log
    error_log('MCP Server error: ' . $e->getMessage());

    // Return error response
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => getenv('MCP_DEBUG') === 'true' ? $e->getMessage() : 'An error occurred'
    ]);
}
