# Model Context Protocol SDK for PHP

[![CI](https://github.com/logiscape/mcp-sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/logiscape/mcp-sdk-php/actions/workflows/ci.yml)
[![MCP Conformance](https://github.com/logiscape/mcp-sdk-php/actions/workflows/conformance.yml/badge.svg)](https://github.com/logiscape/mcp-sdk-php/actions/workflows/conformance.yml)

> [!NOTE]
> **This `main` branch contains the v2 beta of the logiscape/mcp-sdk-php SDK.**
>
> v2 adds day-one support for the MCP `2026-07-28` spec revision; upgrading
> from v1 is covered by the [Migration Guide](docs/migration-v2.md).
> For the stable v1 code and documentation, see the [`1.x` branch](https://github.com/logiscape/mcp-sdk-php/tree/1.x).

This package provides a PHP implementation of the [Model Context Protocol](https://modelcontextprotocol.io). The primary goal of this project is to provide both an MCP server and an MCP client using pure PHP, making it easy to use in PHP/Apache/cPanel hosting environments with typical server configurations.

## Overview

This PHP SDK implements the full MCP specification, making it easy to:
* Build MCP clients that can connect to any MCP server
* Create MCP servers that expose resources, prompts and tools
* Use standard transports like stdio and HTTP
* Interoperate with peers on any spec revision from `2024-11-05` through `2026-07-28` — version negotiation is built in

This SDK offers two major advantages for the MCP and PHP developer communities:

* This SDK features a 100% pass rate on the applicable required [MCP Conformance Tests](https://github.com/modelcontextprotocol/conformance) — the stable-track baseline is empty — and additionally runs the draft-track suite validating the `2026-07-28` release candidate. See [Conformance Testing](conformance/README.md).

* The SDK can demonstrate both a functional MCP client and MCP server with a single Composer command. See the [Webclient Example](webclient/README.md).

## New in v2

v2 is built around two headline features — day-one support for the MCP
`2026-07-28` "stateless core" spec revision, and full support for the MCP
Apps extension — delivered without giving up compatibility with any
earlier revision:

* **`2026-07-28` stateless core** — no `initialize` handshake, no session
  ids: every request is self-contained, which is exactly the model that
  fits typical PHP web hosting (a fresh process per request). Includes
  `server/discover`, caching hints, request-metadata headers,
  `subscriptions/listen` streams, and multi-round-trip input gathering.
* **Dual-era negotiation** — servers detect each request's era and clients
  probe-then-fall-back automatically, so one codebase serves both modern
  (`2026-07-28`) and legacy (`2024-11-05` … `2025-11-25`) peers
  concurrently.
* **Tasks extension (SEP-2663)** — long-running tool calls return a task
  handle that clients poll, cancel, and feed input to; backed by a
  file-based store that works on shared hosting. See the
  [Tasks guide](docs/tasks.md).
* **MCP Apps extension (SEP-1865)** — attach a host-rendered HTML UI to a
  tool with one `->ui(...)` call. See the [Apps guide](docs/apps.md).

The complete inventory — including OAuth 2.1 hardening, typed errors, and
the feature-lifecycle deprecation registry — is in the
[CHANGELOG](CHANGELOG.md); API differences from v1 are covered in the
[Migration Guide](docs/migration-v2.md).

## Installation

You can install the v2 beta via composer:

```bash
composer require logiscape/mcp-sdk-php:2.0.0-beta3
```

(Plain `composer require logiscape/mcp-sdk-php` installs the stable v1
line until v2.0.0 is released.)

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

For detailed documentation and examples of MCP clients, see the [Client Development Guide](docs/client-dev.md).

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

## Examples

The [`examples/` directory](examples/README.md) contains a runnable example
for every major SDK feature — stateless servers, dual-era client
negotiation, Tasks, Apps, elicitation, HTTP clients, and OAuth — each
designed to run from the directory where you installed the SDK. Its README
doubles as a feature map of the SDK.

Some examples use monolog for logging, which can be installed via composer:

```bash
composer require monolog/monolog
```

## MCP Web Client

The "webclient" directory includes a web-based application for testing MCP servers. It was designed to demonstrate an MCP client capable of running in a typical web hosting environment.

### Setting Up The Web Client

To setup the web client, upload the contents of "webclient" to a web directory, such as public_html on a cPanel server. Ensure that the MCP SDK for PHP is installed in that same directory by running the Composer command found in the Installation section of this README.

### Using The Web Client

Once the web client has been uploaded to a web directory, navigate to index.php to open the interface. To connect to the included MCP test server, enter `php` in the Command field and `test_server.php` in the Arguments field and click `Connect to Server`. The interface allows you to test Prompts, Tools, and Resources. There is also a Debug Panel allowing you to view the JSON-RPC messages being sent between the Client and Server.

### Web Client Notes And Limitations

This MCP Web Client is intended for developers to test MCP servers, and it is not recommended to be made publicly accessible as a web interface without additional testing for security, error handling, and resource management.

## OAuth Authorization

The HTTP server transport includes optional OAuth 2.1 support. For more details see the [OAuth Authentication Example](examples/server_auth/README.md).

## Documentation

For detailed information about the Model Context Protocol itself, visit the [official documentation](https://modelcontextprotocol.io).

Project-specific documentation lives in this repository (see the
[documentation index](docs/README.md) for the full annotated list):

| Document | Purpose |
| --- | --- |
| [Server Development Guide](docs/server-dev.md) | Building MCP servers with `McpServer`. |
| [Client Development Guide](docs/client-dev.md) | Building MCP clients with `Client` / `ClientSession`. |
| [Migration Guide](docs/migration-v2.md) | Upgrading a project from v1 to v2. |
| [Tasks Extension Guide](docs/tasks.md) | Long-running tool calls (SEP-2663), server and client side. |
| [Apps Extension Guide](docs/apps.md) | Host-rendered tool UIs (SEP-1865). |
| [Examples Index](examples/README.md) | A runnable example per major feature. |
| [Testing Guide](docs/testing.md) | Unit tests, PHPStan, conformance, MCP Inspector, Claude Code, OpenAI. |
| [Compatibility Guide](docs/compatibility.md) | cPanel / Apache / PHP-FPM notes and graceful-degradation rules. |
| [Dependency Policy](docs/dependency-policy.md) | How dependencies are declared, bumped, and retired. |
| [Label Scheme](docs/labels.md) | Issue labels aligned with the MCP SDK Working Group conventions. |
| [Conformance Testing](conformance/README.md) | How the dual-track conformance harness works and the no-shortcut rule. |

Project governance and process:

| Document | Purpose |
| --- | --- |
| [CONTRIBUTING](CONTRIBUTING.md) | Local development setup, test stack, coding standards, versioning policy. |
| [ROADMAP](ROADMAP.md) | Direction, tier self-assessment against SEP-1730, and what we're working on. |
| [CHANGELOG](CHANGELOG.md) | Structured release history. |
| [SECURITY](SECURITY.md) | How to report vulnerabilities. |
| [GOVERNANCE](GOVERNANCE.md) | How decisions are made and how to become a trusted contributor. |
| [SUPPORT](SUPPORT.md) | Where to ask questions. |
| [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) | Adopts Contributor Covenant 2.1 by reference; summarises the key sections. |

## Project Status

This SDK supports the latest MCP specification revision (`2026-07-28`, the
"stateless core") from day one, alongside every earlier revision back to
`2024-11-05` — version negotiation and per-request era detection let one
server or client interoperate across all of them.

Conformance runs on two tracks in CI: the stable track passes 100% of the
applicable required tests with an **empty baseline**
([`conformance/conformance-baseline.yml`](conformance/conformance-baseline.yml)),
and the draft track validates the `2026-07-28` release candidate against
the upstream RC-validation suite, with the few remaining entries in
[`conformance/conformance-draft-baseline.yml`](conformance/conformance-draft-baseline.yml)
documented as upstream-tool issues. See
[conformance/README.md](conformance/README.md) for the dual-track rules.

v2 is in **beta** (latest pre-release: `v2.0.0-beta2`); the final `v2.0.0`
is gated on the `2026-07-28` spec's publication and a clean conformance
run against the suite current at that date, per the
[v2 development plan](docs/v2-development-plan.md).

This is a community-maintained SDK. See [ROADMAP.md](ROADMAP.md) for a candid self-assessment against the [SDK tier criteria](https://modelcontextprotocol.io/community/sdk-tiers) and [GOVERNANCE.md](GOVERNANCE.md) for how the project is maintained.

Release history is captured in [CHANGELOG.md](CHANGELOG.md); what is shipping next is tracked under its `[Unreleased]` heading.

## Credits

This PHP SDK was developed by:
- [Josh Abbott](https://joshabbott.com)
- Claude 3.5 Sonnet
- Claude Opus 4.5
- Claude Fable 5

Code reviews and refactoring done by Josh Abbott originally using OpenAI ChatGPT o1 pro mode, and later by OpenAI Codex using GPT-5.5.

Special acknowledgement to [Roman Pronskiy](https://github.com/pronskiy) for simplifying the server building process with his convenience wrapper project: [https://github.com/pronskiy/mcp](https://github.com/pronskiy/mcp)

- `Mcp\Server\McpServer` — adapted from `Pronskiy\Mcp\Server`
- `Mcp\Server\McpServerException` — adapted from `Pronskiy\Mcp\McpServerException`

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
