# Building MCP Servers in PHP

A comprehensive guide to developing Model Context Protocol servers using the `logiscape/mcp-sdk-php` SDK.

---

## Table of Contents

- [Introduction](#introduction)
- [Getting Started](#getting-started)
- [Part 1: Tools](#part-1-tools)
- [Part 2: Prompts](#part-2-prompts)
- [Part 3: Resources](#part-3-resources)
- [Part 4: Deploying Remote MCP Servers](#part-4-deploying-remote-mcp-servers)
- [Part 5: Securing Remote Servers with OAuth](#part-5-securing-remote-servers-with-oauth)
- [Part 6: Structured Output](#part-6-structured-output)
- [Part 7: Returning Rich Content](#part-7-returning-rich-content)
- [Part 8: Multi-Capability Servers](#part-8-multi-capability-servers)
- [Appendix A: Configuration Reference](#appendix-a-configuration-reference)
- [Appendix B: Deployment Checklist](#appendix-b-deployment-checklist)

---

## Introduction

The [Model Context Protocol](https://modelcontextprotocol.io) (MCP) is an open standard that enables AI applications to interact with external data sources and tools through a uniform interface. MCP servers expose three core primitives:

- **Tools** -- Functions the AI model can invoke to perform actions (model-controlled)
- **Prompts** -- Reusable message templates the user can select (user-controlled)
- **Resources** -- Data that provides context to the model (application-controlled)

The `logiscape/mcp-sdk-php` SDK implements the MCP specification (including the latest 2025-11-25 revision) for PHP 8.1+. It provides a `McpServer` convenience wrapper that lets you build a fully functional MCP server in just a few lines of code. The same server file can run locally via stdio or remotely over HTTP -- making it deployable to standard cPanel/Apache hosting with zero infrastructure changes.

### What You Can Build

- A local MCP server that Claude Desktop, Cursor, or any MCP client launches as a subprocess
- A remote MCP server hosted on your web hosting that any MCP client connects to over HTTPS
- A dual-mode server that works both ways from a single PHP file

---

## Getting Started

### Requirements

- PHP 8.1 or higher
- Composer
- `ext-curl` and `ext-json` (typically enabled by default)
- For local/stdio servers: CLI access
- For remote/HTTP servers: Apache with `mod_rewrite` (standard on cPanel hosting)

### Installation

```bash
composer require logiscape/mcp-sdk-php
```

### Your First MCP Server

```php
<?php
// server.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('my-first-server');
$server
    ->tool('hello', 'Say hello to someone', function (string $name): string {
        return "Hello, {$name}! Welcome to MCP.";
    })
    ->run();
```

The `run()` method detects the environment automatically:
- **CLI** (`php server.php`) -- uses the stdio transport for local MCP connections
- **Web server** (accessed via HTTP) -- uses the HTTP transport for remote MCP connections

You can also force a specific transport:
- `runStdio()` -- always use stdio
- `runHttp()` -- always use HTTP

### Connecting from Claude Desktop (Local)

Add to your Claude Desktop `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "my-server": {
      "command": "php",
      "args": ["/absolute/path/to/server.php"]
    }
  }
}
```

### Connecting from an MCP Client (Remote)

Once deployed to a web server (covered in [Part 4](#part-4-deploying-remote-mcp-servers)), the MCP endpoint URL is simply the URL to your PHP file:

```
https://yoursite.com/mcp-server.php
```

---

## Part 1: Tools

Tools are the most powerful MCP primitive. They let an AI model take action -- call an API, query a database, transform data, or interact with any system your PHP code can reach. The model discovers available tools, decides when to use them, and invokes them with the appropriate arguments.

### How Tools Work

1. The MCP client asks your server for its list of tools (`tools/list`)
2. The AI model sees each tool's name, description, and parameter schema
3. When the model decides to use a tool, the client sends a `tools/call` request
4. Your server executes the callback and returns the result

### Basic Tool

```php
<?php
// tools_basic.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('basic-tools');

// A tool that converts temperature units.
// The SDK uses reflection to automatically build the JSON Schema
// from the callback's parameter types.
$server->tool(
    'convert-temperature',
    'Convert a temperature between Celsius and Fahrenheit',
    function (float $value, string $unit): string {
        $unit = strtolower($unit);

        if ($unit === 'c' || $unit === 'celsius') {
            $result = ($value * 9 / 5) + 32;
            return "{$value}C = {$result}F";
        }

        if ($unit === 'f' || $unit === 'fahrenheit') {
            $result = ($value - 32) * 5 / 9;
            return "{$value}F = {$result}C";
        }

        return "Unknown unit '{$unit}'. Use 'C' or 'F'.";
    }
);

$server->run();
```

The SDK inspects the callback with PHP reflection:
- `float $value` becomes `{ "type": "number" }` in the JSON Schema
- `string $unit` becomes `{ "type": "string" }`
- Required vs. optional is determined by whether the parameter has a default value

### Tool with Optional Parameters

```php
<?php
// tools_optional.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('optional-params');

// Parameters with default values become optional in the schema.
$server->tool(
    'search-products',
    'Search a product catalog by keyword',
    function (string $query, int $limit = 10, string $sort = 'relevance'): string {
        // In a real server, this would query a database.
        $results = [
            ['name' => 'Widget A', 'price' => 9.99],
            ['name' => 'Widget B', 'price' => 14.99],
            ['name' => 'Gadget C', 'price' => 24.99],
        ];

        $output = "Results for '{$query}' (limit: {$limit}, sort: {$sort}):\n";
        foreach (array_slice($results, 0, $limit) as $i => $product) {
            $output .= ($i + 1) . ". {$product['name']} - \${$product['price']}\n";
        }

        return $output;
    }
);

$server->run();
```

In this example `$query` is required, while `$limit` and `$sort` are optional and will use their default values if the model doesn't supply them.

### Tool with Error Handling

When an exception is thrown inside a tool callback, the SDK catches it and returns the error message to the model with `isError: true`. This lets the model self-correct rather than crashing the server.

```php
<?php
// tools_errors.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('error-handling');

$server->tool(
    'divide',
    'Divide one number by another',
    function (float $numerator, float $denominator): string {
        if ($denominator == 0) {
            // Throwing an exception inside a tool callback returns the
            // error to the model as a tool execution error (isError: true).
            // This is intentional -- it lets the model understand what
            // went wrong and try a different approach.
            throw new \InvalidArgumentException(
                'Division by zero is not allowed. Please provide a non-zero denominator.'
            );
        }

        $result = $numerator / $denominator;
        return "{$numerator} / {$denominator} = {$result}";
    }
);

$server->run();
```

### Multiple Tools

Chain as many tools as you need. Each `->tool()` call returns the server instance for fluent chaining.

```php
<?php
// tools_multiple.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('text-utilities');

$server
    ->tool('word-count', 'Count the words in a text', function (string $text): string {
        $count = str_word_count($text);
        return "The text contains {$count} word(s).";
    })
    ->tool('slugify', 'Convert text to a URL-friendly slug', function (string $text): string {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    })
    ->tool('extract-emails', 'Extract email addresses from text', function (string $text): string {
        preg_match_all('/[\w.\-]+@[\w.\-]+\.\w+/', $text, $matches);
        $emails = $matches[0];

        if (empty($emails)) {
            return 'No email addresses found.';
        }

        return "Found " . count($emails) . " email(s):\n" . implode("\n", $emails);
    })
    ->run();
```

---

## Part 2: Prompts

Prompts are reusable message templates that a user can select in their MCP client. Unlike tools (which the model calls autonomously), prompts are user-initiated -- they appear as slash commands or in a prompt library UI. Prompts are ideal for standardizing common interactions: code review templates, analysis frameworks, report formats, etc.

### How Prompts Work

1. The MCP client fetches available prompts (`prompts/list`)
2. The user selects a prompt (e.g., via a slash command)
3. The client sends `prompts/get` with the user's arguments
4. Your server returns one or more messages that seed the conversation

### Basic Prompt

```php
<?php
// prompts_basic.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('basic-prompts');

// A prompt that generates a code review request.
// Like tools, arguments are auto-generated from the callback's parameters.
$server->prompt(
    'code-review',
    'Generate a structured code review request',
    function (string $language, string $code): string {
        return <<<PROMPT
        Please review the following {$language} code. Analyze it for:

        1. **Correctness** -- Are there any bugs or logic errors?
        2. **Security** -- Are there any vulnerabilities (injection, XSS, etc.)?
        3. **Performance** -- Are there any inefficiencies?
        4. **Readability** -- Is the code clean and well-structured?
        5. **Best Practices** -- Does it follow {$language} conventions?

        Code to review:
        ```{$language}
        {$code}
        ```
        PROMPT;
    }
);

$server->run();
```

When the callback returns a string, the SDK wraps it as a single user-role message. The model then responds to it as if the user had typed that message.

### Prompt Returning Multiple Messages

Return an array of strings to create a multi-message conversation starter:

```php
<?php
// prompts_multi_message.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('multi-message-prompts');

// Returning an array produces multiple user messages.
$server->prompt(
    'debug-session',
    'Start a structured debugging session',
    function (string $error_message, string $context = 'web application'): array {
        return [
            "I'm encountering the following error in my {$context}:\n\n```\n{$error_message}\n```",
            "Please help me debug this step by step. Start by identifying the most likely root causes, then suggest specific diagnostic steps I can take.",
        ];
    }
);

$server->run();
```

### Advanced Prompt with Full Control

For full control over message roles and content types, return a `GetPromptResult` directly:

```php
<?php
// prompts_advanced.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Types\GetPromptResult;
use Mcp\Types\PromptMessage;
use Mcp\Types\TextContent;
use Mcp\Types\Role;

$server = new McpServer('advanced-prompts');

// Returning a GetPromptResult gives full control over the message structure,
// including the ability to mix user and assistant roles.
$server->prompt(
    'sql-assistant',
    'Start an interactive SQL query building session',
    function (string $table_name, string $database_type = 'MySQL'): GetPromptResult {
        return new GetPromptResult(
            description: "SQL assistant for {$database_type}",
            messages: [
                new PromptMessage(
                    role: Role::USER,
                    content: new TextContent(
                        text: "I need help writing a {$database_type} query for the '{$table_name}' table."
                    )
                ),
                new PromptMessage(
                    role: Role::ASSISTANT,
                    content: new TextContent(
                        text: "I'd be happy to help you write a {$database_type} query for the '{$table_name}' table. To write the best query, could you tell me:\n\n1. What columns does the table have?\n2. What do you want the query to do? (SELECT, INSERT, UPDATE, aggregate, join, etc.)\n3. Are there any specific conditions or filters?"
                    )
                ),
            ]
        );
    }
);

$server->run();
```

By including an assistant message, you prime the model to continue in a specific conversational style.

---

## Part 3: Resources

Resources expose data that provides context to the AI model. They are identified by URIs and can represent anything: files, database records, API responses, configuration, or live system data. Resources are typically loaded by the application (or user) rather than invoked by the model -- they're about providing information, not taking action.

### How Resources Work

1. The MCP client fetches available resources (`resources/list`)
2. The client or user selects resources to include as context
3. The client sends `resources/read` with the resource URI
4. Your server returns the content (text or binary)

### Basic Resource

```php
<?php
// resources_basic.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('basic-resources');

// A text resource. When the callback returns a string,
// the SDK wraps it as TextResourceContents.
$server->resource(
    uri: 'config://app-settings',
    name: 'Application Settings',
    description: 'Current application configuration',
    callback: function (): string {
        // In production, this might read from a config file or database
        return json_encode([
            'app_name' => 'My Application',
            'version' => '2.1.0',
            'environment' => 'production',
            'features' => [
                'dark_mode' => true,
                'notifications' => true,
                'beta_features' => false,
            ],
        ], JSON_PRETTY_PRINT);
    },
    mimeType: 'application/json'
);

$server->run();
```

### Multiple Resources

```php
<?php
// resources_multiple.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('multi-resources');

$server
    ->resource(
        uri: 'docs://api-reference',
        name: 'API Reference',
        description: 'REST API endpoint documentation',
        callback: function (): string {
            return <<<'DOC'
            ## API Endpoints

            ### GET /api/users
            Returns a paginated list of users.
            Parameters: page (int), per_page (int, max 100)

            ### GET /api/users/{id}
            Returns a single user by ID.

            ### POST /api/users
            Creates a new user.
            Body: { "name": string, "email": string }

            ### PUT /api/users/{id}
            Updates an existing user.
            Body: { "name"?: string, "email"?: string }

            ### DELETE /api/users/{id}
            Deletes a user. Requires admin role.
            DOC;
        },
        mimeType: 'text/markdown'
    )
    ->resource(
        uri: 'schema://users-table',
        name: 'Users Table Schema',
        description: 'Database schema for the users table',
        callback: function (): string {
            return <<<'SQL'
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                role ENUM('user', 'admin', 'moderator') DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_role (role)
            );
            SQL;
        },
        mimeType: 'text/plain'
    )
    ->resource(
        uri: 'info://server-status',
        name: 'Server Status',
        description: 'Live server health information',
        callback: function (): string {
            return json_encode([
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'uptime' => @file_get_contents('/proc/uptime') ?: 'N/A',
                'disk_free' => disk_free_space('.'),
                'timestamp' => date('c'),
            ], JSON_PRETTY_PRINT);
        },
        mimeType: 'application/json'
    )
    ->run();
```

### Binary Resource

When a callback returns an `SplFileObject` or a PHP stream resource, the SDK automatically base64-encodes it as a `BlobResourceContents`:

```php
<?php
// resources_binary.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('binary-resources');

// Serve a binary file (e.g., a logo image).
// Returning an SplFileObject triggers base64 encoding.
$server->resource(
    uri: 'file://company-logo',
    name: 'Company Logo',
    description: 'The company logo in PNG format',
    callback: function (): \SplFileObject {
        $path = __DIR__ . '/assets/logo.png';
        return new \SplFileObject($path, 'r');
    },
    mimeType: 'image/png'
);

// Serve a dynamically generated CSV using a PHP stream resource
$server->resource(
    uri: 'file://report-csv',
    name: 'Monthly Report CSV',
    description: 'Generated CSV export of monthly report data',
    callback: function () {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Date', 'Revenue', 'Orders']);
        fputcsv($stream, ['2025-11-01', '12500.00', '145']);
        fputcsv($stream, ['2025-11-02', '13200.00', '162']);
        fputcsv($stream, ['2025-11-03', '11800.00', '138']);
        rewind($stream);
        return $stream;
    },
    mimeType: 'text/csv'
);

$server->run();
```

### Advanced Resource with Full Control

For full control over the response, return a `ReadResourceResult` directly:

```php
<?php
// resources_advanced.php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\TextResourceContents;

$server = new McpServer('advanced-resources');

$server->resource(
    uri: 'multi://combined-context',
    name: 'Combined Context',
    description: 'Returns multiple content items in a single resource read',
    callback: function (): ReadResourceResult {
        return new ReadResourceResult(
            contents: [
                new TextResourceContents(
                    uri: 'multi://combined-context#schema',
                    text: 'CREATE TABLE orders (id INT PRIMARY KEY, total DECIMAL(10,2));',
                    mimeType: 'text/plain'
                ),
                new TextResourceContents(
                    uri: 'multi://combined-context#sample-data',
                    text: json_encode([
                        ['id' => 1, 'total' => 99.99],
                        ['id' => 2, 'total' => 149.50],
                    ]),
                    mimeType: 'application/json'
                ),
            ]
        );
    }
);

$server->run();
```

---

## Part 4: Deploying Remote MCP Servers

One of the great strengths of this PHP SDK is that remote MCP servers work on standard shared hosting -- the same cPanel/Apache environment that runs millions of PHP sites. No special server software, no long-running processes, no WebSockets.

### How It Works

The SDK's HTTP transport is designed for PHP's traditional request-response lifecycle:

1. The MCP client sends HTTP POST requests to your PHP file
2. Apache/PHP processes each request independently
3. Session state is persisted to files between requests
4. The SDK handles all JSON-RPC protocol details

### Minimal Remote Server

```php
<?php
// mcp-server.php -- deploy this to your web hosting
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('my-remote-server');

$server
    ->tool('server-time', 'Get the current server time', function (string $timezone = 'UTC'): string {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid timezone: {$timezone}");
        }
        $now = new \DateTime('now', $tz);
        return $now->format('Y-m-d H:i:s T');
    })
    ->run();
```

Because `run()` detects the environment, this same file works locally via `php mcp-server.php` *and* remotely when accessed via `https://yoursite.com/mcp-server.php`.

### Deployment to cPanel Hosting

1. **Upload files** -- Upload your project (including `vendor/`) to a directory inside `public_html/`

2. **Directory structure**:
   ```
   public_html/
   └── mcp/
       ├── vendor/
       │   └── ...
       ├── mcp_sessions/     (auto-created, must be writable)
       ├── mcp-server.php
       └── .htaccess
   ```

3. **Create `.htaccess`** for clean URL and security:
   ```apache
   # Deny access to sensitive directories
   <IfModule mod_rewrite.c>
       RewriteEngine On

       # Block direct access to vendor and session directories
       RewriteRule ^vendor/ - [F,L]
       RewriteRule ^mcp_sessions/ - [F,L]
   </IfModule>
   ```

4. **Verify PHP version** -- Ensure PHP 8.1+ is selected in cPanel's "MultiPHP Manager"

5. **Test** -- Your MCP endpoint is now live at:
   ```
   https://yoursite.com/mcp/mcp-server.php
   ```

### Configuring HTTP Options

For more control over the HTTP transport:

```php
<?php
// mcp-server-configured.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Server\Transport\Http\FileSessionStore;

$server = new McpServer('configured-server');

$server
    ->httpOptions([
        'session_timeout' => 1800,     // 30-minute session timeout
        'max_queue_size' => 500,       // Message queue limit
        'enable_sse' => false,         // Disable SSE for shared hosting compatibility
        'shared_hosting' => true,      // Optimize for shared hosting
        'server_header' => 'My-MCP-Server/1.0',
    ])
    ->sessionStore(new FileSessionStore(__DIR__ . '/mcp_sessions'))
    ->tool('ping', 'Check if the server is alive', function (): string {
        return 'pong';
    })
    ->run();
```

### Production Hardening

```php
<?php
// production-server.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Server\Transport\Http\FileSessionStore;

// Suppress warnings in production (MCP protocol uses stdout)
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/mcp-error.log');

$server = new McpServer('production-server');

$server
    ->httpOptions([
        'session_timeout' => 3600,
        'shared_hosting' => true,
    ])
    ->sessionStore(new FileSessionStore(__DIR__ . '/mcp_sessions'))
    ->tool('status', 'Get server status', function (): string {
        return json_encode([
            'status' => 'healthy',
            'version' => '1.0.0',
            'timestamp' => date('c'),
        ]);
    })
    ->run();
```

---

## Part 5: Securing Remote Servers with OAuth

Remote MCP servers are publicly accessible over HTTP, so authentication is essential. The MCP specification uses OAuth 2.1 for authorization. In this model, your MCP server acts as a **resource server** that validates tokens issued by an external **authorization server** (Auth0, Okta, Keycloak, Azure AD, or any OAuth 2.1 / OpenID Connect provider).

The SDK provides built-in JWT validation. You don't need to implement the OAuth flow yourself -- your authorization provider handles token issuance, and the SDK validates incoming tokens on every request.

### Architecture Overview

```
MCP Client                  Authorization Server          Your MCP Server
    |                         (Auth0, Okta, etc.)          (PHP + SDK)
    |                               |                          |
    |-- 1. Get access token ------->|                          |
    |<-- 2. Access token -----------|                          |
    |                               |                          |
    |-- 3. MCP request + Bearer token ----------------------->|
    |                               |                          |-- 4. Validate JWT
    |                               |                          |   (verify signature,
    |                               |                          |    check issuer, audience,
    |                               |                          |    expiry)
    |<-- 5. MCP response ------------------------------------ |
```

The SDK handles step 4 automatically. You configure it with your provider's details.

### Using the Built-in JWT Validator

#### With RS256 / JWKS (Recommended for Production)

Most providers (Auth0, Okta, Keycloak, Azure AD, Google) use RS256 with a JWKS endpoint. The SDK fetches the public keys automatically.

```php
<?php
// secured-server-rs256.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Server\Auth\JwtTokenValidator;

$server = new McpServer('secured-server');

// Configure JWT validation for your provider.
// Replace these values with your actual authorization server details.
$tokenValidator = new JwtTokenValidator(
    key: '',                        // Not used for JWKS-based validation
    algorithm: 'RS256',
    issuer: 'https://your-tenant.auth0.com/',
    audience: 'https://yoursite.com/mcp-server.php',
    jwksUri: 'https://your-tenant.auth0.com/.well-known/jwks.json'
);

$server
    ->withAuth(
        tokenValidator: $tokenValidator,
        authorizationServers: 'https://your-tenant.auth0.com/',
        resourceId: 'https://yoursite.com/mcp-server.php'
    )
    ->tool('protected-data', 'Access protected data', function (): string {
        return 'This data is only accessible with a valid token.';
    })
    ->run();
```

#### With HS256 (Simpler Setup)

HS256 uses a shared secret and is simpler to configure for development or when your provider supports it:

```php
<?php
// secured-server-hs256.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Server\Auth\JwtTokenValidator;

$server = new McpServer('secured-server');

$tokenValidator = new JwtTokenValidator(
    key: 'your-shared-secret-at-least-32-characters-long',
    algorithm: 'HS256',
    issuer: 'https://your-auth-server.com/',
    audience: 'https://yoursite.com/mcp-server.php'
);

$server
    ->withAuth(
        tokenValidator: $tokenValidator,
        authorizationServers: 'https://your-auth-server.com/',
        resourceId: 'https://yoursite.com/mcp-server.php'
    )
    ->tool('protected-data', 'Access protected data', function (): string {
        return 'Authenticated access granted.';
    })
    ->run();
```

### Provider-Specific Configuration

Provider configurations can change over time, consult the official documentation from your provider for the latest details.

#### Auth0

```php
$tokenValidator = new JwtTokenValidator(
    key: '',
    algorithm: 'RS256',
    issuer: 'https://YOUR_TENANT.auth0.com/',
    audience: 'https://yoursite.com/mcp-server.php',    // Must match the API Identifier in Auth0
    jwksUri: 'https://YOUR_TENANT.auth0.com/.well-known/jwks.json'
);
```

#### Okta

```php
$tokenValidator = new JwtTokenValidator(
    key: '',
    algorithm: 'RS256',
    issuer: 'https://YOUR_ORG.okta.com/oauth2/default',
    audience: 'https://yoursite.com/mcp-server.php',
    jwksUri: 'https://YOUR_ORG.okta.com/oauth2/default/v1/keys'
);
```

#### Keycloak

```php
$tokenValidator = new JwtTokenValidator(
    key: '',
    algorithm: 'RS256',
    issuer: 'https://keycloak.example.com/realms/YOUR_REALM',
    audience: 'your-mcp-client-id',
    jwksUri: 'https://keycloak.example.com/realms/YOUR_REALM/protocol/openid-connect/certs'
);
```

#### Azure AD (Entra ID)

```php
$tokenValidator = new JwtTokenValidator(
    key: '',
    algorithm: 'RS256',
    issuer: 'https://login.microsoftonline.com/YOUR_TENANT_ID/v2.0',
    audience: 'api://your-app-client-id',
    jwksUri: 'https://login.microsoftonline.com/YOUR_TENANT_ID/discovery/v2.0/keys'
);
```

### Using a Custom Token Validator

For providers or flows that don't use standard JWT, implement `TokenValidatorInterface`:

```php
<?php
// custom-validator-server.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Server\Auth\TokenValidatorInterface;
use Mcp\Server\Auth\TokenValidationResult;

// Example: validate tokens by calling your provider's introspection endpoint
class IntrospectionTokenValidator implements TokenValidatorInterface
{
    public function __construct(
        private string $introspectionUrl,
        private string $clientId,
        private string $clientSecret
    ) {}

    public function validate(string $token): TokenValidationResult
    {
        $ch = curl_init($this->introspectionUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'token' => $token,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return new TokenValidationResult(
                valid: false,
                error: 'Token introspection request failed'
            );
        }

        $data = json_decode($response, true);

        if (!($data['active'] ?? false)) {
            return new TokenValidationResult(
                valid: false,
                error: 'Token is not active'
            );
        }

        return new TokenValidationResult(
            valid: true,
            claims: $data
        );
    }
}

$server = new McpServer('custom-auth-server');

$validator = new IntrospectionTokenValidator(
    introspectionUrl: 'https://your-auth-server.com/oauth2/introspect',
    clientId: 'your-client-id',
    clientSecret: 'your-client-secret'
);

$server
    ->withAuth($validator, 'https://your-auth-server.com/', 'https://yoursite.com/mcp-server.php')
    ->tool('whoami', 'Show the authenticated user info', function (): string {
        return 'You are authenticated.';
    })
    ->run();
```

### Configuring Apache (.htaccess) for OAuth

Add the following rules to your `.htaccess` file in the document root:

```apache
# 1. Pass Authorization header to PHP (REQUIRED for MCP)
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]

# 2. Route .well-known endpoint to your MCP server
RewriteRule ^\.well-known/oauth-protected-resource(/.*)?$ /server_auth.php [L]
```

**Why This Is Necessary:**
- Many shared hosting environments strip the `Authorization` header by default
- The first rule ensures OAuth bearer tokens reach your PHP scripts
- The second rule enables OAuth discovery via the well-known endpoint

---

## Part 6: Structured Output

Tools can define an `outputSchema` to return machine-readable structured data alongside human-readable text. When an `outputSchema` is set and the callback returns an array or object, the SDK populates both `content` (text for the model) and `structuredContent` (validated JSON for programmatic use).

```php
<?php
// structured_output.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('structured-output');

$server->tool(
    name: 'analyze-url',
    description: 'Parse and analyze a URL into its components',
    callback: function (string $url): array {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException("Invalid URL: {$url}");
        }

        return [
            'scheme' => $parts['scheme'] ?? '',
            'host' => $parts['host'] ?? '',
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '/',
            'query' => $parts['query'] ?? null,
            'fragment' => $parts['fragment'] ?? null,
            'is_secure' => ($parts['scheme'] ?? '') === 'https',
        ];
    },
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'scheme' => ['type' => 'string'],
            'host' => ['type' => 'string'],
            'port' => ['type' => ['integer', 'null']],
            'path' => ['type' => 'string'],
            'query' => ['type' => ['string', 'null']],
            'fragment' => ['type' => ['string', 'null']],
            'is_secure' => ['type' => 'boolean'],
        ],
        'required' => ['scheme', 'host', 'path', 'is_secure'],
    ]
);

$server->tool(
    name: 'calculate-statistics',
    description: 'Calculate basic statistics for a list of comma-separated numbers',
    callback: function (string $numbers): array {
        $values = array_map('floatval', explode(',', $numbers));
        $count = count($values);

        if ($count === 0) {
            throw new \InvalidArgumentException('No numbers provided.');
        }

        sort($values);
        $sum = array_sum($values);
        $mean = $sum / $count;
        $median = ($count % 2 === 0)
            ? ($values[$count / 2 - 1] + $values[$count / 2]) / 2
            : $values[(int) floor($count / 2)];

        return [
            'count' => $count,
            'sum' => $sum,
            'mean' => round($mean, 4),
            'median' => $median,
            'min' => min($values),
            'max' => max($values),
        ];
    },
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'integer'],
            'sum' => ['type' => 'number'],
            'mean' => ['type' => 'number'],
            'median' => ['type' => 'number'],
            'min' => ['type' => 'number'],
            'max' => ['type' => 'number'],
        ],
        'required' => ['count', 'sum', 'mean', 'median', 'min', 'max'],
    ]
);

$server->run();
```

---

## Part 7: Returning Rich Content

Tool callbacks can return a `CallToolResult` directly for full control over the response, including images, multiple content items, and error flags.

### Returning Images

```php
<?php
// rich_content_image.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Mcp\Types\ImageContent;

$server = new McpServer('image-tools');

$server->tool(
    'generate-placeholder',
    'Generate a placeholder image with specified dimensions and return it',
    function (int $width = 200, int $height = 200, string $color = '4A90D9'): CallToolResult {
        // Create a simple colored rectangle using GD
        $image = imagecreatetruecolor($width, $height);
        $r = hexdec(substr($color, 0, 2));
        $g = hexdec(substr($color, 2, 2));
        $b = hexdec(substr($color, 4, 2));
        $fill = imagecolorallocate($image, $r, $g, $b);
        imagefill($image, 0, 0, $fill);

        // Add dimension text
        $white = imagecolorallocate($image, 255, 255, 255);
        $text = "{$width}x{$height}";
        $fontSize = 4;
        $textWidth = imagefontwidth($fontSize) * strlen($text);
        $textHeight = imagefontheight($fontSize);
        $x = ($width - $textWidth) / 2;
        $y = ($height - $textHeight) / 2;
        imagestring($image, $fontSize, (int) $x, (int) $y, $text, $white);

        // Capture PNG output
        ob_start();
        imagepng($image);
        $imageData = ob_get_clean();
        imagedestroy($image);

        return new CallToolResult(
            content: [
                new TextContent(text: "Generated a {$width}x{$height} placeholder image with color #{$color}."),
                new ImageContent(
                    data: base64_encode($imageData),
                    mimeType: 'image/png'
                ),
            ]
        );
    }
);

$server->run();
```

### Returning Multiple Content Items

```php
<?php
// rich_content_multi.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

$server = new McpServer('multi-content');

$server->tool(
    'system-report',
    'Generate a comprehensive system report with multiple sections',
    function (): CallToolResult {
        $phpInfo = "PHP Version: " . PHP_VERSION . "\n"
            . "SAPI: " . PHP_SAPI . "\n"
            . "OS: " . PHP_OS . "\n"
            . "Extensions: " . implode(', ', get_loaded_extensions());

        $memoryInfo = "Memory Usage: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n"
            . "Peak Memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n"
            . "Memory Limit: " . ini_get('memory_limit');

        $diskInfo = "Disk Free: " . round(disk_free_space('.') / 1024 / 1024 / 1024, 2) . " GB\n"
            . "Disk Total: " . round(disk_total_space('.') / 1024 / 1024 / 1024, 2) . " GB";

        return new CallToolResult(
            content: [
                new TextContent(text: "## PHP Environment\n{$phpInfo}"),
                new TextContent(text: "## Memory\n{$memoryInfo}"),
                new TextContent(text: "## Disk\n{$diskInfo}"),
            ]
        );
    }
);

$server->run();
```

---

## Part 8: Multi-Capability Servers

Real-world MCP servers combine tools, prompts, and resources. Here is a complete server that demonstrates all three working together:

```php
<?php
// multi_capability.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('project-assistant');

// --- Tools ---

$server
    ->tool(
        'estimate-reading-time',
        'Estimate reading time for a given text',
        function (string $text, int $wpm = 200): string {
            $wordCount = str_word_count($text);
            $minutes = ceil($wordCount / $wpm);
            return "{$wordCount} words, approximately {$minutes} minute(s) to read at {$wpm} WPM.";
        }
    )
    ->tool(
        'generate-table-of-contents',
        'Extract markdown headings to build a table of contents',
        function (string $markdown): string {
            preg_match_all('/^(#{1,6})\s+(.+)$/m', $markdown, $matches, PREG_SET_ORDER);

            if (empty($matches)) {
                return 'No headings found in the provided markdown.';
            }

            $toc = "## Table of Contents\n\n";
            foreach ($matches as $match) {
                $level = strlen($match[1]);
                $title = trim($match[2]);
                $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $title));
                $indent = str_repeat('  ', $level - 1);
                $toc .= "{$indent}- [{$title}](#{$slug})\n";
            }

            return $toc;
        }
    );

// --- Prompts ---

$server
    ->prompt(
        'write-readme',
        'Generate a README.md for a project',
        function (string $project_name, string $description, string $language = 'PHP'): string {
            return <<<PROMPT
            Write a professional README.md for a project with these details:

            - **Project Name**: {$project_name}
            - **Description**: {$description}
            - **Language**: {$language}

            Include these sections: Overview, Features, Installation, Usage, Configuration, Contributing, and License (MIT).
            Use proper markdown formatting with code examples.
            PROMPT;
        }
    )
    ->prompt(
        'document-function',
        'Generate documentation for a function or method',
        function (string $function_signature, string $purpose): string {
            return <<<PROMPT
            Write comprehensive documentation for this function:

            ```
            {$function_signature}
            ```

            Purpose: {$purpose}

            Include: description, parameter documentation, return value, usage example, and edge cases.
            PROMPT;
        }
    );

// --- Resources ---

$server
    ->resource(
        uri: 'guide://coding-standards',
        name: 'Coding Standards',
        description: 'Team coding standards and conventions',
        callback: function (): string {
            return <<<'STANDARDS'
            # Coding Standards

            ## PHP
            - Follow PSR-12 coding style
            - Use strict_types=1 in all files
            - Type-hint all parameters and return types
            - Use named arguments for constructors with 3+ parameters

            ## Naming
            - Classes: PascalCase
            - Methods/Functions: camelCase
            - Variables: camelCase
            - Constants: UPPER_SNAKE_CASE
            - Database tables: snake_case (plural)

            ## Git
            - Branch naming: feature/description, fix/description, chore/description
            - Commit messages: imperative mood ("Add feature" not "Added feature")
            - Squash merge feature branches
            STANDARDS;
        },
        mimeType: 'text/markdown'
    )
    ->resource(
        uri: 'guide://project-structure',
        name: 'Project Structure',
        description: 'Recommended directory layout',
        callback: function (): string {
            return <<<'STRUCTURE'
            project/
            ├── src/                  # Application source code
            │   ├── Controllers/      # HTTP controllers
            │   ├── Models/           # Database models
            │   ├── Services/         # Business logic
            │   └── Repositories/     # Data access layer
            ├── tests/                # Test files
            │   ├── Unit/
            │   └── Integration/
            ├── config/               # Configuration files
            ├── public/               # Web root
            │   └── index.php         # Entry point
            ├── storage/              # Generated files, logs, cache
            ├── composer.json
            └── README.md
            STRUCTURE;
        },
        mimeType: 'text/plain'
    );

$server->run();
```

---

## Appendix A: Configuration Reference

### HTTP Transport Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `session_timeout` | int | 3600 | Session expiry in seconds |
| `max_queue_size` | int | 1000 | Maximum messages in queue per session |
| `enable_sse` | bool | false | Enable Server-Sent Events (disable for shared hosting) |
| `shared_hosting` | bool/null | null (auto-detect) | Force shared hosting optimizations |
| `server_header` | string | `MCP-PHP-Server/1.0` | Server identification header |
| `auth_enabled` | bool | false | Enable OAuth token validation |
| `authorization_servers` | array | [] | Authorization server URLs |
| `resource` | string/null | null | Protected resource identifier |
| `token_validator` | TokenValidatorInterface/null | null | Token validator instance |
| `resource_metadata_path` | string | `/.well-known/oauth-protected-resource` | OAuth metadata endpoint |

### JwtTokenValidator Constructor

| Parameter | Type | Description |
|-----------|------|-------------|
| `$key` | string | Shared secret (HS256) or PEM public key (RS256) |
| `$algorithm` | string | `'HS256'` or `'RS256'` (default: `'HS256'`) |
| `$issuer` | string/null | Expected `iss` claim value |
| `$audience` | string/null | Expected `aud` claim value |
| `$jwksUri` | string/null | JWKS endpoint URL for RS256 key fetching |

### McpServer Methods

| Method | Description |
|--------|-------------|
| `tool(name, description, callback, title?, icons?, outputSchema?)` | Register a tool |
| `prompt(name, description, callback, title?, icons?)` | Register a prompt |
| `resource(uri, name, callback, description?, mimeType?, title?, icons?, size?)` | Register a resource |
| `httpOptions(array)` | Set HTTP transport configuration |
| `sessionStore(SessionStoreInterface)` | Set the session persistence backend |
| `withAuth(tokenValidator, authorizationServers, resourceId)` | Enable OAuth authentication |
| `notifyOnChanges(resourcesChanged?, toolsChanged?, promptsChanged?)` | Configure change notifications |
| `enableTasks(storagePath?)` | Enable experimental task support |
| `run()` | Auto-detect transport and start |
| `runStdio()` | Force stdio transport |
| `runHttp()` | Force HTTP transport |
| `getServer()` | Access the underlying Server instance |
| `getTaskManager()` | Access the TaskManager instance |

### Callback Return Types

| Primitive | Return Type | SDK Behavior |
|-----------|-------------|--------------|
| **Tool** | `string` | Wrapped in `TextContent` inside `CallToolResult` |
| **Tool** | `CallToolResult` | Returned as-is |
| **Tool** | `array` (with `outputSchema`) | Wrapped with `content` + `structuredContent` |
| **Prompt** | `string` | Wrapped as single user-role `PromptMessage` |
| **Prompt** | `array` of strings | Each string becomes a user-role `PromptMessage` |
| **Prompt** | `GetPromptResult` | Returned as-is |
| **Resource** | `string` | Wrapped as `TextResourceContents` |
| **Resource** | `SplFileObject` or `resource` | Base64-encoded as `BlobResourceContents` |
| **Resource** | `ReadResourceResult` | Returned as-is |

### PHP Type to JSON Schema Mapping

| PHP Type | JSON Schema Type |
|----------|-----------------|
| `string` | `"string"` |
| `int` | `"number"` |
| `float` | `"number"` |
| `bool` | `"boolean"` |
| `array` | `"array"` |
| `object` / `stdClass` | `"object"` |

---

## Appendix B: Deployment Checklist

### Local (Stdio) Server

- [ ] PHP 8.1+ installed and accessible via `php` command
- [ ] `composer install` run to install dependencies
- [ ] Server file is executable and paths are correct
- [ ] Tested via `php server.php` (should hang waiting for input -- that's normal)
- [ ] MCP client config points to the correct `php` binary and script path

### Remote (HTTP) Server on cPanel/Apache

- [ ] PHP 8.1+ selected in cPanel MultiPHP Manager
- [ ] `ext-curl` and `ext-json` enabled
- [ ] Project uploaded with `vendor/` directory
- [ ] Session storage directory is writable (`mcp_sessions/`)
- [ ] `.htaccess` blocks access to `vendor/` and `mcp_sessions/`
- [ ] Error logging configured (not displayed to stdout)
- [ ] HTTPS enabled (required for production, especially with OAuth)
- [ ] Tested with a simple POST request to the MCP endpoint
- [ ] For OAuth: authorization server URLs, issuer, and audience values are correct

### Security

- [ ] OAuth enabled for any server accessible over the public internet that handles non-public data
- [ ] Database queries use prepared statements (never interpolate user input)
- [ ] Tool callbacks validate and sanitize all input
- [ ] Read-only tools don't accidentally modify state
- [ ] Sensitive directories (vendor, sessions, config) are not web-accessible
- [ ] Error messages don't leak internal paths or credentials

---

*This guide covers the `logiscape/mcp-sdk-php` SDK implementing the [MCP specification 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25). For SDK source code and updates, visit [github.com/logiscape/mcp-sdk-php](https://github.com/logiscape/mcp-sdk-php).*
