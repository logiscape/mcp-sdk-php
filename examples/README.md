# MCP SDK for PHP — Examples

Working examples for building MCP servers and clients with
`logiscape/mcp-sdk-php` v2. Every example targets the `2026-07-28` MCP
specification revision by default while remaining interoperable with legacy
clients and servers (`2024-11-05` … `2025-11-25`) through the SDK's automatic
dual-era negotiation.

Unless a command says otherwise, run the examples from the repository (or
your project) root so `vendor/autoload.php` resolves.

## Getting started

| Example | Demonstrates |
| --- | --- |
| [`simple_server.php`](simple_server.php) | The minimal `McpServer`: one tool, `run()` auto-selects stdio (CLI) or HTTP (web SAPI) |
| [`simple_server_stdio.php`](simple_server_stdio.php) | Same server forced onto the stdio transport with `runStdio()` |
| [`simple_server_http.php`](simple_server_http.php) | Same server forced onto the HTTP transport with `runHttp()` |

```bash
# stdio (for MCP hosts that spawn a local process)
php examples/simple_server.php

# HTTP (development server; on real hosting just upload the script)
php -S localhost:8000 examples/simple_server_http.php

# inspect either with MCP Inspector
npx @modelcontextprotocol/inspector
```

## The 2026-07-28 stateless revision

| Example | Demonstrates |
| --- | --- |
| [`stateless_server.php`](stateless_server.php) | A minimal stateless server: tools (including structured output), resources, a resource template, and a prompt served per-request with no handshake and no session — the model that fits shared PHP hosting |
| [`client_negotiation.php`](client_negotiation.php) | Dual-era negotiation from the client side: `server/discover` probing, legacy fallback, and the `protocolMode` override (`auto` / `modern` / `legacy`) |

```bash
# stdio, auto-negotiates the modern era
php examples/client_negotiation.php examples/stateless_server.php

# force the legacy initialize handshake for comparison
php examples/client_negotiation.php examples/stateless_server.php --mode=legacy

# or over HTTP
php -S localhost:8000 examples/stateless_server.php &
php examples/client_negotiation.php http://localhost:8000
```

## Interactive tools (elicitation / multi-round-trip input)

| Example | Demonstrates |
| --- | --- |
| [`elicitation_server.php`](elicitation_server.php) | A tool that asks the user for structured input mid-execution via an injected `ElicitationContext` — carried as SEP-2322 `InputRequiredResult` exchanges on the modern path, as server-initiated requests on the legacy path |
| [`elicitation_client.php`](elicitation_client.php) | Registering an `onElicit` handler; the SDK services the multi-round-trip exchange transparently inside `callTool()` |

```bash
php examples/elicitation_client.php   # spawns elicitation_server.php over stdio
```

## Tasks extension (SEP-2663, `io.modelcontextprotocol/tasks`)

| Example | Demonstrates |
| --- | --- |
| [`tasks_server.php`](tasks_server.php) | `enableTasks()` plus per-tool `taskSupport:` opt-in (`OPTIONAL` and `REQUIRED`), including a task that gathers in-task input |
| [`tasks_client.php`](tasks_client.php) | Declaring the extension, receiving a `CreateTaskResult` task handle from `callTool()`, polling `tasks/get`, and answering `input_required` via `tasks/update` |

```bash
php examples/tasks_client.php   # spawns tasks_server.php over stdio
```

## MCP Apps extension (SEP-1865, `io.modelcontextprotocol/ui`)

| Example | Demonstrates |
| --- | --- |
| [`apps_server/`](apps_server/) | Linking a tool to an interactive `ui://` HTML template with `McpServer::ui()`, rendered by Apps-capable hosts in a sandboxed iframe |

## HTTP clients and OAuth

| Example | Demonstrates |
| --- | --- |
| [`client_http.php`](client_http.php) | A command-line HTTP client: connect options (headers, TLS, timeouts), capability inspection, and listing tools/prompts/resources |
| [`server_auth/`](server_auth/) | An OAuth 2.1-protected HTTP server (`McpServer::withAuth()`) with JWT validation and the RFC 9728 protected-resource metadata endpoint, deployable on cPanel/Apache |

## Related reference code

- [`../webclient/`](../webclient/README.md) — a full browser-based MCP client
  for shared PHP hosting (OAuth flows, session resume, elicitation UI).
- [`../conformance/everything-server.php`](../conformance/everything-server.php)
  and [`everything-client.php`](../conformance/everything-client.php) — the
  fixtures for the official MCP conformance suite; they exercise nearly every
  SDK surface (subscriptions, request-metadata headers, sampling, logging,
  OAuth grants) and are a good reference for features without a dedicated
  example.
- [`../docs/server-dev.md`](../docs/server-dev.md) and
  [`../docs/client-dev.md`](../docs/client-dev.md) — the full development
  guides.
