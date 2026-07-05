# AGENTS.md

## Project Overview

This is a PHP implementation of the Model Context Protocol (MCP), allowing applications to provide context for LLMs in a standardized way. The SDK implements both MCP clients and servers with support for stdio and HTTP transports.

**Key characteristics:**
- Designed for native PHP with easy installation via Composer
- Targets PHP 8.1+ with type safety (strict_types=1)
- Supports both traditional CLI/stdio and web hosting environments
- Speaks every MCP spec revision from `2024-11-05` through `2026-07-28` (the "stateless core"); servers detect each request's era and clients probe-then-fall-back, so one codebase serves modern and legacy peers concurrently
- Implements the Tasks (SEP-2663) and MCP Apps (SEP-1865) extensions — see [docs/tasks.md](docs/tasks.md) and [docs/apps.md](docs/apps.md)
- Includes McpServer convenience wrapper for building fully functional MCP servers with just a few lines of PHP code

User-facing documentation is indexed in [docs/README.md](docs/README.md);
the two deep guides are [docs/server-dev.md](docs/server-dev.md) and
[docs/client-dev.md](docs/client-dev.md), and the v1 → v2 changes are in
[docs/migration-v2.md](docs/migration-v2.md).

## Contributor-facing documentation

For non-trivial work, please also consult the governance and process docs at
the repository root: [CONTRIBUTING.md](CONTRIBUTING.md) (coding standards,
test stack, versioning policy), [ROADMAP.md](ROADMAP.md) (direction and tier
self-assessment), [SECURITY.md](SECURITY.md) (vulnerability reporting),
[GOVERNANCE.md](GOVERNANCE.md), and the deeper guides under `docs/` —
[docs/v2-development-plan.md](docs/v2-development-plan.md) (the main working
plan for v2 development), [docs/testing.md](docs/testing.md),
[docs/compatibility.md](docs/compatibility.md)
(the cPanel/Apache compatibility rules), [docs/dependency-policy.md](docs/dependency-policy.md),
and [conformance/README.md](conformance/README.md) (including the
no-shortcuts-for-conformance rule).

## V2 Development Process

The `main` branch carries the v2 beta of the SDK (stable v1 lives on the
`1.x` branch). **All v2 work follows
[docs/v2-development-plan.md](docs/v2-development-plan.md)** — the main
working plan, with ordered workstreams, dependencies, completion criteria,
and release gates. Key rules for agents working on v2 milestones:

1. **Research first.** Before implementing a milestone, gather the latest
   details from official MCP sources (the spec repository, the `2026-07-28`
   release-candidate material, the ext-apps repository, the conformance
   suite). The plan reflects a point in time; the official text wins where
   they disagree, and the plan should be amended in the same change set.
2. **Implementation includes tests.** A milestone is achieved only when the
   work is implemented, automated tests cover it per the project's testing
   conventions, and `composer check` passes (plus `composer conformance`
   regression-free for protocol/transport/session/McpServer changes).
3. **Human-initiated code review.** After the agent has verified the
   milestone, the human user initiates a code review; the agent addresses
   findings and re-verifies.
4. **All commits are human-initiated.** Agents must never run `git commit`,
   `git push`, or tag releases. Leave verified work in the working tree for
   the human user to review, approve, and commit. No exceptions.

## Development Testing Commands

The canonical, complete test-stack reference is
**[docs/testing.md](docs/testing.md)** — installation, PHPUnit, PHPStan,
both conformance tracks, single-scenario runs, and the interactive
harnesses (MCP Inspector, Claude Code, OpenAI). The commands used on
nearly every change:

```bash
composer install            # dependencies (plus `npm install` for the pinned conformance tools)
composer check              # the regression gate: PHPUnit + PHPStan — run before considering any change done
composer conformance        # stable conformance track (published-spec scenarios)
composer conformance-draft  # draft track (2026-07-28 RC scenarios)
```

**When to run conformance:** `composer conformance` must stay
regression-free for any change touching protocol handling, transports,
session management, or `McpServer`; add `composer conformance-draft` for
changes touching `2026-07-28` behavior, updating
`conformance/conformance-draft-baseline.yml` to reflect honest progress.
Neither is part of `composer check` (they need Node.js). Known failures
live in each track's baseline file with a root cause — never engineer a
workaround to make a scenario pass; see
[conformance/README.md](conformance/README.md) for the dual-track rules.

## Building An MCP Server

The easiest and recommended way to create a new MCP server is to use the McpServer convenience wrapper. Here is a complete fully functional example that can be used as both a local MCP server or a remote MCP server.

```php
<?php
require 'vendor/autoload.php';
use Mcp\Server\McpServer;
$server = new McpServer('example-mcp-server');
$server
    ->tool('add', 'Add numbers', fn(float $a, float $b) => "Sum: " . ($a + $b))
    ->prompt('greet', 'Greeting', fn(string $name) => "Hello, {$name}!")
    ->resource(uri: 'info://php', name: 'PHP Info', callback: fn() => PHP_VERSION)
    ->run();
```

When using the convenience wrapper, `run()` is a router that uses the stdio transport on cli applications and the HTTP transport on web servers. `run()` can be replaced with `runStdio()` to force the stdio transport, or `runHttp()` to force the HTTP transport.

## Architecture Overview

### Core Component Layers

1. **Session Layer** (`Shared/BaseSession.php`)
   - Abstract base for all MCP sessions (client and server)
   - Manages JSON-RPC message routing and handler registration
   - Handles request/response matching via request IDs
   - Maintains initialization state and protocol version negotiation

2. **Client Architecture** (`Client/`)
   - `Client`: Main entry point, detects transport (stdio vs HTTP) based on commandOrUrl parameter
   - `ClientSession`: Extends BaseSession, provides high-level methods (`listPrompts()`, `callTool()`, etc.)
   - `Transport/StdioTransport`: Process-based transport using stdin/stdout
   - `Transport/StreamableHttpTransport`: HTTP/HTTPS transport with SSE support
   - Both transports speak JSON-RPC over their respective channels

3. **Server Architecture** (`Server/`)
   - `Server`: Request/notification handler registry, capability management
   - `ServerSession`: Extends BaseSession, handles initialization handshake
   - `ServerRunner`: Stdio runner that manages the server lifecycle
   - `HttpServerRunner`: HTTP runner for web-based servers
   - `McpServer`: Convenience wrapper to simplify building MCP servers
   - Handlers are registered as callables: `registerHandler(string $method, callable $handler)`

4. **Types System** (`Types/`)
   - All MCP protocol types are implemented as typed PHP classes
   - Types implement `McpModel` interface for JSON serialization/deserialization
   - Uses `ExtraFieldsTrait` for forward compatibility with unknown fields
   - Request/Response types follow JSON-RPC 2.0 specification

5. **Transport Abstraction**
   - Stdio: Uses PHP process control (pcntl) for server process management
   - HTTP: Supports both standard HTTP and Server-Sent Events (SSE) for streaming
   - `MemoryStream` for testing without actual I/O
   - `HttpIoInterface`: SAPI adapter seam for the HTTP runner. `NativePhpIo` (default) wraps `header()`/`echo`/`flush()`/`ob_*`/`connection_aborted` for cPanel/Apache/FPM; `BufferedIo` captures bytes for tests or non-SAPI hosts. Pass a custom implementation via `McpServer::httpOptions(['io' => $adapter])` or the `HttpServerRunner` constructor to embed the runner in a framework (Symfony, Slim, FrankenPHP, RoadRunner). `handleRequest()` returns a `StreamedHttpMessage` when the streaming-SSE body was already written through the adapter during handler execution — integrators can check `instanceof StreamedHttpMessage` to skip re-emitting the body.

### Handler Registration Pattern

**Server-side:**
```php
$server
    // Define a tool
    ->tool('add-numbers', 'Adds two numbers together', function (float $a, float $b): string {
        return 'Sum: ' . ($a + $b);
    });
```

**Client-side:**
```php
// ClientSession provides convenience methods that internally send JSON-RPC requests
$prompts = $session->listPrompts();
$result = $session->callTool($toolName, $arguments);
```

### Protocol Version Negotiation

The SDK speaks two protocol *eras* and negotiates per the spec's detection rules:
- **Modern era (`2026-07-28`, the latest revision):** no `initialize` handshake — every request carries protocol version, client info, and client capabilities in its `_meta` envelope, and `server/discover` answers capability discovery statelessly. The server detects a modern request per-request (envelope or `MCP-Protocol-Version` header) and serves it on a fresh ephemeral context; no `Mcp-Session-Id` exists on this path.
- **Legacy era (`2024-11-05` … `2025-11-25`):** the classic `initialize` handshake, negotiated to the highest mutually supported version, with the session header on HTTP. `Version::LATEST_LEGACY_PROTOCOL_VERSION` caps what the handshake can negotiate.
- **Client side:** `Client::connect()` probes `server/discover` first and falls back to `initialize` (`protocolMode: 'auto' | 'modern' | 'legacy'`).
- Version constants live in `src/Shared/Version.php`; feature gating in `ServerSession::clientSupportsFeature()` / `ClientSession::supportsFeature()`; response shaping for older clients in `ServerSession::adaptResponseForClient()`.

### Web Hosting Considerations

The SDK is designed for typical PHP web hosting (cPanel/Apache/FPM):
- **The `2026-07-28` stateless model is a natural fit**: every modern request is self-contained, so a fresh PHP process per request needs no persisted protocol state at all.
- **Legacy sessions** persist to files (or another `SessionStoreInterface`) between requests; the HTTP transport works without long-running processes on both eras.
- Cross-process event fan-out for `subscriptions/listen` uses `FileSubscriptionBus`; the Tasks extension's `TaskManager` store is file-based for the same reason.
- See [docs/compatibility.md](docs/compatibility.md) for the compatibility rules and `webclient/` for the reference client implementation.

## Testing Patterns

Tests use PHPUnit 10+ and follow these conventions:

- Test classes are marked `final` and extend `PHPUnit\Framework\TestCase`
- Test methods include detailed docblocks explaining what is being validated
- Mock transports using `MemoryStream` or `InMemoryTransport` for isolation
- Focus on protocol compliance and state transitions
- Test files mirror source structure: `tests/Server/ServerSessionTest.php` tests `src/Server/ServerSession.php`

## Important Implementation Notes

### Type Safety
- All files use `declare(strict_types=1);`
- Parameters and return types are strictly typed
- Use type hints on handler callables for automatic param deserialization

### Error Handling
- Protocol errors throw `Mcp\Shared\McpError`
- Transport errors throw `RuntimeException`
- Invalid parameters throw `InvalidArgumentException`
- Errors are automatically converted to JSON-RPC error responses

### Logging
- All major components accept optional PSR-3 `LoggerInterface`
- Defaults to `NullLogger` if not provided
- Examples use Monolog for demonstration

### OAuth Support
- HTTP transport includes OAuth 2.1 authorization framework
- Server-side implementation available in `Server/Auth/`
- Client-side implementation available in `Client/Auth/`
- See `examples/server_auth/` for usage

## MCP Protocol Capabilities

Servers expose capabilities through handler registration:
- **Prompts**: `prompts/list`, `prompts/get`
- **Resources**: `resources/list`, `resources/read` (+ `resources/subscribe` on legacy revisions only)
- **Tools**: `tools/call`, `tools/list`
- **Completions**: `completion/complete`
- **Logging**: `logging/setLevel` (legacy revisions; deprecated by SEP-2577)
- **Subscriptions**: `subscriptions/listen` (modern-era change-notification channel, backed by a `SubscriptionBusInterface` on HTTP)
- **Discovery**: `server/discover` (modern era; answered automatically with the same capabilities as the legacy `initialize` result)
- **Extensions** (SEP-2133 `extensions` capability map): Tasks (`tasks/get`, `tasks/update`, `tasks/cancel` via `enableTasks()`) and MCP Apps (`McpServer::ui()`, capability + `_meta` only — no new RPC)

Capabilities are automatically detected based on registered handlers and included in both the legacy initialization response and the modern `server/discover` result.

## Common Patterns

### Creating a Server (Use convenience wrapper)
1. Instantiate `McpServer` with a name
2. Register tools, prompts, and/or resources for desired capabilities
3. Call `$server->run()` to start

### Creating a Client
1. Instantiate `Client`
2. Call `$client->connect()` with command/URL and parameters
3. Returns initialized `ClientSession`
4. Use session methods to interact with server
5. Call `$client->close()` when done
