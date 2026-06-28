# MCP Apps example server

A minimal [MCP Apps](https://github.com/modelcontextprotocol/ext-apps)
(SEP-1865, stable revision `2026-01-26`) server built entirely on the public
`McpServer::ui()` helper.

## What it shows

- `apps_server.php` registers one ordinary tool (`get_weather`) and links it to
  a `ui://` HTML template with a single `->ui(...)` call. That call:
  1. registers `ui://weather/dashboard` as a resource with MIME
     `text/html;profile=mcp-app` (so the host can prefetch, cache, and
     security-review it),
  2. writes the tool's `_meta.ui.resourceUri` link (plus the deprecated flat
     `_meta["ui/resourceUri"]` key for host back-compat), and
  3. declares the Apps extension
     (`capabilities.extensions["io.modelcontextprotocol/ui"]`), advertised in
     `server/discover`.
- `dashboard.html` is the iframe view: a JSON-RPC-over-`postMessage` client that
  performs the `ui/initialize` → `ui/notifications/initialized` handshake,
  renders the tool result pushed via `ui/notifications/tool-result`, and can
  re-run the tool by sending `tools/call` back through the host.

The extension adds **no new server RPC method**: a UI-originated `tools/call`
is an ordinary tool call, and the host↔iframe `ui/*` envelope never reaches the
server.

## Run it

Over HTTP (what hosts connect to):

```bash
php -S localhost:8000 examples/apps_server/apps_server.php
```

Over stdio:

```bash
php examples/apps_server/apps_server.php
```

## Try it in a host

Point an MCP-Apps-capable host (e.g. Claude or VS Code) at the HTTP endpoint.
When the agent calls `get_weather`, the host fetches `ui://weather/dashboard`
and renders the dashboard, which displays the structured weather data and lets
you look up another city.

## Graceful degradation

A host that does not support MCP Apps simply ignores `_meta.ui`: the tool still
returns a normal text `content` block, so the model (and any non-UI client)
gets a usable answer. Nothing about the UI is required for the tool to work.
