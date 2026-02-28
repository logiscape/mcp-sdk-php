<?php

// Simple PHP-based MCP server over HTTP

require 'vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('simple-mcp-server');

$server
    // Define a tool
    ->tool('add-numbers', 'Adds two numbers together', function (float $a, float $b): string {
        return 'Sum: ' . ($a + $b);
    })

    // Start the HTTP server
    ->runHttp();
