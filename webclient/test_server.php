<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
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

/**
 * Simple MCP server exercising the three capabilities the webclient UI
 * renders: a prompt, a tool, and a resource. Useful as a quick target when
 * smoke-testing the webclient.
 */

declare(strict_types=1);

$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require $candidate;
        break;
    }
}

use Mcp\Server\McpServer;

$server = new McpServer('mcp-test-server');

$server
    ->prompt(
        'example-prompt',
        'An example prompt template',
        fn(string $arg1) => "Example prompt text with argument: {$arg1}"
    )
    ->tool(
        'add-numbers',
        'Adds two numbers together',
        fn(float $num1, float $num2) => "The sum of {$num1} and {$num2} is " . ($num1 + $num2)
    )
    ->resource(
        uri: 'example://greeting',
        name: 'Greeting Text',
        callback: fn() => 'Hello from the example MCP server!',
        description: 'A simple greeting message',
        mimeType: 'text/plain'
    )
    ->run();
