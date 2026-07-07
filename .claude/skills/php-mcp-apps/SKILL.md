---
name: php-mcp-apps
description: Build MCP Apps (SEP-1865 interactive UIs) in PHP with the logiscape/mcp-sdk-php SDK — tools that render live HTML views (dashboards, forms, carousels, pickers, widgets) inside Claude, ChatGPT, VS Code Copilot, and other MCP hosts. Use this skill whenever a PHP MCP server should show interactive or visual output instead of plain text, when the user mentions MCP Apps, apps for ChatGPT/Claude, widgets, interactive connectors, ui:// resources, McpServer::ui(), the io.modelcontextprotocol/ui extension, mcp-ui, the OpenAI Apps SDK in a PHP context, or asks how a tool result can be displayed as a UI. Covers the full path from server (McpServer::ui()) to sandboxed HTML view to per-host distribution.
---

# PHP MCP Apps Development Guide

## Overview

MCP Apps (SEP-1865) let an MCP tool ship an interactive HTML view that the host —
Claude, ChatGPT, VS Code Copilot, and others — renders in a sandboxed iframe next to
the tool's result. It is the first official MCP extension (extension id
`io.modelcontextprotocol/ui`, stable revision `2026-01-26`, GA January 2026), jointly
authored by Anthropic, OpenAI, and the MCP-UI community — which is why one correctly
built app runs across ChatGPT, Claude, VS Code, Microsoft 365 Copilot, Goose, Postman,
and more.

An MCP App is three things, and only three things:

1. **An ordinary tool** that returns both human-readable `content` and a
   machine-readable `structuredContent` payload.
2. **A `ui://` resource** carrying a self-contained HTML document (MIME type
   `text/html;profile=mcp-app`) served via plain `resources/read`.
3. **A `_meta` link** (`_meta.ui.resourceUri`) on the tool pointing at that resource,
   plus the `extensions` capability declaration.

The extension adds **no new server RPC**. All interactivity — the view calling tools,
posting messages to the chat, resizing — flows through the *host* over a
`postMessage` JSON-RPC bridge (`ui/*` methods) that never touches your PHP code.
Your server's job is small and this SDK compresses it into one call: `McpServer::ui()`.

**Why PHP is a great fit:** the host fetches the view once and pushes data into it —
so the server side is stateless request/response, exactly PHP's model. No Node build
step (views are single-file vanilla HTML/JS), no long-running process, no SSE
required. Apps built with this SDK deploy to cPanel/Apache shared hosting unchanged.

**Prerequisite:** a PHP 8.1+ project with the SDK installed
(`composer require logiscape/mcp-sdk-php`). This skill covers everything UI; for
general server fundamentals (tool design, transports, sessions, OAuth) consult the
SDK's own documentation — the README at
`https://raw.githubusercontent.com/logiscape/mcp-sdk-php/main/README.md` and the
guides it links under `docs/` (fetch with WebFetch as needed).

## Reference files — load as needed

- [reference/php_server_patterns.md](reference/php_server_patterns.md) — the
  `McpServer::ui()` API in full, plus server-side recipes: dashboards, pickers,
  carousels, app-only tools, CSP/permissions, views from disk, database-driven apps,
  auth interplay, shared-hosting deployment. **Load during server implementation.**
- [reference/view_patterns.md](reference/view_patterns.md) — how to write the HTML
  view: the bridge contract, theming, sizing, state, loading skeletons, calling tools
  from the UI, talking to the model. **Load during view implementation.**
- [assets/view-template.html](assets/view-template.html) — a complete, ready-to-copy
  view boilerplate implementing the full bridge (handshake, tool-input/result,
  host-context theming, size reporting, error surface). **Start every view from this.**
- [reference/apps_protocol.md](reference/apps_protocol.md) — the exact wire protocol:
  every `ui/*` method, `_meta` key, hostContext field, CSP rule, and theming variable.
  **Load when you need exact names/shapes or are debugging the bridge.**
- [reference/host_compatibility.md](reference/host_compatibility.md) — per-host
  support matrix, ChatGPT `openai/*` extras, Claude design rules and directory
  submission, testing harnesses. **Load when targeting or testing a specific host.**

---

# Process

## Phase 1: Design the app before writing code

### 1.1 Decide whether a UI earns its place

A view is worth shipping when seeing or manipulating the data beats reading about it:
dashboards and charts, listings with images (real estate, products, travel), pickers
and booking forms, maps, document/PDF review, queues and feeds, configuration wizards,
monitoring panels, games. If the tool's output is a sentence, keep it text-only —
a UI that restates prose is clutter, and every host renders text faster.

### 1.2 Choose the display pattern

| Pattern | Display mode | Fits |
|---|---|---|
| Card / stat panel | `inline` (default) | Single record, summary, status. ≤2 actions, no internal nav. |
| Carousel | `inline` | 3–8 visually consistent cards with imagery (listings, media). |
| Form / picker | `inline` | Collect a choice or parameters, then act. |
| Workspace / canvas | `fullscreen` | Multi-step flows, editors, maps, large tables. Chat composer stays overlaid. |
| Live panel | `pip` | Games, timers, collaboration (not all hosts support pip). |

Start inline. To use anything beyond inline, the view must declare every mode it
implements in `appCapabilities.availableDisplayModes` at `ui/initialize` (the
template handles this via `APP_DISPLAY_MODES`), then request the switch only on a
user action, only if the host offers the mode, and apply whatever mode the host
actually returns — it may differ. Design for a **320px-wide minimum** and let height
fit content.

### 1.3 Design the data contract — the three channels

This is the single highest-leverage design decision. A tool result reaches two
audiences through three channels:

- **`content`** (text) — what the **model** and every non-UI host reads. Always
  provide a meaningful summary here. Never ship a UI-only result: a host without Apps
  support (including Claude Code, which is terminal-only) sees *only* this.
- **`structuredContent`** (JSON) — what the **view renders**. Whether the model also
  sees it is **host-dependent**: the Apps spec keeps it out of model context, but
  ChatGPT surfaces it to the model. Two consequences: anything the model must know
  goes in `content` (never count on it reading `structuredContent`), and keep it
  lean anyway — on hosts that do surface it, the model pays tokens for every byte.
  Define it like an API response (this is your view's props) and declare a matching
  `outputSchema` on the tool.
- **result `_meta`** (JSON) — view-only bulk/sensitive data, hidden from the model.
  Portability caveat: some hosts strip custom result `_meta` — treat it as an
  optimization, never the only path to data the view requires.

Rule of thumb: `content` = one paragraph; `structuredContent` = what fits on screen;
`_meta` = the rest, if you must.

### 1.4 Split tools by who may call them

Every UI action arrives at your server as an ordinary `tools/call`. Decide per tool:

- **`visibility: ['model', 'app']`** (default) — the agent and the view may both call it.
- **`visibility: ['app']`** — UI plumbing (refresh, paginate, sort, save-state) the
  model should never invoke. Hiding these keeps the model from misfiring them and
  keeps its tool list clean.
- Model-only tools need no UI linkage at all.

Visibility is enforced by the **host**, not your server — don't put secrets behind it.

### 1.5 One view per *view*, not per tool

The `ui://` template is a static app shell the host fetches once and **caches by
URI**. Several related tools can share one template (`_meta.ui.resourceUri` on each).
Bake **no per-request data into the HTML** — data arrives through
`ui/notifications/tool-result`. When you change the template, version the URI
(`ui://myapp/dashboard-v2`) so cached hosts pick it up.

## Phase 2: Implement the server (PHP)

Load [reference/php_server_patterns.md](reference/php_server_patterns.md) now.

The minimal complete pattern — an ordinary tool plus one `ui()` call:

```php
<?php
require 'vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

$server = new McpServer('kanban-server');

$server
    ->tool(
        'list_board',
        'Show the kanban board with current task cards',
        function (string $board = 'default'): CallToolResult {
            $data = fetchBoard($board);   // your domain logic
            return new CallToolResult(
                // Channel 1: the model + non-UI hosts. Always meaningful.
                content: [new TextContent(text: summarizeBoard($data))],
                // Channel 2: the view's props. Lean, schema-shaped.
                structuredContent: $data,
            );
        },
    )
    // Register AFTER the tool it links to.
    ->ui(
        tool: 'list_board',
        uri: 'ui://kanban/board-v1',
        name: 'Kanban Board',
        html: fn() => file_get_contents(__DIR__ . '/views/board.html'), // lazy: read at fetch time
    )
    ->run();  // stdio on CLI, HTTP under a web server
```

That one `ui()` call registers the `ui://` resource with the correct MIME type,
writes `_meta.ui.resourceUri` on the tool (plus the deprecated flat key for host
back-compat), and declares `extensions["io.modelcontextprotocol/ui"]` in the
capabilities — advertised on both the legacy `initialize` and modern
`server/discover` paths, so it works with every protocol era.

Hard rules the SDK enforces at registration time (fail fast, not on the wire):
- The URI must begin with `ui://`.
- The tool must already be registered (`tool()` before `ui()`).
- `visibility`, `csp`, `permissions` values are validated; invalid ones throw.

**Embedding HTML in PHP:** use a *nowdoc* (`<<<'HTML'`), never a heredoc — modern JS
is full of `${...}` template literals that a heredoc would interpolate as PHP. For
anything beyond ~50 lines, keep the view in its own `.html` file and load it with a
lazy callback (syntax highlighting, `php -l`-free editing, direct browser preview).

## Phase 3: Implement the view (HTML + JS)

Load [reference/view_patterns.md](reference/view_patterns.md) and copy
[assets/view-template.html](assets/view-template.html) as your starting point.

The view is a **self-contained HTML document** that speaks JSON-RPC 2.0 with the host
over `postMessage`. The non-negotiable part is the handshake:

```
view  → host   ui/initialize {appInfo, appCapabilities, protocolVersion}   (all three required; host replies with theme, locale, sizing)
view  → host   ui/notifications/initialized
host  → view   ui/notifications/tool-input    (the original arguments)
host  → view   ui/notifications/tool-result   (the CallToolResult — render structuredContent)
```

**A view without this script renders nothing useful, ever** — the host delivers the
tool result only after the handshake completes. Static HTML alone shows its
placeholder forever.

What a production view handles beyond the handshake (all in the template):
- **Theming**: read `hostContext.theme` + host CSS variables
  (`--color-*`, `--font-*`, `--border-radius-*`) with fallbacks; re-apply on
  `ui/notifications/host-context-changed`. Never hardcode colors — your app must
  look native in light and dark on every host.
- **Loading state**: `tool-input` often arrives before `tool-result` (and streaming
  hosts send `tool-input-partial` earlier still). Render a skeleton keyed on the
  input, not a blank frame.
- **Interactivity**: call tools through the host (`tools/call` over the bridge),
  post user actions to the conversation (`ui/message`) or silently update what the
  model knows (`ui/update-model-context`) — the classic bug is UI state the model
  can't see (user picked a flight; model still thinks nothing happened).
- **Sizing**: report content height via `ui/notifications/size-changed`; respect
  `containerDimensions.maxHeight`.

Sandbox constraints to design around: deny-all CSP by default (no external fetches,
fonts, or CDNs unless you declare domains via `ui()`'s `csp:` parameter), no
`alert`/`confirm`/`prompt`, no `navigator.clipboard` on some hosts, links must go
through `ui/open-link`. **Single-file is the law**: inline all CSS/JS; embed images
as data: URIs or declare `resourceDomains`. Vanilla JS is the default (matches PHP's
no-build ethos); if you want React/Vue/Svelte, bundle with `vite-plugin-singlefile`
and load the built artifact from disk.

## Phase 4: Test

1. **Server sanity** (no host needed):
   ```bash
   php -l server.php
   php server.php                      # stdio: should wait on stdin
   php -S localhost:8000 server.php    # HTTP endpoint at http://localhost:8000
   ```
2. **View in a plain browser**: the template downgrades gracefully — open the .html
   file directly; with no host to answer, the view should settle in its
   empty/skeleton state with no JS errors.
3. **Apps-capable inspector** — the official MCP Inspector
   (`npx @modelcontextprotocol/inspector`) has an **Apps** tab: connect,
   select the app, fill the tool params, "Open App". It strictly validates the
   bridge (e.g. rejects a `ui/initialize` missing `appInfo`/`protocolVersion`),
   which makes it the best conformance check. MCPJam
   (`npx @mcpjam/inspector`) is a good alternative with richer host emulation
   (theme toggle, device/locale, both-ways `ui/*` traffic log).
4. **Real hosts**: Claude (Settings → Connectors → add your HTTP URL; works before
   any directory submission) and/or ChatGPT developer mode. Verify: result renders,
   UI-initiated tool calls round-trip, dark mode, graceful text for non-UI hosts.
5. If it doesn't render, debug with the checklist in
   [reference/apps_protocol.md](reference/apps_protocol.md) — nine times out of ten
   it's a missing **or rejected** handshake (a skeleton that pulses forever
   usually means the host refused `ui/initialize` — check the iframe console),
   a non-`ui://` URI, or a CSP-blocked external asset.

## Phase 5: Deploy and distribute

Deployment is identical to any SDK server: upload the project including `vendor/`
to the web root, guard `vendor/` and session directories with `.htaccess`
(`RewriteRule ^vendor/ - [F,L]`), ensure PHP 8.1+ is selected. Nothing
about Apps needs SSE, sessions, or workers — the template is served by ordinary
`resources/read` over standard HTTP.

For distribution inside a host's directory (Claude's connector directory, the
ChatGPT app store), each has its own review gauntlet — OAuth, accurate tool
annotations, screenshots, privacy policy. Load
[reference/host_compatibility.md](reference/host_compatibility.md) before submitting.

---

# Quality checklist

Design
- [ ] The UI earns its place (visual/interactive value over prose)
- [ ] `structuredContent` designed like an API response, with `outputSchema` declared
- [ ] UI-plumbing tools marked `visibility: ['app']`
- [ ] Template treated as static shell; no per-request data baked into HTML
- [ ] `ui://` URI versioned (bump on template changes — hosts cache by URI)

Server (PHP)
- [ ] Every UI-linked tool returns a meaningful text `content` block (graceful degradation)
- [ ] `tool()` called before `ui()`; URI starts with `ui://`
- [ ] View HTML in its own file, loaded via lazy callback (or nowdoc if truly tiny)
- [ ] External domains the view fetches declared via `csp:`; none needed = none declared
- [ ] `declare(strict_types=1)`, no output before `run()`

View (HTML/JS)
- [ ] Started from `assets/view-template.html`; handshake implemented
- [ ] Renders `structuredContent`, not parsed display text
- [ ] Skeleton/loading state before `tool-result` arrives
- [ ] Theme: host CSS variables with fallbacks; reacts to `host-context-changed`; light AND dark verified
- [ ] Single file: all CSS/JS inline; no CDN references unless CSP-declared
- [ ] Links via `ui/open-link`; no `alert`/`confirm`/`prompt`
- [ ] Model kept in sync after user actions (`ui/message` or `ui/update-model-context`)
- [ ] Usable at 320px width; touch targets ≥44px if actionable

Cross-host
- [ ] Built to the open standard first; host-specific extras feature-detected, never UA-sniffed
- [ ] Tested in the official MCP Inspector + at least one real host, both themes
- [ ] Tool annotations (`readOnlyHint`/`destructiveHint`/`idempotentHint`/`openWorldHint`) accurate, set via `tool(annotations: [...])` — directory reviews check them
