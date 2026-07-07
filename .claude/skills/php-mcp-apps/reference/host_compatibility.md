# Host Compatibility, Distribution, and Testing

Per-host reality check for MCP Apps as of mid-2026, plus how to test and how to get
into each directory. Strategy first, matrix second, host specifics after.

## Table of contents

- [Portability strategy](#portability-strategy)
- [Host support matrix](#host-support-matrix)
- [Claude](#claude)
- [ChatGPT (OpenAI Apps SDK)](#chatgpt-openai-apps-sdk)
- [VS Code, M365 Copilot, Goose, Postman](#vs-code-m365-copilot-goose-postman)
- [Testing harnesses](#testing-harnesses)
- [Ecosystem context](#ecosystem-context)

## Portability strategy

Build to the **open standard first** (`text/html;profile=mcp-app`,
`_meta.ui.resourceUri`, the `ui/*` bridge — exactly what `McpServer::ui()` emits),
then layer host extras behind **feature detection**, never user-agent branching:

```js
const res = await bridge.request('ui/initialize', {
  appInfo: { name: 'my-app-view', version: '1.0.0' },      // required
  appCapabilities: { availableDisplayModes: ['inline'] },  // declare what you implement
  protocolVersion: '2026-01-26',                           // required
});
const caps = res.hostCapabilities || {};
// if (caps.updateModelContext) ... ; if (window.openai?.uploadFile) ...
```

`hostInfo.name` is for logging, not logic. Isolate host-specific code in one adapter
so the rest of the view is standard.

## Host support matrix

The canonical, maintained list is the official client matrix at
`modelcontextprotocol.io/extensions/client-matrix` — check it before promising
support for any host. Snapshot as of mid-2026:

| Host | Standard Apps support | Notes |
|---|---|---|
| Claude (web, Desktop, iOS/Android, Cowork) | Yes — launch host, Jan 2026 | "Interactive connectors"; enable via claude.ai/directory or custom connector |
| **Claude Code** | **No** | Terminal client — your tool's text `content` is all it shows |
| ChatGPT | Yes | Standard format + proprietary `window.openai` extras; legacy skybridge still accepted |
| VS Code / GitHub Copilot | Yes | In the official client matrix |
| Microsoft 365 Copilot | Yes — partial | In the official matrix; notable gaps below |
| Goose (Block) | Yes | Labeled experimental/minimal |
| Postman | Yes | Also renders legacy mcp-ui format |
| MCP Inspector (official) / MCPJam | Yes | The dev/test harnesses |
| Cursor | Yes — v2.6, Mar 2026 | In the official client matrix |
| Archestra.AI, PostHog Code | Yes | In the official client matrix |
| LibreChat | Legacy mcp-ui only | Official Apps support is an open feature request (PR in progress — re-check) |

Because Claude Code and other text-only clients are real consumers of the same
server, the graceful-degradation rule (meaningful text `content` always) is not
theoretical.

## Claude

**Where it renders:** Claude web, Desktop, mobile apps, Cowork — not Claude Code.
Users can disable interactive tool calls per conversation; Team/Enterprise owners can
disable org-wide. Purchases through interactive connectors are not supported (policy).

**Getting your server in front of Claude (no directory needed):** Settings →
Customize → Connectors → add your HTTPS endpoint (or
`claude_desktop_config.json` for local stdio). Custom connectors render Apps;
the directory is for distribution, not a rendering gate. Users grant per-app
permission on first render. Add connectors via web or desktop — mobile can use
them but cannot add them.

**Claude-specific constraints:**
- `frameDomains` (nested iframes) restricted pending security review — don't depend
  on embedding third-party frames.
- Mobile: no camera / microphone / geolocation grants; respect `safeAreaInsets`.
- Design language: use the injected CSS variables (`--color-*`, `--font-*` — Claude
  supplies "Anthropic Sans"; 50+ tokens documented), min 320pt viewport, 44×44pt
  targets, no floating panels in fullscreen, avoid popovers/dropdowns and nested
  scrolling inline.
- Claude's design guidance names three modes — inline, fullscreen, `pip` —
  with detailed patterns for the first two (carousel as an inline variant);
  `pip` is listed without a guidance section. Rely on the negotiated
  `availableDisplayModes` rather than assuming any mode.

**Directory submission** (claude.ai → admin settings → directory submissions):
OAuth 2.0 for authed services; accurate `title` + `readOnlyHint`/`destructiveHint`
annotations on every tool; documentation and a support channel; **3–5 PNG screenshots
≥1000px wide (response-area crops) each paired with the prompt that produced it**
(Anthropic publishes a Figma template). Review criteria are published — read them
before submitting. Questions/escalations: mcp-review@anthropic.com;
implementation bugs: github.com/anthropics/claude-ai-mcp.

## ChatGPT (OpenAI Apps SDK)

ChatGPT converged on the official format: the docs now lead with
`text/html;profile=mcp-app`, `_meta.ui.*`, and the `ui/*` bridge, keeping the
original 2025 "skybridge" forms as documented compatibility aliases (no announced
removal date). A server built with `McpServer::ui()` is format-compatible as-is.

One behavioral divergence to design for: ChatGPT surfaces `structuredContent` to the
model (its docs say the model reads those fields verbatim), while the Apps spec
baseline keeps it out of model context. Size `structuredContent` for ChatGPT's
budget; put anything the model *must* know in `content`.

**Optional OpenAI enrichments** — additive `_meta` keys a PHP server can attach for a
better ChatGPT experience (other hosts ignore them). Add them via the SDK's generic
extra-fields mechanism on the tool, alongside what `ui()` wrote — merge, don't
replace, the existing `_meta`:

| Key | Where | Meaning |
|---|---|---|
| `openai/toolInvocation/invoking`, `.../invoked` | tool `_meta` | ≤64-char status strings shown while/after the tool runs ("Searching flights…") |
| `openai/widgetDescription` | resource `_meta` | Human summary of the widget surfaced to the model |
| `openai/outputTemplate` | tool `_meta` | Legacy alias of `ui.resourceUri` — only for very old ChatGPT paths |
| `openai/widgetAccessible` | tool `_meta` | Legacy alias of `visibility` including `"app"` |

**`window.openai`** (proprietary, feature-detect): `toolInput`, `toolOutput`,
`toolResponseMetadata` (reliable path to result `_meta` there), `widgetState` /
`setWidgetState` (persistence scoped to the widget instance via
`openai/widgetSessionId`), `callTool`, `sendFollowUpMessage`, `requestDisplayMode`,
`requestModal` / `requestClose` (ChatGPT-owned modal; close the widget),
`notifyIntrinsicHeight` / `openExternal` / `setOpenInAppUrl` (proprietary analogs
of `size-changed` and `ui/open-link`, plus the fullscreen "Open in App" target),
file APIs (`uploadFile`, `selectFiles`, `getFileDownloadUrl`), and read-only
context (`theme`, `displayMode`, `maxHeight`, `safeArea`, `view`, `locale`).
`requestCheckout` (ChatGPT payment sheet) is documented on the Apps SDK
monetization page — private beta, select marketplaces, currently
physical-goods apps only.
Globals update via the `"openai:set_globals"` CustomEvent. All of it optional — the
standard bridge covers the core.

**Display modes:** inline / inline card / inline carousel / fullscreen / pip, with
published design guidance (cards ≤2 actions; carousels 3–8 items with imagery;
fullscreen keeps the composer overlaid; pip for live/ongoing activities).

**Submission (the strict one):** business/identity verification; publicly hosted
server; OAuth 2.1 per the MCP auth spec — PKCE (S256) mandatory, protected-resource
metadata at `/.well-known/oauth-protected-resource`, CIMD preferred or DCR
(the SDK's `Server/Auth/` classes cover the server side — see the SDK's
`examples/server_auth/` and docs);
**domain verification** of your MCP server's domain (a challenge served at the
domain root; most apps submit one universal MCP server URL); CSP declared
for every fetched origin; `readOnlyHint`/`destructiveHint`/`openWorldHint` on every
tool and **checked against actual behavior in review**; privacy policy consistent
with data actually collected. Updates trigger full re-review; the base MCP URL
cannot change after listing.

## VS Code, M365 Copilot, Goose, Postman

- **VS Code / GitHub Copilot:** standard format; in the official client matrix.
  Theming arrives through the standard `hostContext.styles` mechanism — VS
  Code's own docs don't detail which variables, so keep the fallbacks. Good
  second test host after the inspectors.
- **M365 Copilot:** supports both standard MCP Apps and OpenAI-Apps-SDK-style
  widgets inside declarative agents; listed in the official client matrix, with
  Microsoft Learn ("MCP apps in Microsoft 365 Copilot") documenting the gaps —
  treat support as real but partial.
  Published gaps: no `tool-input-partial`, no `host-context-changed`, no teardown,
  no `hostContext.availableDisplayModes`, `ui/request-display-mode` fullscreen only,
  and `prefersBorder` / `domain` / `permissions` / `frameDomains` / `baseUriDomains`
  ignored. Widgets render under a per-server
  `{hash}.widget-renderer.usercontent.microsoft.com` origin (allow it in your CORS
  config); OAuth 2.1 / Entra SSO; enterprise admin gating. A textbook case for the
  feature-detection rule above.
- **Goose:** minimal/experimental — a good "does my app degrade sanely" check.
- **Postman:** renders both official Apps and legacy mcp-ui resources.
- Known variance: some hosts strip custom result `_meta` from `tool-result` — the
  reason the skill says never to make it the only path to required data.

## Testing harnesses

1. **Official MCP Inspector** — `npx @modelcontextprotocol/inspector`, connect
   to `http://localhost:8000` (from `php -S localhost:8000 server.php`), then
   the **Apps** tab: select the app, fill the tool params, "Open App". It
   validates the bridge strictly (a `ui/initialize` missing `appInfo` or
   `protocolVersion` is rejected and the view never gets its result) — the
   best first conformance stop.
2. **MCPJam Inspector** — `npx @mcpjam/inspector`, same connection. Richer
   host emulation: bridge traffic log both directions, theme toggle, device
   and locale emulation.
3. **ext-apps `basic-host`** — the reference host implementation in
   `modelcontextprotocol/ext-apps` `examples/basic-host`; another strict
   standard-conformance check.
4. **Claude custom connector** — real-host verification pre-directory (above).
5. **ChatGPT developer mode** — enable in settings, add your server, iterate.
6. **Plain browser** — open the view file directly: it must fail gracefully into its
   skeleton state (the template guarantees this), which also makes visual/CSS work
   fast without any host.

Regression checklist per host: renders on first call · re-renders when scrolled back
· dark and light · view-initiated `tools/call` round-trips · text-only fallback reads
well · no console errors in the iframe.

## Ecosystem context

Useful background when researching or debugging:

- **Spec home:** github.com/modelcontextprotocol/ext-apps (stable `2026-01-26` +
  draft); official docs at modelcontextprotocol.io/extensions/apps and the
  per-host support matrix at modelcontextprotocol.io/extensions/client-matrix;
  SEP-1865 is the governing proposal (authored jointly by Anthropic, OpenAI,
  and the MCP-UI community).
- **Reference SDK:** npm `@modelcontextprotocol/ext-apps` (App class, React hooks,
  `app-bridge` for hosts, `registerAppTool`/`registerAppResource` server helpers) —
  the TypeScript mirror of what `McpServer::ui()` + the view template do here.
- **mcp-ui** (MCP-UI-Org/mcp-ui) — the community project whose patterns (together
  with the OpenAI Apps SDK) directly shaped SEP-1865; now implements the standard,
  plus non-standard extras (remote-dom, external-URL frames). Relevant if a client
  asks for "mcp-ui" by name.
- **Production patterns to study:** ChatGPT partner apps (Zillow maps, Booking/Expedia
  carousels, Canva/Figma canvases) and Claude launch partners (Amplitude/Hex
  dashboards, Asana/monday boards, Slack). The recurring shapes — card, carousel,
  picker, dashboard, canvas — are the recipes in `php_server_patterns.md`.
- The draft spec adds app-registered tools and `ui/download-file`; revisit when the
  next stable revision lands before using them.
