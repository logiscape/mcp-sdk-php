# Model Context Protocol SDK for PHP

English | [中文](README.zh-CN.md)

This package provides a PHP implementation of the [Model Context Protocol](https://modelcontextprotocol.io). The primary goal of this project is to provide both a MCP server and a MCP client using pure PHP, making it easy to use in PHP/Apache/cPanel hosting environments with typical server configurations.

## Overview

This PHP SDK implements the full MCP specification, making it easy to:
* Build MCP clients that can connect to any MCP server
* Create MCP servers that expose resources, prompts and tools
* Use standard transports like stdio and HTTP
* Handle all MCP protocol messages and lifecycle events

This SDK began as a PHP port of the official [Python SDK](https://github.com/modelcontextprotocol/python-sdk) for the Model Context Protocol. It has since been expanded to fully support MCP using native PHP functions, helping to maximize compatibility with most standard web hosting environments.

This SDK features a 100% pass rate on the applicable required [MCP Conformance Tests](https://github.com/modelcontextprotocol/conformance) as of testing suite v0.1.16 and aims to maintain full conformance as the spec and tests evolve, not including tests still marked as experimental or optional extensions.

## Installation

You can install the package via composer:

```bash
composer require logiscape/mcp-sdk-php
```

### Requirements
* PHP 8.1 or higher
* ext-curl
* ext-json
* ext-pcntl (optional, recommended for CLI environments)
* monolog/monolog (optional, used by example clients/servers for logging)

## Basic Usage

### Creating an MCP Server

For detailed documentation and examples of MCP servers, see the [Server Development Guide](docs/server-dev.md).

Here's a complete example of an MCP server that provides a simple tool:

```php
<?php

// An example server with a basic addition tool

require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('example-mcp-server');

$server
    // Define a tool
    ->tool('add-numbers', 'Adds two numbers together', function (float $a, float $b): string {
        return 'Sum: ' . ($a + $b);
    })

    // Start the server
    ->run();
```

Save this as `example_server.php`

### Creating an MCP Client

Here's how to create a client that connects to the example server and calls the addition tool:

```php
<?php

// A basic example client that connects to example_server.php and calls a tool

require 'vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();

try {
    // Connect to the server over stdio
    $session = $client->connect('php', ['example_server.php']);

    // Call the add-numbers tool with two arguments
    $result = $session->callTool('add-numbers', ['a' => 5, 'b' => 25]);

    // Output the result
    echo $result->content[0]->text . "\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $client->close();
}
```

Save this as `example_client.php` and run it:
```bash
php example_client.php
```

## Advanced Examples

The "examples" directory includes additional clients and servers for both the STDIO and HTTP transports. All examples are designed to run in the same directory where you installed the SDK.

Some examples use monolog for logging, which can be installed via composer:

```bash
composer require monolog/monolog
```

## MCP Web Client

The "webclient" directory includes a web-based application for testing MCP servers. It was designed to demonstrate a MCP client capable of running in a typical web hosting environment.

### Setting Up The Web Client

To setup the web client, upload the contents of "webclient" to a web directory, such as public_html on a cPanel server. Ensure that the MCP SDK for PHP is installed in that same directory by running the Composer command found in the Installation section of this README.

### Using The Web Client

Once the web client has been uploaded to a web directory, navigate to index.php to open the interface. To connect to the included MCP test server, enter `php` in the Command field and `test_server.php` in the Arguments field and click `Connect to Server`. The interface allows you to test Prompts, Tools, and Resources. There is also a Debug Panel allowing you to view the JSON-RPC messages being sent between the Client and Server.

### Web Client Notes And Limitations

This MCP Web Client is intended for developers to test MCP servers, and it is not recommended to be made publicly accessible as a web interface without additional testing for security, error handling, and resource management.

## OAuth Authorization

The HTTP server transport includes optional OAuth 2.1 support. For more details see the [OAuth Authentication Example](examples/server_auth/README.md).

## Documentation

For detailed information about the Model Context Protocol, visit the [official documentation](https://modelcontextprotocol.io).

## Latest Updates

The SDK is currently aiming to support the 2025-11-25 revision of the MCP Spec.

### Implemented
- Structured tool output
- New metadata such as icons
- Experimental support for tasks
- Elicitation

### Partial Implementation or In Development
- Experimental support for task-augmented elicitation

## Credits

This PHP SDK was developed by:
- [Josh Abbott](https://joshabbott.com)
- Claude 3.5 Sonnet
- Claude Opus 4.5

Additional debugging and refactoring done by Josh Abbott using OpenAI ChatGPT o1 pro mode and OpenAI Codex.

Special acknowledgement to [Roman Pronskiy](https://github.com/pronskiy) for simplifying the server building process with his convenience wrapper project: [https://github.com/pronskiy/mcp](https://github.com/pronskiy/mcp)

- `Mcp\Server\McpServer` — adapted from `Pronskiy\Mcp\Server`
- `Mcp\Server\McpServerException` — adapted from `Pronskiy\Mcp\McpServerException`

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
