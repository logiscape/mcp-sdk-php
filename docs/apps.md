# MCP Apps Extension Guide (SEP-1865)

The [MCP Apps extension](https://github.com/modelcontextprotocol/ext-apps)
(SEP-1865, stable revision `2026-01-26`) lets a tool ship an interactive
HTML view that a capable host — Claude, VS Code, or any MCP-Apps-aware
client — renders in a sandboxed iframe alongside the tool's result. The
extension id is `io.modelcontextprotocol/ui` (`Mcp\Types\ExtensionIds::UI`).

The server's role is deliberately small: declare the extension, serve the
UI document as a `ui://` resource, and link it to the tool through
`_meta`. The extension adds **no new server RPC method** — a UI-originated
action arrives as an ordinary `tools/call`, and the host↔iframe
`ui/*` postMessage envelope never reaches the server. This SDK bundles the
whole convention into one `McpServer::ui()` call.

A complete runnable example lives at
[`examples/apps_server/`](../examples/apps_server/) — server, dashboard
view, and README.

## A complete Apps server

```php
<?php

require 'vendor/autoload.php';

use Mcp\Server\McpServer;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

$server = new McpServer('weather-apps-server');

$server
    // An ordinary tool. It returns BOTH a text `content` block (so non-UI
    // hosts and the model still get a usable answer) and `structuredContent`
    // (the UI-optimized payload the dashboard renders).
    ->tool(
        'get_weather',
        'Get the current weather for a city',
        function (string $city): CallToolResult {
            $data = ['city' => $city, 'temperatureC' => 21, 'condition' => 'Sunny'];

            return new CallToolResult(
                content: [new TextContent(text: "Weather in {$city}: {$data['condition']}, {$data['temperatureC']}°C")],
                structuredContent: $data,
            );
        },
    )

    // Link the tool to its UI template.
    ->ui(
        tool: 'get_weather',
        uri: 'ui://weather/dashboard',
        name: 'Weather Dashboard',
        html: <<<'HTML'
            <!DOCTYPE html>
            <html><body>
            <h1 id="w">Loading…</h1>
            <script>
            // Handshake with the host, then render the tool result.
            const post = (msg) => window.parent.postMessage({jsonrpc: '2.0', ...msg}, '*');
            window.addEventListener('message', (event) => {
                const msg = event.data;
                if (msg?.id === 1) {                       // ui/initialize response
                    post({method: 'ui/notifications/initialized', params: {}});
                } else if (msg?.method === 'ui/notifications/tool-result') {
                    const d = msg.params?.structuredContent;
                    if (d) document.getElementById('w').textContent =
                        `${d.city}: ${d.condition}, ${d.temperatureC}°C`;
                }
            });
            // All three params are required (appInfo, appCapabilities,
            // protocolVersion — the Apps extension revision, not the MCP
            // protocol version).
            post({id: 1, method: 'ui/initialize', params: {
                appInfo: {name: 'weather-view', version: '1.0.0'},
                appCapabilities: {},
                protocolVersion: '2026-01-26',
            }});
            </script>
            </body></html>
            HTML,
    )

    ->run();
```

That single `ui()` call does three things:

1. **Registers the `ui://` resource** carrying the HTML document with MIME
   `text/html;profile=mcp-app` (`McpServer::UI_MIME_TYPE`), so hosts can
   prefetch, cache, and security-review the view ahead of execution. It
   appears in `resources/list` and is served by `resources/read` like any
   resource.
2. **Links the tool to the view** through the tool's `_meta.ui.resourceUri`
   (the deprecated flat `_meta["ui/resourceUri"]` key is written alongside
   it for host back-compat during the extension's pre-GA window, mirroring
   the reference ext-apps server SDK).
3. **Declares the Apps extension** in the server's capabilities —
   `extensions["io.modelcontextprotocol/ui"] = {mimeTypes: [...]}` —
   advertised in both the legacy `initialize` result and the modern
   `server/discover`.

The `<script>` block is the view's half of the contract, and it is not
optional: a host delivers the tool result only after the view completes
the `ui/initialize` → `ui/notifications/initialized` handshake, so a
script-less document just renders its static HTML forever (you would see
"Loading…" and nothing else). The full protocol is described in
[The view document](#the-view-document) below.

Rules: the URI must begin with `ui://`, and the tool must already be
registered (call `tool()` before `ui()`). The `html` argument accepts a
string or a callback invoked lazily at read time — use a callback for
views generated on demand or read from disk only when a host actually
fetches them.

## Feeding the view: `structuredContent`

The host pushes the tool's result into the iframe, and a well-built view
renders `structuredContent` — the machine-readable half of the result —
rather than parsing display text. The pattern:

- `content` — human/model-readable text. **Always provide it**; it is what
  non-UI hosts and the model see.
- `structuredContent` — the JSON payload the view renders.

## The view document

The HTML document is a self-contained page that speaks JSON-RPC over
`postMessage` with the host: it performs the `ui/initialize` →
`ui/notifications/initialized` handshake, receives the tool result via
`ui/notifications/tool-result`, and may call back through the host (e.g.
sending `tools/call` to re-run the tool with new arguments). None of that
traffic touches your PHP server — the host mediates everything. The
`<script>` in the snippet above is the minimal implementation of this
contract; see
[`examples/apps_server/dashboard.html`](../examples/apps_server/dashboard.html)
for a full-featured one (styling, `tool-input` prefill, and calling the
tool back through the host).

## Host hints

All optional, all advisory — hosts may ignore any of them:

| Argument | Meaning |
| --- | --- |
| `visibility` | Subset of `['model', 'app']` on the *tool*: who may invoke it. `['app']` hides a tool from the agent and exposes it only to the rendered UI (useful for UI-internal refresh actions). Omitted = both. |
| `csp` | Content-Security-Policy domain allowlists for the sandboxed view, keyed by `connectDomains` / `resourceDomains` / `frameDomains` / `baseUriDomains`. Omit when the view fetches nothing external. |
| `permissions` | Browser permissions the view requests: subset of `camera` / `microphone` / `geolocation` / `clipboardWrite`. |
| `domain` | Optional dedicated sandbox origin (host-defined). |
| `prefersBorder` | Whether the view prefers a visual border/background. |

The resource-level hints (`csp`, `permissions`, `domain`, `prefersBorder`)
are emitted as `_meta.ui` on the `resources/read` content — where the
stable extension revision reads them — and mirrored on the listed resource
(where the draft revision also allows them; content takes precedence).
Invalid values (an unknown `visibility` entry, an out-of-range permission)
throw `InvalidArgumentException` at registration time.

Deployments that author hints into stored configuration long before a
server is built from it (for example, per-request server construction)
can run the same checks ahead of time with the static
`McpServer::validateUiHints(?array $visibility, ?array $csp, ?array
$permissions)` — it delegates to the validators `ui()` itself uses, so the
two can never disagree. The allowed values are also public constants for
configuration UIs to enumerate: `McpServer::UI_VISIBILITY`,
`McpServer::UI_CSP_KEYS`, and `McpServer::UI_PERMISSIONS`.

## Graceful degradation

Automatic, by construction: the linked tool keeps returning its ordinary
`content`, and a host that cannot render the UI simply ignores `_meta.ui`.
Nothing about the UI is required for the tool to function — which is also
why the one rule of thumb matters: **always return a meaningful text
`content` block**, never a UI-only result.

## Notes

- The extension defines no size bound on view documents; limits are
  host-defined. Keep views lean — they are fetched and sandboxed by the
  host.
- Apps work on both protocol eras: the extension declaration rides
  `initialize` for legacy hosts and `server/discover` for `2026-07-28`
  hosts.
- Serving the view is plain `resources/read` over standard HTTP — nothing
  about Apps requires SSE, sessions, or long-running processes, so it
  works unchanged on shared hosting.
