# v1 → v2 Migration Guide

This guide covers upgrading a project from `logiscape/mcp-sdk-php` v1.x to
v2. It restates the [v1 → v2 API audit](api-audit-v2.md) — the authoritative
inventory of what changed at the Composer/PHP API level — as actionable
migration steps with runnable before/after code. Each section carries the
audit's identifier (B1–B8 for breaking changes, M1–M9 for behavioral
changes) so the two documents can be read side by side.

**Most v1 code runs on v2 unchanged.** v2's defining feature — day-one
support for the `2026-07-28` "stateless core" MCP spec revision — is
delivered through version negotiation and per-request era detection, so the
wire-level protocol changes (the removed `initialize` handshake, the
per-request `_meta` envelope, new error codes, request-metadata headers,
caching hints) are applied automatically per negotiated revision. A server
or client built on the v2 API interoperates with peers on every spec
revision back to `2024-11-05` without version-specific code. This is a
smaller migration than it looks: the breaking surface is eight items, most
of them narrow, and none of them require restructuring an application. See
[Wire-level changes handled automatically](#wire-level-changes-handled-automatically).

Requirements are unchanged: PHP 8.1+, `ext-curl`, `ext-json`.

## Do you need to change anything?

Start from the symptom. Everything not listed here — and most things that
are — works without edits.

| Symptom after upgrading | Cause | Section |
| --- | --- | --- |
| `catch (\RuntimeException)` around HTTP client calls no longer catches JSON-RPC errors | HTTP errors are now typed `McpError` | [1](#1-http-client-errors-are-typed-mcperror-b1) |
| A tool handler that throws `McpError` now produces a JSON-RPC error instead of an `isError` tool result | `McpError` is treated as a protocol error | [2](#2-mcperror-thrown-in-a-tool-handler-is-a-protocol-error-b2) |
| Static analysis flags `callTool()` return-type assumptions | Return type widened to `CallToolResult\|CreateTaskResult` | [3](#3-calltool-may-return-a-task-handle-b3) |
| Calls to `listTasks()` / `getTaskResult()` fatal with unknown method | Experimental v1 Tasks API removed (SEP-2663 redesign) | [4](#4-the-experimental-tasks-api-was-redesigned-b4-b8) |
| OAuth with pre-registered credentials fails before any token request | Issuer binding is now required by default | [5](#5-pre-registered-oauth-credentials-need-an-issuer-b5) |
| A tool is missing from `listTools()` on modern HTTP sessions | Its `x-mcp-header` annotations violate SEP-2243 | [6](#6-modern-http-clients-filter-invalid-x-mcp-header-tools-b6) |
| A legacy HTTP `prompts/get` with an `ElicitationContext` callback errors `-32603` | Prompt-side input gathering is modern-only | [7](#7-elicitationcontext-in-legacy-http-promptsget-fails-loudly-b7) |
| A fragile legacy server misbehaves at connect time | The client now probes `server/discover` first | [8](#8-connection-and-timeout-behavior-m1-m2) |
| The client times out against a completely silent server | `readTimeout` now fires against silent peers too | [8](#8-connection-and-timeout-behavior-m1-m2) |
| PSR-3 warnings about deprecated MCP features appear in logs | SEP-2596/SEP-2577 deprecation warnings | [11](#11-deprecated-mcp-features-and-runtime-warnings-m8) |

## 1. HTTP client errors are typed `McpError` (B1)

In v1, a JSON-RPC error response arriving over the HTTP transport was
thrown as an opaque `RuntimeException("Critical MCP error: …")` — the
error code and data were only recoverable by parsing the message string.
In v2 the HTTP transport matches the stdio transport: JSON-RPC errors
surface as `Mcp\Shared\McpError` with `getCode()` and the error data
intact.

`McpError` extends `\Exception`, **not** `\RuntimeException` — v1 catch
blocks written against `RuntimeException` no longer match these errors.

v1 code that needs updating:

```php
try {
    $result = $session->callTool('some-tool', $args);
} catch (\RuntimeException $e) {
    // v1: "Critical MCP error: ..." — code and data lost in the string
    error_log($e->getMessage());
}
```

v2 replacement (complete script; point it at any running v2 HTTP server,
e.g. `php -S localhost:8000 examples/stateless_server.php`):

```php
<?php

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Shared\McpError;

$client = new Client();

try {
    $session = $client->connect('http://localhost:8000');
    $session->callTool('no-such-tool');
} catch (McpError $e) {
    // Typed JSON-RPC error: code, message, and data survive intact.
    echo "JSON-RPC error {$e->getCode()}: {$e->getMessage()}\n";
} finally {
    $client->close();
}
```

## 2. `McpError` thrown in a tool handler is a protocol error (B2)

In v1, an `Mcp\Shared\McpError` thrown inside an `McpServer` tool handler
was converted into an `isError: true` tool *result*. In v2 it propagates as
a JSON-RPC *protocol error* — consistent with how `McpServerException` has
always behaved. Handlers that want a tool-execution error (an `isError`
result the model can read and react to) should throw any other exception
type.

A server demonstrating both paths (save as `error_server.php`):

```php
<?php

require 'vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Shared\ErrorData;
use Mcp\Shared\McpError;

$server = new McpServer('error-demo');

$server
    // v2: McpError propagates as a JSON-RPC protocol error (-32602 here).
    ->tool('protocol-error', 'Rejects the request itself', function (): string {
        throw new McpError(new ErrorData(code: -32602, message: 'Bad input'));
    })

    // Any other exception is still an isError tool result.
    ->tool('tool-error', 'Reports a failed tool execution', function (): string {
        throw new \RuntimeException('The backend is unreachable');
    })

    ->run();
```

And a client observing the difference:

```php
<?php

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Shared\McpError;

$client = new Client();

try {
    $session = $client->connect('php', ['error_server.php']);

    try {
        $session->callTool('protocol-error');
    } catch (McpError $e) {
        echo "protocol error: {$e->getCode()} {$e->getMessage()}\n";
    }

    $result = $session->callTool('tool-error');
    echo 'isError result: ' . $result->content[0]->text . "\n";
} finally {
    $client->close();
}
```

## 3. `callTool()` may return a task handle (B3)

`ClientSession::callTool()` is declared `CallToolResult|CreateTaskResult`
in v2. The second type only ever appears when the server augments the call
as a SEP-2663 task — which requires the client to have declared the Tasks
extension first — so v1 code that never touches Tasks receives
`CallToolResult` exactly as before. Code with strict return-type
expectations (instanceof checks, static analysis) should branch:

```php
$result = $session->callTool($name, $args);

if ($result instanceof \Mcp\Types\CreateTaskResult) {
    // Task handle: poll $session->getTask($result->task->taskId).
} else {
    // CallToolResult, as in v1.
}
```

See the next section for the full task lifecycle.

## 4. The experimental Tasks API was redesigned (B4, B8)

The v1 Tasks surface was pre-release and experimental; v2 replaces it with
the settled SEP-2663 extension model **without deprecation shims** (per the
project's pre-release policy, recorded in the roadmap).

Removed from the API — calls to these now fatal or answer `-32601`:

- `ClientSession::listTasks()` and `getTaskResult()` (the `tasks/list` and
  `tasks/result` methods no longer exist; a completed task's result is
  inlined in the `tasks/get` response),
- the `tasks` capability slot, `TaskCapability`, and
  `TaskStatusNotification`,
- the stubbed `$task` parameter on
  `ElicitationContext::form()`/`url()`/`requiresForm()` (B8) — delete the
  argument; in-task input needs no per-call opt-in.

v1 code that needs replacing:

```php
// v1 (experimental, removed in v2):
$tasks  = $session->listTasks();
$result = $session->getTaskResult($taskId);
```

The v2 model: `tools/call` returns the task handle, `tasks/get` polls it
(and inlines the result when completed), `tasks/update` answers in-task
input requests, `tasks/cancel` cancels. A complete client (run from the
SDK root):

```php
<?php

require 'vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\CreateTaskResult;
use Mcp\Types\ExtensionIds;

$client = new Client();

try {
    $session = $client->connect('php', ['examples/tasks_server.php']);

    // Declaring the extension is what lets the server answer with a task.
    $session->declareExtension(ExtensionIds::TASKS);

    $result = $session->callTool('generate-report', ['topic' => 'sales']);

    if ($result instanceof CreateTaskResult) {
        $taskId = $result->task->taskId;

        do {
            usleep(($result->task->pollIntervalMs ?? 250) * 1000);
            $get = $session->getTask($taskId);
        } while ($get->task->status === 'working');

        // The completed result is inlined — there is no tasks/result call.
        echo ($get->result['content'][0]['text'] ?? $get->task->status) . "\n";
    } else {
        // Synchronous CallToolResult: server chose not to create a task.
        echo $result->content[0]->text . "\n";
    }
} finally {
    $client->close();
}
```

Server-side, task support is opt-in per tool via
`McpServer::enableTasks()` + `tool(..., taskSupport: TaskSupport::…)`. The
full lifecycle — including `input_required` tasks that gather user input
mid-task — is documented in the [Tasks extension guide](tasks.md), with a
runnable pair in [`examples/tasks_server.php`](../examples/tasks_server.php)
and [`examples/tasks_client.php`](../examples/tasks_client.php).

## 5. Pre-registered OAuth credentials need an issuer (B5)

The `2026-07-28` revision makes Authorization Server Binding normative:
pre-registered client credentials MUST be associated with the
authorization server that issued them. v2 enforces this by default —
`ClientCredentials` without an issuer are rejected before any
authorization or token request, with an error naming
`REASON_UNBOUND_CLIENT_CREDENTIALS`.

The fix is one constructor argument:

```php
<?php

require 'vendor/autoload.php';

use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Registration\ClientCredentials;

// v2: bind the credentials to the AS that issued them.
$credentials = new ClientCredentials(
    clientId: 'my-registered-client',
    clientSecret: 'secret',
    issuer: 'https://auth.example.com',   // new in v2, required by default
);

$oauth = new OAuthConfiguration(clientCredentials: $credentials);

echo "issuer-bound: {$credentials->issuer}\n";
```

If you cannot know the issuer ahead of time, the published-spec
(`2025-11-25`) behavior — pinning to the first issuer the credentials are
validated against, for the lifetime of the process — is available behind an
explicit opt-in:

```php
$oauth = new OAuthConfiguration(
    clientCredentials: new ClientCredentials(clientId: 'my-registered-client', clientSecret: 'secret'),
    allowUnboundClientCredentials: true,  // legacy published-spec behavior
);
```

Prefer binding: the unbound mode re-pins on every fresh PHP process (each
request, under PHP-FPM), which is exactly the exposure the binding rule
closes.

## 6. Modern HTTP clients filter invalid `x-mcp-header` tools (B6)

On modern (`2026-07-28`) HTTP sessions, tools whose `x-mcp-header` schema
annotations violate the SEP-2243 constraints are **excluded** from
`listTools()` results, and `callTool()` on such a tool throws
`InvalidArgumentException` before any wire traffic (a spec MUST — the
client cannot construct conformant `Mcp-Param-*` headers from an invalid
annotation set). Legacy sessions and stdio are unfiltered.

If a tool you expect is missing on a modern HTTP session, fix its
annotations server-side (the thrown message names the violation). v1
servers are unaffected — they have no `x-mcp-header` annotations.

## 7. `ElicitationContext` in legacy HTTP `prompts/get` fails loudly (B7)

In v1, a prompt callback declaring an `ElicitationContext` parameter on the
HTTP transport was injected but silently non-functional. In v2, a
*legacy-era* HTTP `prompts/get` whose callback declares an
`ElicitationContext` fails with `BadMethodCallException` (`-32603`):
prompt-side input gathering is modern-only by design — the legacy
suspend/resume store is tools-only.

Either serve such prompts to modern clients (the SEP-2322 multi-round-trip
path handles them automatically), or drop the context parameter from
prompt callbacks that must serve legacy HTTP clients.

## 8. Connection and timeout behavior (M1, M2)

**M1 — the client probes modern first.** `Client::connect()` defaults to
`protocolMode: 'auto'`: it sends a `server/discover` probe and falls back
to the legacy `initialize` handshake per the spec's detection rules. Every
conformant legacy server handles this fine (an unknown method gets
`-32601` and the client falls back). If you operate a fragile legacy
server that mishandles unknown pre-initialize requests, pin the legacy
path:

```php
<?php

require 'vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();

try {
    // Skip the server/discover probe entirely.
    $session = $client->connect('php', ['examples/simple_server.php'], protocolMode: 'legacy');

    echo $session->isModernMode() ? "modern\n" : "legacy\n";
} finally {
    $client->close();
}
```

**M2 — `readTimeout` fires against silent peers.** In v1 a configured
client `readTimeout` only fired *between* messages; a peer that never sent
anything at all could hang the client forever. In v2 the timeout also
covers the fully-silent case. Very slow servers that accidentally relied
on the dead timeout may need a larger explicit `readTimeout`.

## 9. Server-side behavioral changes (M3, M4, M5, M7)

None of these require code changes; they are listed so changed behavior
can be recognized.

- **M3 — string returns with an `outputSchema`.** An `McpServer` tool that
  declares an `outputSchema` and returns a string now produces
  JSON-encoded `TextContent` (`"hello"` — with quotes) plus
  `structuredContent`; v1 emitted the raw string and no
  `structuredContent`. This is SEP-2106's contract: with an
  `outputSchema`, the return value is structured output. Tools without an
  `outputSchema` are unchanged.
- **M4 — session persistence keeps client capabilities.**
  `HttpServerSession::toArray()` deep-normalizes `clientParams`, fixing
  in-memory session stores that silently dropped declared client
  capabilities (e.g. `elicitation: {}`) between requests.
- **M5 — `HttpServerTransport::start()` is idempotent.** A second call
  returns silently where v1 threw `RuntimeException('Transport already
  started')` (required by the stateless revision's per-request ephemeral
  sessions).
- **M7 — response adaptation clones.**
  `ServerSession::adaptResponseForClient()` adapts a shallow copy, so a
  handler-cached `Result` reused across requests (and protocol eras) keeps
  its own `resultType`/`ttlMs`/`cacheScope`.

## 10. Typed exceptions replace message sniffing (M6)

Two failure paths that could previously only be recognized by matching
exception message strings now throw typed exceptions. Messages are
unchanged, so v1 string-matching keeps working — but catch the types going
forward:

- `Mcp\Shared\UnknownMethodException` (extends
  `InvalidArgumentException`) — thrown by typed request construction for
  unknown methods.
- `Mcp\Client\Transport\ReadTimeoutException` (extends
  `RuntimeException`) — thrown on client read timeouts.

v2 also adds `Mcp\Server\Transport\TransportClosedException` (stdio EOF —
a clean-shutdown signal, not an error).

## 11. Deprecated MCP features and runtime warnings (M8)

The `2026-07-28` spec deprecates several protocol features (SEP-2596 /
SEP-2577). **Nothing stops working**: there is no wire-level deprecation
signal, and deprecated features keep functioning through the spec's
minimum twelve-month window. The SDK mirrors the spec's registry as
`Mcp\Shared\FeatureLifecycle` and does two things:

1. the affected `Types/` classes, capability slots, and feature APIs carry
   `@deprecated` docblocks (visible to IDEs and static analysis), and
2. exercising a deprecated feature on a session whose *negotiated revision*
   deprecates it emits **one PSR-3 `warning` per feature per session**
   through the logger you supplied (the default `NullLogger` discards
   them). A `2025-11-25` session exercising Sampling is exercising an
   Active feature and stays silent.

| Feature | Deprecated in | By | Migration path |
| --- | --- | --- | --- |
| Roots | `2026-07-28` | SEP-2577 | Pass directories/files via tool parameters, resource URIs, or server configuration |
| Sampling | `2026-07-28` | SEP-2577 | Integrate directly with LLM provider APIs |
| Logging (`logging/setLevel`, log notifications) | `2026-07-28` | SEP-2577 | Log to stderr for stdio transports; use OpenTelemetry for observability |
| `includeContext: "thisServer"\|"allServers"` sampling values | `2025-11-25` | SEP-2596 | Omit `includeContext` or use `"none"` |
| OAuth Dynamic Client Registration | `2026-07-28` | spec PR #2858 | Use Client ID Metadata Documents (CIMD) |

## 12. OAuth hardening inside existing flows (M9)

Standards-driven changes inside the existing authorization flows;
conformant servers are unaffected, and no application code changes are
required:

- SEP-2468: the authorization response's `iss` parameter is validated per
  RFC 9207 (error params are never acted on when `iss` fails).
- SEP-2352: the protected-resource metadata is re-fetched on 401 and on
  403 `insufficient_scope`, and tokens/credentials are never reused across
  issuers (see [section 5](#5-pre-registered-oauth-credentials-need-an-issuer-b5)).
- SEP-837: dynamic registration sends `application_type`.
- SEP-2207: `offline_access` is only requested when the server's
  `scopes_supported` advertises it.

## Wire-level changes handled automatically

Everything below is applied per negotiated revision by version negotiation
and per-request era detection. **No migration action is needed** — a v1
codebase recompiled against v2 speaks all of it correctly to `2026-07-28`
peers while serving legacy peers unchanged:

- the removed `initialize` handshake and the `server/discover` method,
- the per-request `_meta` envelope (protocol version, client info, client
  capabilities) and the sessionless HTTP lifecycle (no `Mcp-Session-Id`),
- the SEP-2243 request-metadata headers (`Mcp-Method`, `Mcp-Name`,
  `MCP-Protocol-Version`, `Mcp-Param-*`),
- SEP-2549 caching hints (`ttlMs`/`cacheScope`) and the `resultType`
  discriminator — stamped for modern clients, stripped for legacy ones,
- SEP-2164 error-code selection (`-32602` + `data.uri` vs the legacy
  `-32002` for missing resources),
- SEP-2322 multi-round-trip input (`input_required` results) replacing
  server-initiated sampling/elicitation/roots requests on the modern path,
- `subscriptions/listen` streams on the modern path; the legacy standalone
  GET SSE stream and `Last-Event-ID` resumption for legacy peers.

## New in v2 (no migration required)

The additive v2 surface — dual-era negotiation options, the Tasks and Apps
extensions, `subscriptions/listen` publishing, batch input gathering,
`server/discover`, caching hints, the `client_credentials` grant and
cross-app access, and more — is inventoried in the
[API audit's additive section](api-audit-v2.md#3-additive-surface-new-in-v2-no-migration-required)
and the [CHANGELOG](../CHANGELOG.md). Start with:

- [Server Development Guide](server-dev.md) — building servers on v2
- [Client Development Guide](client-dev.md) — building clients on v2
- [Tasks extension guide](tasks.md) and [Apps extension guide](apps.md)
- [`examples/README.md`](../examples/README.md) — a runnable example per
  major v2 feature
