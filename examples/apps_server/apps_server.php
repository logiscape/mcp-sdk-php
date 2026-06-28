<?php

/**
 * MCP Apps (SEP-1865) example server.
 *
 * Demonstrates the MCP Apps extension built entirely on the public
 * McpServer::ui() helper: a tool is linked to a `ui://` HTML template that a
 * host renders in a sandboxed iframe. The extension adds NO new RPC method —
 * UI-originated interactions arrive as ordinary tools/call requests, and the
 * server's only job is to declare the extension, serve the template resource,
 * and link it to the tool.
 *
 * Run it like any other McpServer example:
 *   - Web host:  point your MCP-Apps-capable host (e.g. Claude, VS Code) at
 *                this script served over HTTP, or run
 *                `php -S localhost:8000 examples/apps_server/apps_server.php`.
 *   - stdio:     `php examples/apps_server/apps_server.php`
 *
 * The accompanying dashboard.html implements the host<->view postMessage
 * handshake the extension defines. See README.md in this directory.
 */

require 'vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

$server = new McpServer('weather-apps-server');

$server
    // An ordinary tool. It returns BOTH a text `content` block (so non-UI
    // hosts and the model still get a usable answer — graceful degradation)
    // and `structuredContent` (the UI-optimized payload the dashboard renders).
    ->tool(
        'get_weather',
        'Get the current weather for a city',
        function (string $city): CallToolResult {
            // A real server would call a weather API here.
            $data = [
                'city' => $city,
                'temperatureC' => 21,
                'condition' => 'Sunny',
                'humidity' => 48,
            ];

            return new CallToolResult(
                content: [new TextContent(
                    text: "Weather in {$city}: {$data['condition']}, {$data['temperatureC']}°C, {$data['humidity']}% humidity"
                )],
                structuredContent: $data,
            );
        },
    )

    // Link the tool to its UI template. This single call registers the
    // `ui://weather/dashboard` resource (MIME text/html;profile=mcp-app),
    // writes the tool's `_meta.ui.resourceUri` link, and declares the Apps
    // extension in the server's capabilities (advertised in server/discover).
    ->ui(
        tool: 'get_weather',
        uri: 'ui://weather/dashboard',
        name: 'Weather Dashboard',
        html: (string) file_get_contents(__DIR__ . '/dashboard.html'),
        description: 'Interactive weather dashboard for the get_weather tool',
        // Both audiences may use this tool (the default). Use ['app'] to hide a
        // tool from the agent and expose it only to the rendered UI.
        visibility: ['model', 'app'],
        // Optional host hints. The view fetches nothing external here, so the
        // default Content-Security-Policy is sufficient and no `csp` is set.
        prefersBorder: true,
    )

    // run() auto-selects stdio on the CLI and HTTP under a web server.
    ->run();
