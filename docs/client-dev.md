# Building MCP Clients in PHP

A comprehensive guide to developing Model Context Protocol clients using the `logiscape/mcp-sdk-php` SDK.

---

## Table of Contents

- [Introduction](#introduction)
- [Getting Started](#getting-started)
- [Part 1: Connecting to Servers](#part-1-connecting-to-servers)
- [Part 2: Calling Tools](#part-2-calling-tools)
- [Part 3: Using Prompts](#part-3-using-prompts)
- [Part 4: Reading Resources](#part-4-reading-resources)
- [Part 5: Configuring the HTTP Transport](#part-5-configuring-the-http-transport)
- [Part 6: Connecting to OAuth-Protected Servers from the CLI](#part-6-connecting-to-oauth-protected-servers-from-the-cli)
- [Part 7: OAuth in Web Hosting Environments](#part-7-oauth-in-web-hosting-environments)
- [Part 8: Handling Elicitation Requests](#part-8-handling-elicitation-requests)
- [Part 9: Notifications, Progress, and Logging](#part-9-notifications-progress-and-logging)
- [Part 10: Resuming HTTP Sessions Across Web Requests](#part-10-resuming-http-sessions-across-web-requests)
- [Appendix A: Configuration Reference](#appendix-a-configuration-reference)
- [Appendix B: Connection Recipes](#appendix-b-connection-recipes)

---

## Introduction

The [Model Context Protocol](https://modelcontextprotocol.io) (MCP) is an open standard that lets AI applications interact with external data and tools through a uniform interface. An **MCP client** is the side of that conversation that *consumes* a server's capabilities -- it discovers what tools, prompts, and resources are available and invokes them on the user's behalf.

The `logiscape/mcp-sdk-php` SDK implements both ends of the MCP specification -- including the latest `2026-07-28` "stateless core" revision -- for PHP 8.1+. On the client side it provides the `Client` and `ClientSession` classes, which together handle:

- Both transports the spec defines: **stdio** (subprocess servers) and **Streamable HTTP** (remote servers).
- **Dual-era negotiation**: `connect()` detects whether the server speaks the modern (`2026-07-28`) stateless protocol or a legacy revision (`2024-11-05` … `2025-11-25`) and speaks the right one automatically -- the probe/fallback rules, the per-request `_meta` envelope, the request-metadata headers, and the legacy `initialize` handshake are all internal. See [Negotiating Protocol Eras](#negotiating-protocol-eras).
- Server-initiated **elicitation** requests in form and URL modes, and **sampling** / **roots** servicing -- delivered as real server-initiated requests on legacy sessions and as SEP-2322 multi-round-trip `input_required` exchanges on modern ones, through the same handlers.
- The **Tasks extension** (SEP-2663) client API: task handles from `callTool()`, polling, in-task input, and cancellation -- see the [Tasks Extension Guide](tasks.md).
- The complete **OAuth 2.1** authorization-code flow with PKCE, including issuer-bound pre-registered credentials, the Client ID Metadata Document path (CIMD, `2025-11-25`), dynamic client registration (RFC 7591, deprecated upstream in favor of CIMD), the `client_credentials` grant, token storage and refresh, and a redirect-based async flow that works on stateless PHP hosting.
- **Streamable HTTP** features that matter on PHP web hosts: SSE response streams (with `retry`/`Last-Event-ID` reconnection on legacy sessions, SEP-1699), opt-out of the legacy standalone GET stream, and a session-resume API that lets client-side MCP state survive across multiple PHP requests on both eras.

This guide teaches the `2026-07-28` model as the default and explicitly
marks behavior that only exists on legacy revisions ("**legacy only**").
Most of it applies unchanged to both eras -- era differences are wire-level
and handled inside the SDK.

### What You Can Build

- A **CLI tool** that drives any MCP server (local stdio subprocess or remote HTTP) -- ideal for scripting, testing, or building developer tooling.
- A **web application** that connects browser users to MCP servers, including OAuth-protected ones, while running on traditional cPanel/Apache/PHP-FPM hosting.
- A **PHP-based MCP host** that wires user-supplied tools into your own LLM workflows.

This guide focuses on the client side of the SDK. For creating MCP servers see the [Building MCP Servers in PHP](server-dev.md) guide.

---

## Getting Started

### Requirements

- PHP 8.1 or higher
- Composer
- `ext-curl` and `ext-json` (typically enabled by default)
- `ext-openssl` (required for `FileTokenStorage` encryption and HTTPS)
- For stdio transports: `proc_open` enabled (almost always available on CLI; usually disabled on shared web hosts -- which is fine, you don't need stdio there)
- For OAuth callback handling on the CLI: `ext-sockets` (needed by `LoopbackCallbackHandler`)

### Installation

```bash
composer require logiscape/mcp-sdk-php
```

### Your First MCP Client

The simplest possible client connects to a server, lists its tools, and disconnects:

```php
<?php
// client_basic.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

// Connect to a remote MCP server. The same Client also speaks stdio --
// just pass a command instead of an HTTP(S) URL.
$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

// connect() has already negotiated the protocol era by the time it
// returns; getInitializeResult() is populated on both eras.
$initResult = $session->getInitializeResult();
echo "Connected to {$initResult->serverInfo->name} {$initResult->serverInfo->version}\n";
echo "Negotiated protocol version: {$initResult->protocolVersion}\n";

// List the tools the server exposes.
$tools = $session->listTools()->tools ?? [];
echo "Server exposes " . count($tools) . " tool(s):\n";
foreach ($tools as $tool) {
    echo "  - {$tool->name}: {$tool->description}\n";
}

$client->close();
```

A few things to know about this minimal example:

- `new Client()` creates the orchestrator. It detects whether the target is a stdio command or an HTTP(S) URL.
- `connect()` builds the transport, negotiates the protocol era (see [Negotiating Protocol Eras](#negotiating-protocol-eras)), and returns a ready-to-use `ClientSession`. Against a modern (`2026-07-28`) server that means a `server/discover` exchange — there is no handshake, and `getInitializeResult()` is synthesized from the discover result so capability-inspection code works unchanged. Against a legacy server it means the classic `initialize` handshake plus the `initialized` notification.
- The returned `ClientSession` is what you call methods on: `listTools()`, `callTool()`, `readResource()`, etc.
- `close()` tears everything down cleanly, sending an HTTP `DELETE` (or terminating the subprocess) so the server can free its session.

### Connecting to a Local Stdio Server

To launch a local MCP server as a subprocess instead, pass the command and arguments directly to `connect()`:

```php
<?php
// client_basic_stdio.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();
$session = $client->connect(
    commandOrUrl: 'php',
    args: ['/absolute/path/to/server.php']
);

$initResult = $session->getInitializeResult();
echo "Connected to {$initResult->serverInfo->name}\n";

$client->close();
```

The SDK uses the URL scheme of `commandOrUrl` to decide which transport to use:

- Anything that parses as `http://` or `https://` -> Streamable HTTP transport
- Anything else -> Stdio transport, with `commandOrUrl` as the command and `args` as its argv

---

## Part 1: Connecting to Servers

The `Client::connect()` method is overloaded for both transports. Its parameter list is shared between them, but the meaning of `args` and `env` changes depending on which one is used:

```php
public function connect(
    string $commandOrUrl,
    array $args = [],
    ?array $env = null,
    ?float $readTimeout = null,
    string $protocolMode = 'auto',
    ?float $probeTimeout = null
): ClientSession;
```

| Param | Stdio meaning | HTTP meaning |
|-------|---------------|--------------|
| `$commandOrUrl` | Executable to launch (e.g. `'php'`, `'node'`) | The MCP endpoint URL |
| `$args` | Arguments to the executable | HTTP headers (`['Authorization' => 'Bearer ...']`) |
| `$env` | Environment variables for the subprocess | HTTP transport options array (see [Part 5](#part-5-configuring-the-http-transport)) |
| `$readTimeout` | Per-request read timeout (seconds) | Per-request read timeout (seconds) |
| `$protocolMode` | `'auto'` \| `'modern'` \| `'legacy'` (see below) | Same |
| `$probeTimeout` | Seconds to wait on the `server/discover` probe before treating the server as silent-legacy | Same |

That dual meaning is why you'll see HTTP examples that pass headers as `$args` and an options array as `$env` -- the parameter names match the stdio case, but the SDK reuses the slots.

### Negotiating Protocol Eras

Two protocol *eras* exist: the modern `2026-07-28` "stateless core" (no
`initialize` handshake -- every request is self-contained and carries its
protocol metadata in `_meta`) and the legacy revisions
(`2024-11-05` … `2025-11-25`, classic handshake and, on HTTP, a session
id). `connect()` negotiates the era for you following the spec's
detection rules:

- **`protocolMode: 'auto'`** (default) -- probe with `server/discover`
  first. A modern server answers it; a legacy server answers
  `Method not found` (or an HTTP 400 with an unrecognizable body) and the
  client falls back to the `initialize` handshake. A recognized modern
  error (e.g. `UnsupportedProtocolVersion`, `-32022`) means the server
  *is* modern -- the client retries with a version the server advertised
  rather than falling back.
- **`protocolMode: 'modern'`** -- skip the probe and speak `2026-07-28`
  unconditionally. Use against servers known to be modern (or that
  mis-handle unknown methods so badly the probe can't classify them).
- **`protocolMode: 'legacy'`** -- skip the probe and go straight to
  `initialize`. Use for fragile legacy servers that mishandle unknown
  pre-initialize requests.

On HTTP, a `protocolVersion` transport option states which modern
revision to prefer (see [Part 5](#part-5-configuring-the-http-transport)).

After connect, the session knows its era:

```php
$session = $client->connect('php', ['examples/stateless_server.php']);

if ($session->isModernMode()) {
    // Stateless 2026-07-28: no session id, per-request _meta envelope.
} else {
    // Legacy: classic handshake ran; HTTP sessions carry Mcp-Session-Id.
}
echo $session->getNegotiatedProtocolVersion() . "\n";
```

Everything else in this guide works identically on both eras unless
marked otherwise -- `getInitializeResult()` is populated on modern
sessions too (synthesized from the `server/discover` result), so
capability inspection code does not need to branch. You can also call
`$session->discover()` yourself to fetch the raw `DiscoverResult`
(supported versions, capabilities, instructions, caching hints) from a
modern server.

A runnable demonstration of all three modes is
[`examples/client_negotiation.php`](../examples/client_negotiation.php).

### Discovering What the Server Supports

The `InitializeResult` returned by `getInitializeResult()` is the ground truth for what the server can do. Every capability the server didn't advertise will be `null` on the `capabilities` object:

```php
<?php
// client_capabilities.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$caps = $session->getInitializeResult()->capabilities;

if ($caps->tools !== null) {
    echo "Server supports tools (listChanged: " . var_export($caps->tools->listChanged ?? null, true) . ")\n";
}
if ($caps->prompts !== null) {
    echo "Server supports prompts\n";
}
if ($caps->resources !== null) {
    echo "Server supports resources";
    if ($caps->resources->subscribe ?? false) {
        echo " (with subscribe)";
    }
    echo "\n";
}
if ($caps->logging !== null) {
    echo "Server can emit logging messages\n";
}
if ($caps->completions !== null) {
    echo "Server supports argument completion\n";
}

$client->close();
```

Always gate your calls on the advertised capability. Calling `listTools()` against a server that didn't advertise `tools` will result in a JSON-RPC error.

### Detecting Negotiated Protocol Features

The MCP protocol has had several spec revisions, and many features (elicitation, structured content, URL elicitation, sampling-with-tools, CIMD, etc.) only exist in certain versions. The session knows which version it negotiated and can answer feature questions:

```php
$session = $client->connect('https://example.com/mcp-server.php');

// Hard version string (e.g. "2025-11-25").
$version = $session->getNegotiatedProtocolVersion();

// Boolean checks for individual features.
if ($session->supportsFeature('elicitation')) {
    // The negotiated protocol version defines elicitation/create.
}
if ($session->supportsFeature('url_elicitation')) {
    // Negotiated 2025-11-25 or newer; URL-mode elicitation is defined.
}
if ($session->supportsFeature('structured_content')) {
    // The negotiated version defines structuredContent on tool results.
}
```

`supportsFeature()` answers a single question: "is this feature defined in the negotiated protocol version?" It looks the feature up in the version-to-minimum-version table in `Mcp\Shared\Version` and does **not** look at what either side actually advertised in capabilities. To check what the server actually said it supports (e.g. `tools.listChanged`, `resources.subscribe`), inspect the capabilities object returned by the handshake:

```php
$caps = $session->getInitializeResult()->capabilities;
if ($caps->tools !== null && $caps->tools->listChanged) {
    // The server promised to send notifications/tools/list_changed.
}
```

The full feature list is in `Mcp\Shared\Version` -- e.g. `sampling`, `elicitation`, `url_elicitation`, `structured_content`, `tool_output_schema`, `progress_message`, `cimd`, `sampling_with_tools`, and the `2026-07-28` additions (`stateless_lifecycle`, `caching_hints`, `json_schema_2020_12`, `resource_not_found_invalid_params`).

---

## Part 2: Calling Tools

Tools are the primary thing a client invokes. The pattern is always the same: list, decide, call.

### Listing Tools

```php
<?php
// tools_list.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$result = $session->listTools();
foreach ($result->tools as $tool) {
    echo "- {$tool->name}\n";
    if (isset($tool->description)) {
        echo "    {$tool->description}\n";
    }

    // The input schema describes what arguments the tool expects. Each
    // property definition is forwarded as decoded JSON, which is typically
    // an associative array (servers occasionally hand back stdClass), so
    // handle both shapes when reading fields.
    if (isset($tool->inputSchema, $tool->inputSchema->properties)) {
        $required = $tool->inputSchema->required ?? [];
        foreach ($tool->inputSchema->properties as $name => $prop) {
            $req = in_array($name, $required, true) ? 'required' : 'optional';
            $type = is_array($prop)
                ? ($prop['type'] ?? 'unknown')
                : ($prop->type ?? 'unknown');
            echo "    - {$name} ({$type}, {$req})\n";
        }
    }
}

$client->close();
```

### Paginated Listings

Most MCP servers return their entire tool, prompt, or resource catalog in a single response. For servers that *do* paginate -- typically because the catalog is large enough that returning it in one shot would blow past a sensible response budget -- the response arrives with a non-null `nextCursor`, and each list method (`listTools()`, `listPrompts()`, `listResources()`, `listResourceTemplates()`) accepts that cursor as an optional argument to fetch the next page:

```php
<?php
// tools_list_paginated.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$cursor = null;
$allTools = [];
$pageCount = 0;

do {
    $page = $session->listTools($cursor);
    $pageCount++;

    foreach ($page->tools as $tool) {
        $allTools[] = $tool;
    }

    $cursor = $page->nextCursor; // null on the final page
} while ($cursor !== null);

echo "Fetched " . count($allTools) . " tool(s) across {$pageCount} page(s)\n";

$client->close();
```

The same loop works for `listPrompts()`, `listResources()`, and `listResourceTemplates()`. Treat the cursor as opaque -- it's a server-defined token, never something you construct yourself.

If you don't care about pagination (and most callers don't), calling the list methods with no argument fetches the first page and ignores `nextCursor` -- exactly the right behavior against the vast majority of servers, which return everything in that first page.

### Calling a Tool

```php
<?php
// tools_call.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\TextContent;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$result = $session->callTool('add_numbers', ['a' => 1, 'b' => 2]);

// CallToolResult always carries a `content` array.
foreach ($result->content as $block) {
    if ($block instanceof TextContent) {
        echo "Text: {$block->text}\n";
    } else {
        echo "Other content type: " . get_class($block) . "\n";
    }
}

// Tool errors don't throw -- the server returns a normal result with isError=true
// so the model can self-correct. Check for it explicitly.
if ($result->isError ?? false) {
    fwrite(STDERR, "Tool reported an error\n");
}

$client->close();
```

Two important things about tool results:

- **Content is a list of typed blocks.** Use `instanceof` to handle each variant (`TextContent`, `ImageContent`, `AudioContent`, `EmbeddedResource`, etc.). Don't assume a shape.
- **`isError` is a flag, not an exception.** When a tool's callback throws on the server side, the SDK reports it as a normal result with `isError: true`. Genuine RPC failures (network, malformed JSON, unknown method) throw a typed `Mcp\Shared\McpError` on the client -- on both transports -- with the JSON-RPC code and data intact.

### Task-Augmented Tool Calls (SEP-2663)

`callTool()` is declared `CallToolResult|CreateTaskResult`. The task
handle only ever appears when you have declared the Tasks extension
(`$session->declareExtension(ExtensionIds::TASKS)`) and the server chose
to run the call as a long-running task -- you then poll `getTask()`,
answer in-task input with `updateTask()`, and read the completed result
inlined in the final `tasks/get` response. Code that never declares the
extension always receives `CallToolResult`. The full lifecycle, with a
complete runnable client, is in the [Tasks Extension Guide](tasks.md).

### Reading Structured Content

Servers that negotiated `2025-06-18` or newer can attach a machine-readable `structuredContent` field alongside the human-readable `content` blocks. The SDK exposes it on `CallToolResult` as a plain `?array` -- whatever the server sent, forwarded as-is. Handle the null branch and don't assume keys exist:

```php
$result = $session->callTool('analyze-url', ['url' => 'https://example.com/path?q=1']);

if ($result->structuredContent !== null) {
    $data = $result->structuredContent;
    echo "Host: " . ($data['host'] ?? '(missing)') . "\n";
    echo "Path: " . ($data['path'] ?? '(missing)') . "\n";
} else {
    // Fall back to parsing the text blocks.
    foreach ($result->content as $block) {
        echo $block->text ?? '';
    }
}
```

---

## Part 3: Using Prompts

Prompts are server-supplied message templates. The user (or your application) picks one, supplies arguments, and gets back a list of messages to seed a conversation with.

### Listing Prompts

```php
<?php
// prompts_list.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$result = $session->listPrompts();
foreach ($result->prompts as $prompt) {
    echo "- {$prompt->name}\n";
    if (isset($prompt->description)) {
        echo "    {$prompt->description}\n";
    }
    if (!empty($prompt->arguments)) {
        foreach ($prompt->arguments as $arg) {
            $req = ($arg->required ?? false) ? 'required' : 'optional';
            echo "    arg: {$arg->name} ({$req}): " . ($arg->description ?? '') . "\n";
        }
    }
}

$client->close();
```

### Getting a Prompt

```php
<?php
// prompts_get.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\TextContent;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

// Prompt arguments must be strings -- they come from a UI form, not from JSON Schema.
$result = $session->getPrompt('code-review', [
    'language' => 'php',
    'code'     => "function add(\$a, \$b) { return \$a + \$b; }",
]);

echo "Description: " . ($result->description ?? '(none)') . "\n\n";

foreach ($result->messages as $message) {
    echo "[{$message->role->value}]\n";
    if ($message->content instanceof TextContent) {
        echo $message->content->text . "\n\n";
    } else {
        echo "(non-text content: " . get_class($message->content) . ")\n\n";
    }
}

$client->close();
```

The returned `GetPromptResult::$messages` is a list of `PromptMessage` objects -- each has a `role` (`Role::USER`, `Role::ASSISTANT`) and a `content` block. Feed them into your LLM as the initial conversation.

### Argument Completion

If the server advertises `completions`, you can ask it to suggest values for a prompt or resource argument as the user types:

```php
<?php
// prompts_complete.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\PromptReference;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

if ($session->getInitializeResult()->capabilities->completions === null) {
    echo "Server does not support completions.\n";
    $client->close();
    exit;
}

$result = $session->complete(
    new PromptReference('code-review'),
    ['name' => 'language', 'value' => 'p']  // user has typed "p" so far
);

foreach ($result->completion->values as $suggestion) {
    echo "Suggestion: {$suggestion}\n";
}

$client->close();
```

Use `ResourceReference` instead of `PromptReference` when completing the variables of a templated resource URI.

---

## Part 4: Reading Resources

Resources are URI-addressed pieces of context the server makes available. They might be files, database records, configuration, or live system data -- anything the server wants the model (or your application) to be able to read.

### Listing Resources

```php
<?php
// resources_list.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$result = $session->listResources();
foreach ($result->resources as $resource) {
    echo "- {$resource->name}\n";
    echo "    URI: {$resource->uri}\n";
    if (isset($resource->mimeType)) {
        echo "    MIME: {$resource->mimeType}\n";
    }
    if (isset($resource->description)) {
        echo "    Desc: {$resource->description}\n";
    }
}

$client->close();
```

### Reading a Resource

```php
<?php
// resources_read.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\TextResourceContents;
use Mcp\Types\BlobResourceContents;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$result = $session->readResource('config://app-settings');

// A single resource read can return multiple content items (e.g. a schema + sample data).
foreach ($result->contents as $content) {
    echo "URI: {$content->uri}\n";
    echo "MIME: " . ($content->mimeType ?? 'unknown') . "\n";

    if ($content instanceof TextResourceContents) {
        echo "Text:\n{$content->text}\n";
    } elseif ($content instanceof BlobResourceContents) {
        // blob is base64-encoded bytes
        $bytes = base64_decode($content->blob);
        echo "Binary data: " . strlen($bytes) . " bytes\n";
    }
    echo "---\n";
}

$client->close();
```

Always handle both `TextResourceContents` and `BlobResourceContents` -- the same URI might return either depending on what the server's resource callback produced.

### Subscribing to Resource Updates (legacy only)

The `resources/subscribe` / `resources/unsubscribe` RPCs are **legacy
only**: the `2026-07-28` revision removes them (a modern server answers
`-32601`), replacing them with per-resource events on the server's
`subscriptions/listen` channel. This SDK's client does not yet provide a
high-level `subscriptions/listen` consumer -- on modern sessions,
re-read resources you care about (honoring the `ttlMs`/`cacheScope`
hints on read results) instead of subscribing.

On a legacy session, if the server advertised `resources.subscribe`, you can ask it to notify you when a resource changes. Whether you receive those notifications depends on the transport's ability to deliver server-initiated messages -- see [Part 9](#part-9-notifications-progress-and-logging) for how to register a notification handler.

```php
<?php
// resources_subscribe.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$caps = $session->getInitializeResult()->capabilities;
if (!($caps->resources->subscribe ?? false)) {
    echo "Server does not support resource subscriptions.\n";
    $client->close();
    exit;
}

$session->subscribeResource('info://server-status');

// ... do work, receive notifications/resources/updated ...

$session->unsubscribeResource('info://server-status');

$client->close();
```

---

## Part 5: Configuring the HTTP Transport

The HTTP transport accepts a configuration array as the third argument to `Client::connect()` (the `$env` parameter). Every option has a sensible default; override only what you need.

### Common Options

```php
<?php
// http_configured.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();
$session = $client->connect(
    commandOrUrl: 'https://example.com/mcp-server.php',
    args: [
        'Authorization' => 'Bearer my-static-token',
        'X-My-App'      => 'my-php-client/1.0',
    ],
    env: [
        'connectionTimeout' => 10.0,   // seconds to establish the TCP connection
        'readTimeout'       => 30.0,   // seconds to wait for each response
        'verifyTls'         => true,   // turn off only for self-signed dev servers
        'enableSse'         => true,   // accept text/event-stream responses (default: true)
        'autoSse'           => true,   // open the standalone GET SSE stream (default: true)
    ],
);

echo "Connected: {$session->getInitializeResult()->serverInfo->name}\n";
$client->close();
```

### Choosing a Modern Protocol Version

On the modern era the client stamps a protocol version into every
request's `_meta` envelope. By default it uses the latest supported
revision; the `protocolVersion` option states a different preference
(the client still adopts a server-advertised version if the preferred
one is rejected with `-32022`):

```php
$session = $client->connect(
    'https://example.com/mcp-server.php',
    [],
    ['protocolVersion' => '2026-07-28'],
    protocolMode: 'modern',
);
```

### Disabling the Standalone GET SSE Stream (legacy only)

On **legacy** sessions, the MCP Streamable HTTP spec allows clients to open a long-lived `GET` against the endpoint that the server can use to push messages out-of-band of any active POST. The SDK opens this stream automatically after a successful legacy handshake. On modern (`2026-07-28`) sessions the standalone stream does not exist and is never opened -- `autoSse` has no effect there.

In short-lived web requests this background channel is more trouble than it's worth -- it cannot outlive the request. Pass `autoSse => false` to skip it:

```php
$session = $client->connect(
    'https://example.com/mcp-server.php',
    [],
    ['autoSse' => false],
);
```

Server -> client interleaving on the **POST** SSE response (used during a tool call that triggers elicitation, for example) still works whether or not `autoSse` is set -- it's a different mechanism.

### Custom TLS Trust

For self-signed or internal certificates, point the SDK at a custom CA bundle:

```php
$session = $client->connect(
    'https://internal.example/mcp-server.php',
    [],
    [
        'verifyTls' => true,
        'caFile'    => '/path/to/internal-ca.pem',
    ],
);
```

`verifyTls => false` is also supported but should be reserved for local development -- it disables both peer and host verification.

### SSE Reconnect Tuning (SEP-1699, legacy only)

On **legacy** sessions, when the server interrupts an SSE response with a graceful close, the client honors the `retry` field and reconnects with `Last-Event-ID` to resume the stream. (`Last-Event-ID` resumption does not exist on the modern path -- modern response streams are request-scoped and carry no event ids.) Two knobs control the reconnect policy:

```php
$session = $client->connect(
    'https://example.com/mcp-server.php',
    [],
    [
        'sseDefaultRetryDelay' => 1.0,   // delay (s) when the server omits `retry`
        'sseReconnectBudget'   => 60.0,  // total wall-clock budget (s) for reconnect attempts
    ],
);
```

These defaults are sensible for most servers; tune them only if you're working with a server that has unusual reconnect semantics.

### Adding cURL Options

For anything not exposed directly, you can pass raw cURL options:

```php
$session = $client->connect(
    'https://example.com/mcp-server.php',
    [],
    [
        'curlOptions' => [
            CURLOPT_PROXY     => 'http://corporate-proxy:8080',
            CURLOPT_USERAGENT => 'my-php-mcp-client/2.0',
        ],
    ],
);
```

These are merged into every cURL handle the transport creates.

### Transport Support: Streamable HTTP Only

The SDK's HTTP client speaks the modern **Streamable HTTP** transport: a single endpoint to which it POSTs JSON-RPC and from which it accepts plain JSON or SSE responses (plus the optional standalone GET stream described above). This is the transport the current spec defines.

It does **not** implement the deprecated **HTTP+SSE dual-endpoint** transport from the `2024-11-05` revision -- the older design where the client opened a separate long-lived `GET /sse` stream and POSTed messages to a second, distinct endpoint. The spec deprecated that transport in favor of Streamable HTTP, and this SDK targets only the modern form.

The practical consequence is narrow: this client cannot connect to a server that *only* exposes the legacy dual-endpoint transport. This is intentional and does not reduce Streamable HTTP coverage -- any server implementing the current transport works normally, and protocol-version negotiation still lets the client speak older *protocol* revisions (including `2024-11-05` message shapes) over the modern transport.

---

## Part 6: Connecting to OAuth-Protected Servers from the CLI

When an MCP server is protected with OAuth 2.1 (per the MCP spec), an unauthenticated request returns `401 Unauthorized` with a `WWW-Authenticate` header that points the client at the protected resource metadata. The SDK uses that header to:

1. Discover the protected resource metadata (RFC 9728)
2. Discover the authorization server metadata (RFC 8414 / OIDC)
3. Pick a client credential strategy: pre-registered, CIMD (`2025-11-25`), or dynamic registration (RFC 7591)
4. Run the PKCE-protected authorization-code flow
5. Exchange the code for tokens and store them

For **CLI applications** the SDK ships a `LoopbackCallbackHandler` that opens a temporary loopback HTTP server on `127.0.0.1`, opens the user's browser to the authorization URL, and captures the code from the redirect. This is the right approach for any long-running PHP process: developer CLIs, daemons, automated test harnesses, etc.

The MCP authorization spec lists three client-identification paths in priority order: **pre-registered credentials**, **Client ID Metadata Documents (CIMD)** for `2025-11-25` servers, and **Dynamic Client Registration (DCR)** as a backwards-compatibility fallback. The minimal example below uses pre-registration because the credentials are stable across invocations -- ideal for a CLI that may be re-run many times against `FileTokenStorage`. CIMD and DCR are covered in the subsections that follow.

### Minimal CLI OAuth Client (Pre-Registered)

```php
<?php
// oauth_cli.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Callback\LoopbackCallbackHandler;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Token\FileTokenStorage;

$tokenStorage = new FileTokenStorage(
    storagePath: __DIR__ . '/.oauth-tokens',
    encryptionSecret: 'your-encryption-secret-at-least-32-chars',
);

$callbackHandler = new LoopbackCallbackHandler(
    port: 0,            // auto-pick a free port
    timeout: 120,       // seconds to wait for the user
    openBrowser: true,  // try to open the URL automatically
);

// Register an OAuth client with your authorization server out of band, then
// supply the issued client_id (and client_secret if it gave you one) here.
// `issuer` names the authorization server the credentials were registered
// with; the SDK refuses to present them to any other server.
$credentials = new ClientCredentials(
    clientId: 'my-mcp-cli',
    clientSecret: 'super-secret-string',
    tokenEndpointAuthMethod: ClientCredentials::AUTH_METHOD_AUTO,
    issuer: 'https://auth.example.com',
);

$oauthConfig = new OAuthConfiguration(
    clientCredentials: $credentials,
    tokenStorage: $tokenStorage,
    authCallback: $callbackHandler,
);

$client = new Client();

// First run: the SDK opens the user's browser to authorize, then stores tokens
// in $tokenStorage. Re-runs of this script reuse the persisted tokens (and
// auto-refresh them when they near expiry) so the browser does not reopen.
$session = $client->connect(
    commandOrUrl: 'https://example.com/mcp-server.php',
    args: [],
    env: ['oauth' => $oauthConfig],
);

echo "Authenticated and connected to {$session->getInitializeResult()->serverInfo->name}\n";

// Do work...
foreach ($session->listTools()->tools as $tool) {
    echo "- {$tool->name}\n";
}

$client->close();
```

A few things to note:

- **`AUTH_METHOD_AUTO` is usually the right choice.** It lets the SDK pick `client_secret_post`, `client_secret_basic`, or `none` based on the authorization server's metadata. Override it explicitly only if you have a specific reason.
- **`issuer` is required on pre-registered credentials.** Credentials belong to exactly one authorization server, and the spec requires clients to key them by issuer. With `issuer` set, the SDK raises a clear error — instead of leaking your `client_id`/`client_secret` — if the MCP server's metadata ever points at a different authorization server (a migration, or a hostile server). By default the SDK rejects pre-registered credentials that omit it. If you must interoperate with a setup where the issuer genuinely isn't known in advance, set `OAuthConfiguration::$allowUnboundClientCredentials` to `true` to restore the older published-spec (2025-11-25) behavior: the credentials are pinned to the first authorization server discovery validates, but only for the current PHP process — the pin cannot protect the next request on per-request runtimes like PHP-FPM.
- **Always use `FileTokenStorage` outside of trivial scripts.** The default `MemoryTokenStorage` only persists tokens for the lifetime of the PHP process, so the next run would re-prompt the user.
- **Encrypt token files.** Pass an encryption secret to `FileTokenStorage` so a dropped backup or rogue cron job can't lift refresh tokens off disk. The cipher is AES-256-GCM with a SHA-256-derived key.
- **Auto-refresh is on by default.** When `OAuthConfiguration::$autoRefresh` is `true` (the default) the SDK refreshes tokens within `refreshBuffer` seconds (default 60) of expiry, transparently to your code.
- **DCR credentials live for the process, not the disk.** If you swap `clientCredentials` out for `enableDynamicRegistration: true`, the `client_id`/`client_secret` returned by the authorization server are cached in memory inside the active `OAuthClient` for the remainder of that PHP process. They are **not** written to `TokenStorageInterface`. So a long-running daemon is fine, but a CLI that runs to completion and exits will lose those credentials -- and the next invocation's refresh attempt will fail because the stored refresh token is bound to a client the AS has already issued away from. Across invocation boundaries (CLI re-runs *or* stateless web requests), prefer pre-registration or CIMD. If you must use DCR there, capture `clientId`/`clientSecret` from the `AuthorizationRequest` you stored during the redirect flow and feed them back into `OAuthConfiguration(clientCredentials: ...)` on every subsequent run -- the bundled `webclient/` reference implementation shows the full pattern.

### Pointing CIMD at a Hosted Metadata Document

On `2025-11-25` authorization servers that advertise `client_id_metadata_document_supported`, you can skip dynamic registration entirely by hosting a static client metadata JSON file and passing its URL as both the client ID and the discovery hint:

```php
$oauthConfig = new OAuthConfiguration(
    tokenStorage: $tokenStorage,
    authCallback: $callbackHandler,
    enableCimd: true,
    cimdUrl: 'https://my-app.example.com/mcp-client-metadata.json',
);
```

That JSON file should contain the same fields you would have submitted via DCR (redirect URIs, client name, scopes, etc.). When CIMD is supported by the AS, the URL itself acts as your client identifier.

**This is the recommended path for stateless PHP web hosting.** Because the CIMD URL *is* the client identifier, there is nothing per-process to register or persist -- token refresh on a fresh PHP request just works, with only the tokens themselves needing storage via `TokenStorageInterface`. The SDK's defaults (`enableCimd: true`, `enableDynamicRegistration: true`) try CIMD first when the AS supports it and only fall through to DCR otherwise; setting `cimdUrl` is what activates the CIMD path.

`ClientIdMetadataDocument` (in `Mcp\Client\Auth\Registration`) is a small helper that builds the JSON document for you to host:

```php
use Mcp\Client\Auth\Registration\ClientIdMetadataDocument;

$doc = new ClientIdMetadataDocument(
    clientIdUrl: 'https://my-app.example.com/mcp-client-metadata.json',
    clientName: 'My MCP Client',
    redirectUris: ['https://my-app.example.com/oauth/callback'],
);

file_put_contents('/var/www/public/mcp-client-metadata.json', $doc->toJson());
```

The document must be served over HTTPS, publicly reachable, and the `client_id` field inside it must match the URL exactly.

### Working with Older Servers (MCP 2025-03-26)

The MCP spec made the move from "OAuth-on-the-MCP-server" to "OAuth via separate authorization server" between 2025-03-26 and 2025-06-18. If you need to talk to a 2025-03-26 server, opt into the legacy fallback, which derives the AS metadata from the MCP server's URL when RFC 9728 discovery isn't available:

```php
$oauthConfig = new OAuthConfiguration(
    tokenStorage: $tokenStorage,
    authCallback: $callbackHandler,
    enableLegacyOAuthFallback: true,
);
```

Leave this at the default (`false`) for any server that targets 2025-06-18 or newer.

---

## Part 7: OAuth in Web Hosting Environments

The CLI flow above relies on a long-running process that can spin up a loopback server and block waiting for the user. Neither of those things is true in a typical web request:

- A PHP request lasts seconds, not minutes.
- The authorization server redirects the **user's browser**, not your PHP process.
- The redirect comes back to a *different* PHP request entirely (usually a dedicated callback URL).

The SDK handles this with a two-phase async flow:

1. **Initiation phase.** Your callback handler throws an `AuthorizationRedirectException` that carries the authorization URL plus all the state needed to complete the flow later. Your application catches it, persists the state in `$_SESSION`, and redirects the browser.
2. **Completion phase.** When the browser hits your callback endpoint, you re-hydrate the persisted state into an `AuthorizationRequest` and call `OAuthClient::exchangeCodeForTokens()` to swap the code for tokens. Then redirect the browser back to the page that started the flow, which retries the original `connect()` -- this time tokens are stored, so it succeeds silently.

### A Web-Compatible Callback Handler

The SDK's `LoopbackCallbackHandler` is CLI-only. For web you need a tiny handler that throws the redirect exception instead of trying to open a socket. The full reference implementation lives at `webclient/lib/WebCallbackHandler.php`; here is the same idea condensed for re-use in your own application:

```php
<?php
// MyWebCallbackHandler.php
declare(strict_types=1);

use Mcp\Client\Auth\Callback\AuthorizationCallbackInterface;
use Mcp\Client\Auth\Exception\AuthorizationRedirectException;

final class MyWebCallbackHandler implements AuthorizationCallbackInterface
{
    public function __construct(private readonly string $callbackUrl) {}

    public function authorize(string $authUrl, string $state): string
    {
        // The real return value never happens -- we hand control back to
        // the application via this exception. The application redirects the
        // browser to $authUrl and resumes after the callback fires.
        throw new AuthorizationRedirectException(
            authorizationUrl: $authUrl,
            state: $state,
            redirectUri: $this->callbackUrl,
        );
    }

    public function getRedirectUri(): string
    {
        return $this->callbackUrl;
    }
}
```

Pass that handler into the `OAuthConfiguration` exactly as you would `LoopbackCallbackHandler`.

### Phase 1: The Connect Endpoint

```php
<?php
// connect.php -- POST endpoint that opens the MCP connection
declare(strict_types=1);
session_start();
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/MyWebCallbackHandler.php';

use Mcp\Client\Client;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Exception\AuthorizationRedirectException;
use Mcp\Client\Auth\Token\FileTokenStorage;

$tokenStorage = new FileTokenStorage(
    storagePath: __DIR__ . '/var/tokens/' . session_id(),
    encryptionSecret: getenv('TOKEN_ENC_SECRET'),
);

$callbackUrl = 'https://my-app.example.com/oauth_callback.php';

// Stateless web hosting: use CIMD so the URL itself is the stable client_id.
// Nothing per-process to register or persist; only the tokens themselves are
// stored (in $tokenStorage above). See the CIMD section in Part 6 for how to
// build and host the metadata document. If your AS only supports DCR, see
// `webclient/lib/SessionStore.php` for the credential-persistence pattern.
$oauthConfig = new OAuthConfiguration(
    tokenStorage: $tokenStorage,
    authCallback: new MyWebCallbackHandler($callbackUrl),
    cimdUrl: 'https://my-app.example.com/mcp-client-metadata.json',
);

$client = new Client();
try {
    $session = $client->connect(
        commandOrUrl: 'https://example.com/mcp-server.php',
        args: [],
        env: ['oauth' => $oauthConfig, 'autoSse' => false],
    );

    // Authenticated! Do whatever this endpoint needs and close. close() sends
    // the HTTP DELETE that drops the server-side MCP session, so subsequent
    // requests will reconnect from scratch (the persisted tokens make that
    // silent). To keep the same MCP session alive across requests instead,
    // snapshot transport state and call $client->detach() -- see Part 10.
    $tools = $session->listTools()->tools;
    $client->close();
    echo json_encode(['status' => 'ok', 'toolCount' => count($tools)]);

} catch (AuthorizationRedirectException $e) {
    // Stash the in-flight authorization request so the callback endpoint can
    // pick it up and exchange the code for tokens.
    $authReq = $e->getAuthorizationRequest();
    $_SESSION['pending_oauth'][$e->state] = [
        'authorizationRequest' => $authReq?->toArray(),
        'serverUrl'            => 'https://example.com/mcp-server.php',
    ];

    // Redirect the user's browser to the authorization server.
    header('Location: ' . $e->authorizationUrl);
    exit;
}
```

A few subtleties:

- The `state` parameter is the SDK-generated CSRF token. Use it as your storage key so the callback can find the right pending request.
- `getAuthorizationRequest()` returns a value object that contains the `code_verifier`, redirect URI, token endpoint, and resolved client credentials. You **must** persist it -- it's needed for the token exchange in phase 2.
- The exception may also be thrown later in the lifecycle (e.g. when a stored token has expired or lacks a required scope). The same handling applies wherever you catch it.

### Phase 2: The Callback Endpoint

```php
<?php
// oauth_callback.php -- the redirect_uri the AS calls back to
declare(strict_types=1);
session_start();
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Auth\AuthorizationRequest;
use Mcp\Client\Auth\OAuthClient;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Registration\ClientCredentials;
use Mcp\Client\Auth\Token\FileTokenStorage;

$code  = $_GET['code']  ?? null;
$state = $_GET['state'] ?? null;
if ($code === null || $state === null || !isset($_SESSION['pending_oauth'][$state])) {
    http_response_code(400);
    exit('Invalid OAuth callback');
}

$pending = $_SESSION['pending_oauth'][$state];
unset($_SESSION['pending_oauth'][$state]);

$authRequest = AuthorizationRequest::fromArray($pending['authorizationRequest']);

$tokenStorage = new FileTokenStorage(
    storagePath: __DIR__ . '/var/tokens/' . session_id(),
    encryptionSecret: getenv('TOKEN_ENC_SECRET'),
);

// Build a minimal config just for the code exchange. The AuthorizationRequest
// recorded which issuer the flow ran against; carry it onto the credentials.
$oauthConfig = new OAuthConfiguration(
    clientCredentials: new ClientCredentials(
        clientId: $authRequest->clientId,
        clientSecret: $authRequest->clientSecret,
        tokenEndpointAuthMethod: $authRequest->tokenEndpointAuthMethod,
        issuer: $authRequest->issuer,
    ),
    tokenStorage: $tokenStorage,
);

$oauthClient = new OAuthClient($oauthConfig);
$oauthClient->exchangeCodeForTokens($authRequest, $code);

// Tokens are now stored against $authRequest->resourceUrl. Send the user back
// to the page that started the flow, which will retry connect() and succeed.
header('Location: /index.php?oauth=success');
exit;
```

After the redirect, the next call to `Client::connect()` finds the access token in storage, attaches it as a `Bearer` header, and the request goes through without ever raising `AuthorizationRedirectException` again.

> **Heads up (CIMD on shared hosting):** When CIMD is enabled the AS pulls your `cimdUrl` document directly. That URL must be publicly reachable from the AS, served over HTTPS, and never gated behind authentication.

---

## Part 8: Handling Elicitation Requests

Elicitation (introduced in `2025-06-18`, extended with URL mode in `2025-11-25`) is the protocol mechanism a server uses to ask the **user** -- via the client -- for additional information mid-tool-call. The client side of that conversation is your job: when the server asks, your handler runs, presents UI (or makes a decision), and returns the response.

The wire shape differs by era, but your handler doesn't: on **legacy**
sessions the server sends a real `elicitation/create` request; on
**modern** (`2026-07-28`) sessions the tool call answers with an
`input_required` result (SEP-2322) and the SDK's multi-round-trip loop
runs your handler for each pending elicitation, then retries the call
with the answers attached (echoing the signed `requestState` verbatim,
with a 16-round safety cap). Either way, registering the handler is what
advertises the `elicitation` capability -- without it, a spec-compliant
server will not elicit, and a modern server whose tool *requires* input
rejects the call with `-32021`.

### Registering an Elicitation Handler

The handler is registered on the `Client` *before* `connect()` is called, so the elicitation capability is advertised in the handshake:

```php
<?php
// elicitation_basic.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;

$client = new Client();

$client->onElicit(static function (ElicitationCreateRequest $req): ElicitationCreateResult {
    // For this demo, accept everything with empty content. The applyDefaults
    // flag below will fill in any defaults the server's schema specified.
    fwrite(STDERR, "Server asked: {$req->message}\n");
    return new ElicitationCreateResult(action: 'accept', content: []);
}, applyDefaults: true);

$session = $client->connect('https://example.com/mcp-server.php');

// Any tool call that triggers elicitation will route through the handler above.
$result = $session->callTool('test_client_elicitation_defaults', []);
foreach ($result->content as $block) {
    echo $block->text ?? '';
    echo "\n";
}

$client->close();
```

The three valid actions are:

- `'accept'` -- the user provided a response; `content` holds it.
- `'decline'` -- the user explicitly chose not to provide a response.
- `'cancel'` -- the user cancelled the whole interaction.

If your handler throws, the SDK catches it and sends back an internal-error response (`-32603`) so the server can recover gracefully.

### Form Mode (Inline Structured Input)

Form mode is the common case: the server sends a JSON Schema describing the fields it wants, and the client renders a form. A real CLI handler might prompt the user; a web handler would render an HTML form. Here is a CLI handler that prompts on stdin:

```php
<?php
// elicitation_form_cli.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\ElicitationCreateRequest;
use Mcp\Types\ElicitationCreateResult;

$client = new Client();

$client->onElicit(static function (ElicitationCreateRequest $req): ElicitationCreateResult {
    // URL-mode requests have no schema -- handle them separately (next section).
    if ($req->mode === 'url') {
        fwrite(STDERR, "Server asked us to handle a URL flow; declining for this demo.\n");
        return new ElicitationCreateResult(action: 'decline');
    }

    fwrite(STDERR, "\n=== Server question ===\n{$req->message}\n");

    $properties = $req->requestedSchema['properties'] ?? [];
    $required   = $req->requestedSchema['required']   ?? [];

    $content = [];
    foreach ($properties as $name => $prop) {
        $title = $prop['title'] ?? $name;
        $type  = $prop['type']  ?? 'string';
        $isReq = in_array($name, $required, true);
        $hint  = $isReq ? '*' : ' ';

        // Show enum options if present.
        if (!empty($prop['enum'])) {
            $hint .= ' (' . implode('|', $prop['enum']) . ')';
        }

        fwrite(STDERR, "{$hint}{$title}: ");
        $line = trim((string) fgets(STDIN));

        if ($line === '' && !$isReq) {
            continue; // skip optional fields the user left blank
        }

        // Coerce to the right PHP type.
        $content[$name] = match ($type) {
            'integer' => (int) $line,
            'number'  => (float) $line,
            'boolean' => in_array(strtolower($line), ['1', 'true', 'yes', 'y'], true),
            default   => $line,
        };
    }

    return new ElicitationCreateResult(action: 'accept', content: $content);
});

$session = $client->connect('https://example.com/mcp-server.php');
$result = $session->callTool('archive-project', ['projectId' => 'demo-1']);
foreach ($result->content as $block) {
    echo ($block->text ?? '') . "\n";
}
$client->close();
```

A few things to know about form-mode schemas:

- The schema is always a flat object whose properties are primitives, single-select enums, or **multi-select enums expressed as `array` of enum `items`**. No nested objects.
- The keys in your `content` array must match the schema's `properties` keys.
- If the user explicitly declines (`'decline'`) or cancels (`'cancel'`), set `content` to `null` -- the server's tool can react accordingly.

### Auto-Filling Defaults (SEP-1034)

When the schema's properties carry per-field `default` values, you can let the SDK fill them in automatically for you. Pass `applyDefaults: true` when registering the handler:

```php
$client->onElicit(static function (ElicitationCreateRequest $req): ElicitationCreateResult {
    // Render a form and collect what the user actually filled in. Anything
    // they didn't fill in -- but that has a `default` in the schema -- will
    // be auto-populated by the SDK before the response goes back to the server.
    // For this minimal example we hard-code a partial submission; a real
    // handler would build $submitted from a CLI prompt, web form, etc.
    $submitted = ['name' => 'Jane Doe'];

    return new ElicitationCreateResult(
        action: 'accept',
        content: $submitted,
    );
}, applyDefaults: true);
```

`applyDefaults` only kicks in on `accept` responses, never overwrites a value you supplied, and is silently advertised in the handshake's elicitation capability so the server knows it can omit defaults from its server-side validation.

### URL Mode (Out-of-Band Flows)

URL-mode requests carry a URL the user must visit out-of-band -- typically to consent to an OAuth flow, enter a sensitive credential, or complete a payment. The client never sees what the user types. Per the MCP spec, the response action signals **what the user decided about opening the URL**, not whether the out-of-band interaction has finished:

1. Show the URL to the user and gather explicit consent.
2. If the user consents, open the URL (or instruct them to) and immediately respond with `'accept'`. This tells the server "the user has consented; the out-of-band interaction has begun." It does **not** mean the OAuth/payment/credential flow has completed.
3. If the user refuses or dismisses, respond with `'decline'` or `'cancel'`.

Completion of the out-of-band flow is communicated separately. The server may push a `notifications/elicitation/complete` notification carrying the original `elicitationId` once the interaction finishes, and may also surface a `URLElicitationRequiredError` (code `-32042`) on a subsequent tool call until the user has actually completed the flow.

URL mode must be opted into at handler-registration time so the SDK advertises the `url` sub-capability in the handshake. By default `onElicit()` only advertises `form`, and a spec-compliant server will not send URL-mode requests to a client that hasn't declared support. Pass `supportsUrlMode: true` to enable it:

```php
$client->onElicit(static function (ElicitationCreateRequest $req): ElicitationCreateResult {
    if ($req->mode === 'url') {
        fwrite(STDERR, "\n=== Action required ===\n{$req->message}\n\n");
        fwrite(STDERR, "Open this URL: {$req->url}\n");
        fwrite(STDERR, "Press Enter to confirm you will open the URL (or type 'cancel'): ");

        $line = trim((string) fgets(STDIN));
        if (strtolower($line) === 'cancel') {
            return new ElicitationCreateResult(action: 'cancel');
        }
        // 'accept' here signals user consent to begin the out-of-band flow,
        // not that the OAuth/payment/credential entry is finished. The server
        // tracks completion separately via notifications/elicitation/complete
        // and/or a -32042 URLElicitationRequiredError on a subsequent call.
        return new ElicitationCreateResult(action: 'accept');
    }

    // Fall through to the form-mode logic from the previous example...
    return new ElicitationCreateResult(action: 'decline');
}, supportsUrlMode: true);
```

In a web client, "open this URL" usually means embedding it as a button and waiting for the user to click back into the app. The pattern is identical to the OAuth redirect flow in [Part 7](#part-7-oauth-in-web-hosting-environments).

> **Security:** Per the MCP spec, clients **MUST NOT** auto-fetch or auto-open the URL. Always show the full URL to the user, gather explicit consent, and open it in a context that prevents the client or LLM from inspecting the page (a separate browser tab, `SFSafariViewController`, etc.).

---

## Part 9: Notifications, Progress, and Logging

Notifications are one-way messages the server sends without expecting a response. The SDK's high-level methods (`callTool()`, `listResources()`, etc.) run a tiny receive loop that surfaces these to the handlers you've registered.

Where they can arrive differs by era. On **legacy** HTTP sessions,
notifications ride the in-flight request's SSE response and the
standalone GET stream. On **modern** (`2026-07-28`) sessions there is no
standalone stream -- notifications arrive only on the request-scoped SSE
response of the call that produced them (over stdio, both eras deliver
freely). The modern standing channel for between-request change events
is the server's `subscriptions/listen`; this SDK's client does not yet
provide a high-level consumer for it, so on modern sessions poll or
re-list when freshness matters, honoring the `ttlMs` caching hints.

### Registering a Notification Handler

```php
<?php
// notifications.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\ServerNotification;
use Mcp\Types\LoggingMessageNotification;
use Mcp\Types\ProgressNotification;
use Mcp\Types\ToolListChangedNotification;
use Mcp\Types\ResourceUpdatedNotification;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$session->onNotification(static function (ServerNotification $wrapper): void {
    $note = $wrapper->getNotification();

    if ($note instanceof LoggingMessageNotification) {
        $level  = $note->params->level->value;
        $logger = $note->params->logger ?? '(unknown)';
        $data   = $note->params->data;
        fwrite(STDERR, "[server log][{$logger}][{$level}] " . json_encode($data) . "\n");
        return;
    }

    if ($note instanceof ProgressNotification) {
        $p = $note->params;
        $pct = $p->total ? round($p->progress / $p->total * 100, 1) : null;
        fwrite(STDERR, "Progress: {$p->progress}" .
            ($p->total !== null ? "/{$p->total}" : '') .
            ($pct !== null ? " ({$pct}%)" : '') .
            ($p->message !== null ? " {$p->message}" : '') . "\n");
        return;
    }

    if ($note instanceof ToolListChangedNotification) {
        fwrite(STDERR, "Tool list changed -- consider re-running listTools().\n");
        return;
    }

    if ($note instanceof ResourceUpdatedNotification) {
        fwrite(STDERR, "Resource updated: {$note->params->uri}\n");
        return;
    }
});

// Now do work; any notifications the server emits while we're talking to it
// will route through the handler above.
$session->callTool('long-running-operation', []);

$client->close();
```

The full set of notifications the SDK can dispatch:

| Notification class | Server method |
|--------------------|---------------|
| `ProgressNotification` | `notifications/progress` |
| `LoggingMessageNotification` | `notifications/message` |
| `ResourceListChangedNotification` | `notifications/resources/list_changed` |
| `ResourceUpdatedNotification` | `notifications/resources/updated` |
| `PromptListChangedNotification` | `notifications/prompts/list_changed` |
| `ToolListChangedNotification` | `notifications/tools/list_changed` |
| `CancelledNotification` | `notifications/cancelled` |

### Setting the Server's Log Level (legacy only)

On a **legacy** session, if the server advertised `logging`, you can ask it to send messages at or above a given severity:

```php
use Mcp\Types\LoggingLevel;

$session->setLoggingLevel(LoggingLevel::INFO);
```

Other levels: `DEBUG`, `NOTICE`, `WARNING`, `ERROR`, `CRITICAL`, `ALERT`, `EMERGENCY`.

The `2026-07-28` revision removes `logging/setLevel` (a modern server
answers `-32601`); a modern client states its minimum level per-request
via the deprecated `io.modelcontextprotocol/logLevel` `_meta` key
instead. The Logging feature as a whole is deprecated by SEP-2577 --
calling `setLoggingLevel()` emits one PSR-3 deprecation warning per
session (see the
[Migration Guide](migration-v2.md#11-deprecated-mcp-features-and-runtime-warnings-m8)).

### Asking for Progress Updates

To get progress notifications during a long tool call, attach a `progressToken` to the call's `_meta`. The SDK doesn't yet expose a high-level helper for this on `callTool()` -- the typical pattern is to set up the listener via `onNotification()` (above) and let the server emit progress on its own schedule.

For the *opposite* direction -- sending progress *to* the server while you're processing a server-initiated request -- use `$session->sendProgressNotification(...)`.

### Protocol-Level Pings (legacy only)

The `2026-07-28` revision removes the `ping` method along with the rest
of the connection-oriented lifecycle -- a modern server answers it
`-32601`. On a modern session, any self-contained request (a cheap
`tools/list`, or `discover()`) serves as the liveness check instead.

On **legacy** sessions: MCP defines a `ping` request/response pair at the protocol level, separate from any application "ping" tool a server might expose. It's a no-argument health check: send `ping`, the peer is required to respond with an empty result, and a missing or slow response tells you the connection isn't healthy. Use it to verify a session is still live before kicking off expensive work, to keep an idle stdio subprocess from being killed by an inactivity reaper, or as a smoke test in your test suite.

```php
<?php
// ping.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;

$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');

$start = microtime(true);
$session->sendPing();                       // returns Mcp\Types\EmptyResult
$rttMs = (microtime(true) - $start) * 1000;

printf("Server is alive (round trip: %.1f ms)\n", $rttMs);

$client->close();
```

`sendPing()` returns an `EmptyResult` on success and throws `Mcp\Shared\McpError` if the server returns an error response or `RuntimeException` if the transport fails. There is nothing to inspect on the result -- the value of a ping is that it returned at all. The SDK auto-handles incoming `ping` requests on both sides of the connection, so you do not need to register a handler to respond to a server-initiated ping.

### Cancelling In-Flight Requests

MCP cancellation is a **cooperative notification**. Either side can send a `notifications/cancelled` carrying the `requestId` of an in-flight request to signal "stop working on this." There is no acknowledgement, no guarantee the peer was able to abort cleanly, and no rollback -- it is a hint, not a contract. Per the [spec](https://modelcontextprotocol.io/specification/2025-11-25/basic/utilities/cancellation), the receiver SHOULD stop processing the cancelled request, free its associated resources, and **not send a response** for it. The receiver MAY also ignore the cancel entirely if the request is unknown, has already completed, or cannot be cancelled. Late responses are tolerated as a race: if your cancel and the peer's in-flight response cross on the wire, the *sender* of the cancel SHOULD discard whichever response arrives.

The SDK ships the wire format and dispatch path; deciding *when* to cancel and *what to do* on receipt is application logic.

#### Sending a Cancel from the Client

To cancel an outbound request, send a `CancelledNotification` referencing the same `requestId` the SDK assigned to the original send. The catch is that the high-level convenience methods (`callTool()`, `listTools()`, etc.) block until the response arrives, so issuing a cancel from the same PHP process means doing it from a separate code path -- typically a signal handler on a long-running stdio client, or a separate HTTP request that targets the same MCP session via `resumeHttpSession()` (see [Part 10](#part-10-resuming-http-sessions-across-web-requests)).

The web-style flow looks like this. The first request stashes the in-flight request ID alongside the snapshot needed to resume the session; a second request loads both back, builds the cancel notification, and fires it:

```php
<?php
// page1.php -- starts a long-running tool call and persists the request ID
declare(strict_types=1);
session_start();
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Transport\StreamableHttpTransport;

$client = new Client();
$session = $client->connect(
    commandOrUrl: 'https://example.com/mcp-server.php',
    args: [],
    env: ['autoSse' => false],
);

// Capture the request ID *before* sending. getNextRequestId() returns the
// integer the SDK will assign to the next sendRequest() call, which is
// exactly the value the cancel needs to reference.
$_SESSION['inflight_request_id'] = $session->getNextRequestId();

// Kick off the long-running call. (In a real app you'd run this in a way
// that doesn't block the request -- a queue worker, a fork, etc. The
// snapshot below assumes a separate worker is now driving the session.)
// $session->callTool('long-running-search', ['query' => 'widgets']);

// Snapshot the session so the cancel endpoint can resume into it.
$transport = $client->getTransport();
if ($transport instanceof StreamableHttpTransport) {
    $_SESSION['mcp'] = [
        'sessionManagerState' => $transport->getSessionManager()->toArray(),
        'initResult'          => json_encode(
            $session->getInitializeResult(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        'protocolVersion'     => $session->getNegotiatedProtocolVersion(),
        'nextRequestId'       => $session->getNextRequestId(),
        'serverUrl'           => 'https://example.com/mcp-server.php',
    ];
}
$client->detach();
```

```php
<?php
// cancel.php -- a separate HTTP request that aborts the in-flight call
declare(strict_types=1);
session_start();
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\CancelledNotification;
use Mcp\Types\RequestId;

if (!isset($_SESSION['mcp'], $_SESSION['inflight_request_id'])) {
    http_response_code(400);
    exit('No in-flight request to cancel');
}

$snap = $_SESSION['mcp'];

$client = new Client();
$session = $client->resumeHttpSession(
    url: $snap['serverUrl'],
    sessionManagerState: $snap['sessionManagerState'],
    initResultData: json_decode($snap['initResult'], true, flags: JSON_THROW_ON_ERROR),
    negotiatedProtocolVersion: $snap['protocolVersion'],
    nextRequestId: (int) $snap['nextRequestId'],
    headers: [],
    httpOptions: ['autoSse' => false],
);

$session->sendNotification(new CancelledNotification(
    requestId: new RequestId((int) $_SESSION['inflight_request_id']),
    reason: 'User clicked cancel',
));

unset($_SESSION['inflight_request_id']);
$client->detach();
```

`sendNotification()` returns immediately -- there is no response to wait for. Whether the in-flight request actually stops depends entirely on the server: a spec-compliant server that polls for cancellation will short-circuit and (per the spec SHOULD) suppress its response; a server that doesn't poll will run to completion as if no cancel had arrived and send a normal response anyway. Your code should be ready for either: discard whichever response comes back after the cancel was sent, since the response that does arrive is a tolerated race rather than the cancel succeeding or failing. The `reason` field is optional and propagated to the server for logging. For long-running stdio clients the same `sendNotification()` call works; the difference is just that you can capture the cancel signal from `pcntl_signal` rather than going through session-resume gymnastics.

#### Reacting to a Server-Initiated Cancel

A server can cancel a request *it* sent to *you* -- for example, a `sampling/createMessage` it issued during a long tool call -- by sending the same notification in the opposite direction. Register a handler with `onNotification()` and dispatch on the typed notification class:

```php
$session->onNotification(static function (\Mcp\Types\ServerNotification $wrapper): void {
    $note = $wrapper->getNotification();
    if (!($note instanceof \Mcp\Types\CancelledNotification)) {
        return; // Some other notification -- ignore here.
    }

    $cancelledRequestId = $note->requestId->getValue();
    $reason = $note->reason ?? '(no reason given)';

    fwrite(STDERR, "Server cancelled request #{$cancelledRequestId}: {$reason}\n");

    // If you have a long-running handler keyed by request ID, set its
    // cancellation flag here; otherwise just log and move on.
});
```

The `requestId` is wrapped in a `RequestId` value object; call `->getValue()` to read the numeric ID. Cancellation handlers should be tolerant: as the spec notes, the notification can arrive after the work has already finished, so a cancel for an unknown request ID is normal and should be silently ignored.

### Servicing Sampling Requests

A server tool can ask the **client's LLM** to generate a completion on
its behalf (`sampling/createMessage`). Register a handler with
`Client::onSampling()` **before** `connect()` -- registration is what
advertises the `sampling` capability, and the same handler services both
wire shapes: real server-initiated requests on legacy sessions, and
`sampling/createMessage` entries in modern `input_required` exchanges.

```php
<?php
// sampling_handler.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\CreateMessageRequest;
use Mcp\Types\CreateMessageResult;
use Mcp\Types\Role;
use Mcp\Types\TextContent;

$client = new Client();

$client->onSampling(static function (CreateMessageRequest $req): CreateMessageResult {
    // A real host would run the request through its configured LLM,
    // optionally with user review. $req carries messages, maxTokens,
    // an optional systemPrompt, and advisory modelPreferences.
    return new CreateMessageResult(
        content: new TextContent(text: 'A completion produced by your LLM.'),
        model: 'my-model',
        role: Role::ASSISTANT,
    );
});

$session = $client->connect('https://example.com/mcp-server.php');
$result = $session->callTool('summarize', ['text' => 'A very long document...']);
echo $result->content[0]->text . "\n";
$client->close();
```

> **Deprecation note (SEP-2577):** the Sampling feature (like Roots and
> Logging) is deprecated as of `2026-07-28` -- it keeps working through
> the spec's minimum twelve-month window, and servicing it on a
> `2026-07-28` session emits one PSR-3 warning per session through the
> logger you supplied. See the
> [Migration Guide](migration-v2.md#11-deprecated-mcp-features-and-runtime-warnings-m8)
> for the full deprecation registry.

### Publishing Roots to the Server

MCP **roots** let a client publish a list of `file://` URIs that act as anchors for what the server is allowed to look at -- typically the open workspace folders in an editor, or the working directory of a CLI invocation. The server requests the list with `roots/list` whenever it needs to know the current scope, and the client emits `notifications/roots/list_changed` when those anchors change so a long-lived server doesn't have to poll.

Register a roots handler with `Client::onListRoots()` **before** `connect()`. That single call does two things: it advertises the `roots` capability in the initialization handshake (the MCP spec requires a client that supports roots to declare it, so a spec-compliant server only calls `roots/list` once it sees the capability), and it wires your handler to answer every incoming `roots/list`. Two things you still own:

- **The roots store itself.** The SDK doesn't track which roots you've published. Hold them in an array (or whatever your application uses for workspace state) and return them from the handler on every `roots/list`.
- **Change notifications.** Call `ClientSession::sendRootsListChanged()` whenever the store changes so a long-lived server can refresh. By default `onListRoots()` advertises `roots: { listChanged: true }`; pass `listChanged: false` if your root set is static and you will never send the notification.

Putting it together:

```php
<?php
// roots.php
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Types\ListRootsResult;
use Mcp\Types\Root;

// Application-owned root store. Update this whenever the user opens or closes
// a workspace folder; the change notification below tells the server to refresh.
$roots = [
    new Root(uri: 'file:///home/alice/projects/website',  name: 'website'),
    new Root(uri: 'file:///home/alice/projects/api',      name: 'api'),
];

$client = new Client();

// Register the roots/list handler before connect() so the `roots` capability
// is advertised in the handshake. The handler returns a ListRootsResult built
// from the current store; capture $roots by reference (use (&$roots)) so later
// updates are reflected on the next roots/list.
$client->onListRoots(static function () use (&$roots): ListRootsResult {
    return new ListRootsResult(roots: $roots);
});

$session = $client->connect('https://example.com/mcp-server.php');

// Push an update when the user adds or removes a workspace.
$roots[] = new Root(uri: 'file:///home/alice/projects/cli-tools', name: 'cli-tools');
$session->sendRootsListChanged();

$client->close();
```

A few constraints worth knowing:

- **`Root::$uri` must be a `file://` URI.** `Root::validate()` rejects any other scheme with an `InvalidArgumentException`. Note that the SDK does *not* validate the result you hand to `sendResponse()` on the way out, nor does the `Root` constructor validate -- the check runs when the receiving peer parses the `ListRootsResult` off the wire (`Root::fromArray()`), so a non-`file://` URI is rejected there rather than thrown locally at construction time. There is no virtual-roots escape hatch -- everything published has to live under a `file://` scheme even if the underlying handler maps it to something exotic.
- **`Root::$name` is optional** and is meant for display in client UI; servers should not parse it.
- **`sendRootsListChanged()` is fire-and-forget.** The server may issue a fresh `roots/list` in response, or it may defer until it next needs the list -- both behaviors are spec-compliant.
- **Roots is deprecated as of `2026-07-28`** (SEP-2577; migration: pass directories or files via tool parameters, resource URIs, or server configuration). It keeps working through the deprecation window; on modern sessions `roots/list` entries arrive via `input_required` exchanges and servicing them emits one PSR-3 warning per session.

---

## Part 10: Resuming HTTP Sessions Across Web Requests

Stateful MCP sessions work naturally over stdio (the subprocess holds the state) and over legacy HTTP (the server holds the state via `Mcp-Session-Id`). The challenge for **PHP web hosting** is that each browser request creates a fresh PHP process. Without help, every page load would have to do a full handshake, re-authenticate, and forget anything the previous request learned.

The SDK solves this with a **detach + resume** pair:

- `Client::detach()` -- close locally but keep the server's session alive (no HTTP `DELETE`). Snapshot everything resumable to `$_SESSION`.
- `Client::resumeHttpSession(...)` -- the next request rebuilds the transport from the snapshot and skips the handshake.

The pair works on **both eras**. On a modern (`2026-07-28`) session there
is no server-side session at all -- the snapshot carries only client-side
state (the negotiated version, the request-id counter), and the resumed
session re-enters modern mode automatically (the SDK detects it from the
persisted negotiated version; pass the original session's
`getModernWireVersion()` as `resumeHttpSession()`'s optional
`modernWireVersion` parameter to preserve an exact wire identifier). The
`detach()`-vs-`close()` distinction only matters on legacy sessions --
a modern close has no server session to delete -- but using `detach()`
uniformly is harmless and keeps the code era-agnostic.

### Detaching at the End of a Request

```php
<?php
// page1.php -- first request: connect, do work, persist state
declare(strict_types=1);
session_start();
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Transport\StreamableHttpTransport;

$client = new Client();
$session = $client->connect(
    commandOrUrl: 'https://example.com/mcp-server.php',
    args: [],
    env: ['autoSse' => false],
);

// Do work for this request.
$tools = $session->listTools()->tools;
echo "Got " . count($tools) . " tools\n";

// Snapshot everything we need to resume next time.
$transport = $client->getTransport();
if ($transport instanceof StreamableHttpTransport) {
    $_SESSION['mcp'] = [
        'sessionManagerState' => $transport->getSessionManager()->toArray(),
        'initResult'          => json_encode(
            $session->getInitializeResult(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        'protocolVersion'     => $session->getNegotiatedProtocolVersion(),
        'nextRequestId'       => $session->getNextRequestId(),
        'serverUrl'           => 'https://example.com/mcp-server.php',
    ];
}

// detach() leaves the server-side session alive; close() would tear it down.
$client->detach();
```

### Resuming on the Next Request

```php
<?php
// page2.php -- subsequent request: rehydrate, do more work, persist again
declare(strict_types=1);
session_start();
require __DIR__ . '/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Transport\StreamableHttpTransport;

if (!isset($_SESSION['mcp'])) {
    http_response_code(400);
    exit('No live MCP session in this browser session');
}

$snap = $_SESSION['mcp'];

$client = new Client();
$session = $client->resumeHttpSession(
    url: $snap['serverUrl'],
    sessionManagerState: $snap['sessionManagerState'],
    initResultData: json_decode($snap['initResult'], true, flags: JSON_THROW_ON_ERROR),
    negotiatedProtocolVersion: $snap['protocolVersion'],
    nextRequestId: (int) $snap['nextRequestId'],
    headers: [],
    httpOptions: ['autoSse' => false],
);

// No handshake happens -- the session is ready for operations immediately.
$result = $session->callTool('add_numbers', ['a' => 5, 'b' => 7]);
foreach ($result->content as $block) {
    echo ($block->text ?? '') . "\n";
}

// Persist the updated state again before detaching.
$transport = $client->getTransport();
if ($transport instanceof StreamableHttpTransport) {
    $_SESSION['mcp']['sessionManagerState'] = $transport->getSessionManager()->toArray();
    $_SESSION['mcp']['nextRequestId']       = $session->getNextRequestId();
}
$client->detach();
```

A few important rules:

- **Snapshot after every operation.** The `nextRequestId` counter and the `Mcp-Session-Id` / last-event-ID inside `sessionManagerState` advance on every JSON-RPC round-trip.
- **Use `detach()`, not `close()`, between requests.** `close()` sends a `DELETE` and drops the server session.
- **`autoSse => false` is the right call.** A standalone GET stream cannot survive past the end of the PHP request anyway, and in some SAPIs PHP-FPM may try to fork it -- which inherits the session/log state in confusing ways.
- **OAuth tokens persist separately.** They're stored in the `TokenStorageInterface` you configured (use `FileTokenStorage` keyed by PHP session ID for true per-user isolation). The session resume API only handles the MCP-level state.

For a complete reference implementation -- including OAuth, elicitation capture, and a JavaScript front-end -- see `webclient/` in the repository.

---

## Appendix A: Configuration Reference

### `Client::connect()` Parameters

| Parameter | Stdio | HTTP |
|-----------|-------|------|
| `commandOrUrl` (string) | Executable to run | MCP endpoint URL |
| `args` (array) | Arguments for the executable | HTTP headers (`['Header' => 'value']`) |
| `env` (array, nullable) | Subprocess env vars | HTTP options array (see below) |
| `readTimeout` (float, nullable) | Per-request read timeout (s) | Per-request read timeout (s) |
| `protocolMode` (string) | `'auto'` (probe + fallback) \| `'modern'` \| `'legacy'` | Same (the `protocolMode` HTTP option takes precedence) |
| `probeTimeout` (float, nullable) | Seconds to wait on the `server/discover` probe | Same (the `probeTimeout` HTTP option takes precedence) |

### HTTP Transport Options (third arg to `connect()`)

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `connectionTimeout` | float | 30.0 | Seconds to establish TCP connection |
| `readTimeout` | float | 60.0 | Seconds to wait for each response |
| `sseIdleTimeout` | float | 300.0 | Max idle seconds for an SSE stream |
| `enableSse` | bool | true | Accept `text/event-stream` responses |
| `autoSse` | bool | true | Open the standalone GET SSE stream after connect (**legacy only** -- no such stream exists on the modern era) |
| `protocolMode` | string | (connect param) | `'auto'` \| `'modern'` \| `'legacy'`; overrides the `connect()` parameter |
| `protocolVersion` | ?string | null | Preferred modern protocol revision for the `_meta` envelope |
| `probeTimeout` | ?float | null | Discover-probe timeout (s); overrides the `connect()` parameter |
| `verifyTls` | bool | true | Verify TLS peer + host |
| `caFile` | ?string | null | Custom CA bundle path |
| `curlOptions` | array | [] | Raw cURL options merged into every request |
| `oauth` | ?OAuthConfiguration | null | OAuth config (see below) |
| `sseDefaultRetryDelay` | float | 1.0 | Reconnect delay when server omits `retry` (s) |
| `sseReconnectBudget` | float | 60.0 | Total wall-clock budget for reconnect attempts (s) |

### `OAuthConfiguration` Constructor

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `clientCredentials` | ?ClientCredentials | null | Pre-registered client (skip DCR) |
| `tokenStorage` | ?TokenStorageInterface | MemoryTokenStorage | Where tokens are persisted |
| `authCallback` | ?AuthorizationCallbackInterface | null | Handles the user authorization step |
| `enableCimd` | bool | true | Allow Client ID Metadata Document path |
| `enableDynamicRegistration` | bool | true | Allow RFC 7591 DCR fallback |
| `cimdUrl` | ?string | null | Hosted client metadata JSON URL |
| `additionalScopes` | array | [] | Extra scopes to request |
| `timeout` | float | 30.0 | HTTP timeout for OAuth requests (s) |
| `autoRefresh` | bool | true | Refresh tokens nearing expiry |
| `refreshBuffer` | int | 60 | Seconds before expiry to trigger refresh |
| `redirectUri` | ?string | null | Override the callback handler's redirect URI |
| `verifyTls` | bool | true | Verify TLS for OAuth HTTP calls |
| `authorizationServerUrl` | ?string | null | Fallback AS URL when PRM discovery fails |
| `enableLegacyOAuthFallback` | bool | false | MCP 2025-03-26 backwards-compat fallback |
| `useClientCredentialsGrant` | bool | false | Machine-to-machine `client_credentials` grant (no browser step); pair with `ClientCredentials` using `private_key_jwt` (ES256/RS256) or `client_secret_basic` |
| `crossAppAccess` | ?CrossAppAccessConfiguration | null | SEP-990 cross-app access (RFC 8693 token exchange + RFC 7523) |
| `allowUnboundClientCredentials` | bool | false | Accept pre-registered credentials without `ClientCredentials::$issuer` (published-spec `2025-11-25` behavior: pin to the first validated issuer for the process lifetime) |

### Built-in Authorization Callback Handlers

| Class | Use case |
|-------|----------|
| `LoopbackCallbackHandler` | CLI applications: spins up a loopback HTTP server |
| `HeadlessCallbackHandler` | Test harnesses: simulates the redirect without a browser |
| (custom) | Web hosting: throw `AuthorizationRedirectException` -- see Part 7 |

### Token Storage Implementations

| Class | Persistence | Recommended for |
|-------|-------------|-----------------|
| `MemoryTokenStorage` | Per PHP process | Trivial scripts, tests |
| `FileTokenStorage` | Disk (AES-256-GCM optional) | CLI tools, web hosting |

### `ClientSession` Methods

| Method | Description |
|--------|-------------|
| `getInitializeResult()` | Server info and capabilities (synthesized from `server/discover` on modern sessions) |
| `getNegotiatedProtocolVersion()` | The version both sides agreed on |
| `isModernMode()` | Whether the session negotiated the `2026-07-28` era |
| `discover()` | `server/discover` -- raw `DiscoverResult` (modern servers) |
| `supportsFeature(string)` | Boolean check against the version + feature matrix |
| `declareExtension(string, array)` | Declare a SEP-2133 extension (e.g. `ExtensionIds::TASKS`) in the per-request capability envelope |
| `listTools(?string)` | `tools/list` (optional pagination cursor) |
| `callTool(string, ?array)` | `tools/call` -- returns `CallToolResult\|CreateTaskResult` (see [Tasks guide](tasks.md)) |
| `getTask(string)` / `updateTask(string, array)` / `cancelTask(string)` | `tasks/get` / `tasks/update` / `tasks/cancel` (Tasks extension) |
| `listPrompts(?string)` | `prompts/list` (optional pagination cursor) |
| `getPrompt(string, ?array)` | `prompts/get` |
| `listResources(?string)` | `resources/list` (optional pagination cursor) |
| `listResourceTemplates(?string)` | `resources/templates/list` (optional pagination cursor) |
| `readResource(string)` | `resources/read` |
| `subscribeResource(string)` | `resources/subscribe` (**legacy only** -- removed in `2026-07-28`) |
| `unsubscribeResource(string)` | `resources/unsubscribe` (**legacy only**) |
| `complete(ref, array)` | `completion/complete` |
| `setLoggingLevel(LoggingLevel)` | `logging/setLevel` (**legacy only**; deprecated by SEP-2577) |
| `sendPing()` | `ping` (**legacy only** -- removed in `2026-07-28`) |
| `sendProgressNotification(...)` | Send progress while handling a server-initiated request |
| `onListRoots(callable, bool)` | Advertise the `roots` capability and answer `roots/list` |
| `sendRootsListChanged()` | Notify the server that the roots list changed |
| `onNotification(callable)` | Register a server-notification handler |
| `onRequest(callable)` | Register a low-level server-request handler (advanced) |
| `getNextRequestId()` | Persist the request-ID counter for session resume |

### `Client` Methods

| Method | Description |
|--------|-------------|
| `connect(commandOrUrl, args, env, readTimeout, protocolMode, probeTimeout)` | Open transport, negotiate the protocol era, return session |
| `onElicit(callable, applyDefaults, supportsUrlMode)` | Register the elicitation handler -- call before `connect()`. `supportsUrlMode: true` opts the client into the `url` sub-capability (2025-11-25) in addition to `form` |
| `onListRoots(callable, listChanged)` | Register the roots/list handler -- call before `connect()`. Advertises the `roots` capability (`listChanged: true` by default) in the handshake |
| `onSampling(callable)` | Register the sampling handler -- call before `connect()`. Advertises the `sampling` capability; also services modern `input_required` sampling entries |
| `close()` | Tear down session and transport (sends DELETE on HTTP) |
| `detach()` | Close locally; preserve the server-side HTTP session |
| `resumeHttpSession(...)` | Rebuild a session from a snapshot, skipping handshake |
| `getSession()` | The current `ClientSession` (or null) |
| `getTransport()` | The current transport (or null) |

---

## Appendix B: Connection Recipes

### CLI Tool Talking to a Local Stdio Server

```php
$client = new Client();
$session = $client->connect('php', ['/path/to/server.php']);
// ... use $session ...
$client->close();
```

### CLI Tool Talking to a Remote HTTP Server (no auth)

```php
$client = new Client();
$session = $client->connect('https://example.com/mcp-server.php');
// ... use $session ...
$client->close();
```

### CLI Tool with OAuth (browser-based authorization)

```php
$client = new Client();
$session = $client->connect(
    'https://example.com/mcp-server.php',
    [],
    [
        'oauth' => new OAuthConfiguration(
            tokenStorage: new FileTokenStorage(__DIR__ . '/.tokens', 'enc-secret'),
            authCallback: new LoopbackCallbackHandler(),
        ),
    ],
);
// ... use $session ...
$client->close();
```

### CLI Tool with a Static Bearer Token

```php
$client = new Client();
$session = $client->connect(
    'https://example.com/mcp-server.php',
    ['Authorization' => 'Bearer my-static-token'],
);
// ... use $session ...
$client->close();
```

### Web Application with Per-Browser-Session State

1. On every request, `session_start()` and check whether `$_SESSION['mcp']` exists.
2. If not, `Client::connect()` (catching `AuthorizationRedirectException` for OAuth servers).
3. If yes, `Client::resumeHttpSession(...)`.
4. Do the request's work.
5. Snapshot transport state back into `$_SESSION['mcp']`.
6. `Client::detach()`.

The reference implementation is in `webclient/` -- see `webclient/lib/SessionStore.php` for the full snapshot/resume/oauth-resume flow used to power the bundled web UI.

### Client Pre-Loading an Elicitation Handler

```php
$client = new Client();
$client->onElicit(
    static fn ($req) => new ElicitationCreateResult(action: 'accept', content: []),
    applyDefaults: true,
    supportsUrlMode: true, // omit (or pass false) to advertise form-only
);
$session = $client->connect('https://example.com/mcp-server.php');
```

`onElicit()` *must* be called before `connect()` so the elicitation capability is included in the handshake. `supportsUrlMode` is opt-in: with the default (`false`) the SDK advertises `form` only, and spec-compliant servers will not send URL-mode requests.

---

*This guide covers v2 of the `logiscape/mcp-sdk-php` SDK, implementing the [MCP specification](https://modelcontextprotocol.io/specification/) through the `2026-07-28` revision with negotiated support back to `2024-11-05`. For SDK source code and updates, visit [github.com/logiscape/mcp-sdk-php](https://github.com/logiscape/mcp-sdk-php).*
