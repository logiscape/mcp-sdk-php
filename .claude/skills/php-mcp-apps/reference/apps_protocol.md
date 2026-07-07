# MCP Apps Wire Protocol Reference (SEP-1865)

Exact names and shapes from the official extension spec —
[modelcontextprotocol/ext-apps](https://github.com/modelcontextprotocol/ext-apps),
stable revision **`2026-01-26`** (`specification/2026-01-26/apps.mdx`). Where this SDK
emits something automatically, that is noted; everything under "Host ↔ view bridge"
lives purely in the HTML view and the host — it never reaches PHP.

## Table of contents

- [Identity and capability declaration](#identity-and-capability-declaration)
- [The ui:// resource](#the-ui-resource)
- [Tool _meta linkage](#tool-_meta-linkage)
- [Resource _meta.ui (security & presentation hints)](#resource-_metaui-security--presentation-hints)
- [Host ↔ view bridge: lifecycle](#host--view-bridge-lifecycle)
- [Host ↔ view bridge: method registry](#host--view-bridge-method-registry)
- [hostContext fields](#hostcontext-fields)
- [Sandboxing and CSP](#sandboxing-and-csp)
- [Host theming CSS variables](#host-theming-css-variables)
- [Draft (post-stable) additions](#draft-post-stable-additions)
- [Debugging checklist](#debugging-checklist)

## Identity and capability declaration

- Extension id: **`io.modelcontextprotocol/ui`** (`Mcp\Types\ExtensionIds::UI`)
- Declared in the SEP-2133 `extensions` capability map. A capable **host (client)**
  sends in its capabilities:

```json
"extensions": {
  "io.modelcontextprotocol/ui": { "mimeTypes": ["text/html;profile=mcp-app"] }
}
```

- This SDK's `ui()` declares the same shape on the **server** side (via
  `Server::declareExtension()`), advertised identically in the legacy `initialize`
  result and the modern `server/discover` result. Harmless and symmetric — but the
  negotiation signal hosts act on is the *client* capability, so a server MAY also
  check `clientCapabilities.extensions` before advertising UI-heavy behavior.

## The ui:// resource

- URI scheme: MUST be `ui://...`. Treat the URI as a **cache key** — hosts prefetch
  and cache the template by URI; version it (`ui://app/view-v2`) when the HTML changes.
- MIME type: **`text/html;profile=mcp-app`** (exact string; lowercase, no space —
  `McpServer::UI_MIME_TYPE`). This replaced the pre-standard community strings
  (`text/html+skybridge` is OpenAI's legacy marker; plain `text/html` is wrong).
- Served by ordinary `resources/read`; content in `text` (or `blob`, base64).
- Servers MAY omit UI-only resources from `resources/list` (discovery via tool
  `_meta` is the primary path). This SDK *does* list them — also fine.

## Tool _meta linkage

Current stable format is the bare nested `ui` key (NOT the namespaced extension id):

```json
"_meta": {
  "ui": {
    "resourceUri": "ui://weather/dashboard",
    "visibility": ["model", "app"]
  }
}
```

- `resourceUri` — the linked `ui://` template.
- `visibility` — array of `"model"` and/or `"app"`; omitted means both.
  `["app"]` = callable from the view, hidden from the model. Enforced by the host.
- Deprecated: flat `_meta["ui/resourceUri"]`. This SDK dual-writes it alongside the
  nested form for pre-GA host back-compat (matching the reference TS SDK). Never
  write it by hand.
- OpenAI legacy alias: `_meta["openai/outputTemplate"]` (see host_compatibility.md).

## Resource _meta.ui (security & presentation hints)

Emitted on the `resources/read` content item (where the stable revision reads them)
and mirrored on the listed resource. All produced by `ui()` parameters:

```json
"_meta": {
  "ui": {
    "csp": {
      "connectDomains":  ["https://api.example.com"],
      "resourceDomains": ["https://cdn.example.com"],
      "frameDomains":    [],
      "baseUriDomains":  []
    },
    "permissions": { "camera": {}, "clipboardWrite": {} },
    "domain": "https://sandbox.example.com",
    "prefersBorder": true
  }
}
```

- `csp.connectDomains` → `connect-src` (fetch/XHR/WebSocket targets)
- `csp.resourceDomains` → `img-src`/`script-src`/`style-src`/`font-src`/`media-src`
- `csp.frameDomains` → `frame-src` (nested iframes; Claude currently restricts this)
- `csp.baseUriDomains` → `base-uri`
- `permissions` — subset of `camera`, `microphone`, `geolocation`, `clipboardWrite`;
  mapped to the iframe `allow` attribute. Each serializes as `"name": {}`.
- `domain` — dedicated sandbox origin (host-defined use).
- `prefersBorder` — advisory: view prefers a host-drawn border/background.

## Host ↔ view bridge: lifecycle

JSON-RPC 2.0 over `window.postMessage` between the sandboxed iframe and the host.
The host answers `ui/*` methods itself and **proxies standard MCP methods**
(the registry enumerates `tools/call`, `resources/read`, `notifications/message`,
and `ping`; hosts MAY forward other non-`ui/*` methods) to the server through its
normal consent/audit pipeline.

```
1. Host prefetches template (resources/read of _meta.ui.resourceUri), builds iframe
2. view → host   ui/initialize {appCapabilities}          [request]
   host → view   result: { protocolVersion: "2026-01-26",
                           hostInfo: {name, version},
                           hostCapabilities: {...},
                           hostContext: { theme, displayMode, containerDimensions, ... } }
3. view → host   ui/notifications/initialized             [notification]
4. host → view   ui/notifications/tool-input  { arguments: {...} }
   (streaming hosts may send ui/notifications/tool-input-partial earlier/repeatedly)
5. host → view   ui/notifications/tool-result  — params ARE the CallToolResult:
                 { content: [...], structuredContent?: {...}, isError?: bool, _meta?: {...} }
6. ongoing       view calls tools/call, ui/message, etc.; host pushes
                 host-context-changed on theme/size changes
7. host → view   ui/resource-teardown                     [request — MUST be answered]
```

The host delivers tool data **only after the view's `ui/initialize` request
completes** (the normative MUST is keyed to initialize completing; the sequence
diagram places it after `initialized`) — a view that skips the handshake renders
its static HTML forever.

## Host ↔ view bridge: method registry

View → host requests:

| Method | Params | Purpose |
|---|---|---|
| `ui/initialize` | `{ appCapabilities }` — declare `availableDisplayModes` here (e.g. `{availableDisplayModes:["inline","fullscreen"]}`); hosts MUST NOT switch the view to an undeclared mode | Handshake; returns host info/context |
| `ui/open-link` | `{ url }` | Open external link (navigation is sandbox-blocked) |
| `ui/request-display-mode` | `{ mode: "inline"\|"fullscreen"\|"pip" }` → result `{ mode }` | Ask for a mode you declared AND the host offers (`hostContext.availableDisplayModes`); the result carries the RESULTING mode, which may differ — always apply it |
| `ui/message` | `{ role: "user", content: {type:"text", text} }` — single block, not an array | Post into the conversation — model sees it and reacts. Host MAY require user consent and MAY deny (error response) |
| `ui/update-model-context` | `{ content?: ContentBlock[], structuredContent?: {...} }` | Silently update model-visible state; no chat message. Host MAY deny |
| `tools/call` | standard MCP | Proxied to the server — arrives at PHP as a normal call |
| `resources/read` / `ping` | standard MCP | Proxied to the server (hosts MAY also forward other non-`ui/*` methods, e.g. `resources/list`) |

View → host notifications:

| Method | Params | Purpose |
|---|---|---|
| `ui/notifications/initialized` | `{}` | Completes handshake |
| `ui/notifications/size-changed` | `{ width, height }` (pixels) | Report content size |
| `notifications/message` | standard MCP logging | Log through the host |

Host → view:

| Method | Kind | Params |
|---|---|---|
| `ui/notifications/tool-input` | notification | `{ arguments }` — the originating call's args |
| `ui/notifications/tool-input-partial` | notification | `{ arguments }` — streaming partial args |
| `ui/notifications/tool-result` | notification | the `CallToolResult` itself |
| `ui/notifications/tool-cancelled` | notification | call was cancelled |
| `ui/notifications/host-context-changed` | notification | changed subset of hostContext |
| `ui/notifications/sandbox-proxy-ready` / `sandbox-resource-ready` | notification | sandbox-proxy plumbing (web hosts' double-iframe pattern) — views can ignore |
| `ui/resource-teardown` | **request** | graceful shutdown — respond before iframe removal |

Result `_meta` on `tool-result` is view-only (hidden from the model), but some hosts
strip custom result `_meta` — never make it the sole path for data the view requires.

Audience per result channel: `content` is the only channel the model is guaranteed
to see. The spec defines `structuredContent` as "optimized for UI rendering (not
added to model context)"; some hosts (ChatGPT) surface it to the model anyway —
treat its model visibility as host-dependent.

## hostContext fields

Sent in the `ui/initialize` result and updated via `host-context-changed`:

`theme` (`"light"|"dark"`), `styles` (`{variables: {"--css-var": "value"},
css: {fonts}}`), `displayMode`, `availableDisplayModes`, `containerDimensions`
(a union: `width` *or* `maxWidth` × `height` *or* `maxHeight` — `{width,
maxHeight}` is the common combination, but don't assume fixed keys),
`locale` (BCP 47), `timeZone` (IANA), `userAgent`, `platform`
(`"web"|"desktop"|"mobile"`), `deviceCapabilities` (`{touch?, hover?}`),
`safeAreaInsets` (`{top,right,bottom,left}` — respect on mobile).

## Sandboxing and CSP

- The view runs in a sandboxed iframe (minimum `allow-scripts allow-same-origin` on
  a host-distinct origin; web hosts use a double-iframe sandbox-proxy pattern).
- **Default CSP with no `csp` metadata** (spec): `default-src 'none'; script-src
  'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;
  media-src 'self' data:; connect-src 'none'`. Practical meaning: inline
  script/style and data: images/media work; **every external fetch, CDN script,
  font, or image fails** unless its origin is declared through `ui()`'s `csp:`
  parameter.
- Blocked in the sandbox: `alert`/`confirm`/`prompt`, direct top navigation,
  `navigator.clipboard` on some hosts (request `clipboardWrite` permission), popups.
- Every view-initiated `tools/call` passes the host's normal consent flow — users may
  be prompted; hosts may restrict which tools the view gets.

## Host theming CSS variables

Hosts inject CSS custom properties (Claude documents ~60 across the color,
typography, radius, and shadow families; VS Code similar). Use them
with fallbacks: `color: var(--color-text-primary, CanvasText)`. The families:

- `--color-background-{primary,secondary,...}`, `--color-text-{primary,secondary,...}`,
  `--color-border-{primary,...}`, `--color-ring-*`
- `--font-sans` (Claude: "Anthropic Sans, sans-serif"), `--font-mono`,
  `--font-weight-{normal,medium,semibold,bold}` (400–700),
  `--font-text-{xs,sm,md,lg}-{size,line-height}` (12–20px),
  `--font-heading-{xs..3xl}-size` (12–36px)
- `--border-radius-{xs..full}` (4–9999px), `--border-width-regular` (0.5px),
  `--shadow-{hairline,sm,md,lg}`

Rule: never hardcode colors; brand color only as accent on logos/primary buttons.

## Draft (post-stable) additions

In `specification/draft/apps.mdx`, not yet stable — do not rely on cross-host:
`ui/download-file`; app-registered tools (view declares `tools` in
`appCapabilities`, host can call them, `notifications/tools/list_changed` from the
view); `ui/notifications/request-teardown` (view-initiated shutdown).

## Debugging checklist

View stays on its skeleton / "Loading…":
1. Handshake missing or broken — is `ui/initialize` sent and `initialized` notified
   after the response? (Most common failure.)
2. Check the host's iframe console (MCPJam surfaces it; browsers: inspect the iframe).
3. CSP kill — an external `<script src>`/font/CSS failed to load and took your
   bridge code down with it. Inline everything or declare `csp:` domains.

Nothing renders at all / fallback text only:
4. Tool `_meta.ui.resourceUri` present in `tools/list`? URI starts with `ui://`?
5. `resources/read` of the URI returns MIME `text/html;profile=mcp-app`?
6. Capability `extensions["io.modelcontextprotocol/ui"]` present in initialize /
   discover result? (All three are automatic with `ui()` — if missing, `ui()` was
   never called or a different server instance is being hit.)
7. Host actually supports Apps? (Claude Code does not — terminal client.)

Data missing in the view:
8. Tool returned `structuredContent`? (`content` text is NOT what views render.)
9. Relying on result `_meta` on a host that strips it?
10. Waiting on `tool-input` that never comes? Some hosts only send input after the
    user approves the call — render a generic skeleton first, not one keyed on input.
