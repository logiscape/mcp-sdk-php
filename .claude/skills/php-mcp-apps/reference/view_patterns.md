# View Patterns for MCP Apps

How to write the HTML view — the half of an MCP App that runs in the host's sandboxed
iframe. Start every view by copying `../assets/view-template.html`, which implements
everything in "The bridge" below; this file explains the patterns and the judgment
calls. Exact wire shapes: `apps_protocol.md`.

## Table of contents

- [Ground rules](#ground-rules)
- [The bridge (what the template already does)](#the-bridge-what-the-template-already-does)
- [Rendering: structuredContent is your props](#rendering-structuredcontent-is-your-props)
- [Loading states](#loading-states)
- [Theming that survives every host](#theming-that-survives-every-host)
- [Talking back: three ways, three meanings](#talking-back-three-ways-three-meanings)
- [Calling tools from the view](#calling-tools-from-the-view)
- [State and persistence](#state-and-persistence)
- [Sizing and display modes](#sizing-and-display-modes)
- [Accessibility and mobile](#accessibility-and-mobile)
- [Frameworks and build tools](#frameworks-and-build-tools)
- [Sandbox gotchas](#sandbox-gotchas)

## Ground rules

1. **One self-contained file.** All CSS and JS inline; images as data: URIs or from
   CSP-declared domains. The default sandbox CSP blocks every external request, and a
   blocked `<script src>` typically kills your bridge code with it.
2. **The handshake is mandatory.** No `ui/initialize` → `initialized` exchange means
   the host never delivers the tool result. This is the #1 "why is it blank" cause.
3. **Render data, not prose.** The view renders `structuredContent`; parsing display
   text is brittle and breaks the moment the server rewords a sentence.
4. **The view is cached.** Hosts fetch the template once per URI. Design it like a
   deployed SPA: data-driven, backward-tolerant of missing fields, versioned via the
   `ui://` URI when you change it.

## The bridge (what the template already does)

`view-template.html` gives you a ~80-line bridge exposing:

- `bridge.request(method, params)` / `bridge.notify(method, params)` — JSON-RPC out
- `bridge.on(method, fn)` — host notifications in (`tool-input`, `tool-result`, ...)
- `bridge.onRequest(method, fn)` — host requests in (`ui/resource-teardown` must be
  answered or hosts may consider the view hung during shutdown)
- helpers: `callTool`, `sendMessage`, `updateModelContext`, `openLink`,
  `requestDisplayMode`
- automatic: handshake, host-context theming, `ResizeObserver` → `size-changed`,
  skeleton/error/content state switching

Replace the marked block in `render()` with your UI; leave the plumbing alone.

## Rendering: structuredContent is your props

```js
function render(result) {
  if (result.isError) { /* surface result.content text */ }
  const data = result.structuredContent;   // <- the contract with your PHP tool
  // Defensive: hosts cache templates, servers evolve. Tolerate missing fields.
  const items = Array.isArray(data?.listings) ? data.listings : [];
  ...
}
```

- Treat `data` as untrusted for DOM purposes: build nodes with `textContent` /
  `createElement`, not `innerHTML` concatenation — your own server data can contain
  user-generated strings (titles, names) and the iframe is still your reputation.
- The same `render()` should be reusable for results the view fetches itself via
  `callTool` — one render path, two triggers (host push, user action).

## Loading states

Timeline you must handle: iframe appears → (maybe) `tool-input-partial` (streaming
args) → `tool-input` → *host runs the tool* → `tool-result`. The gaps are visible to
the user, so:

- Show a **skeleton immediately** (the template's default state) — never a blank frame.
- On `tool-input`, upgrade the skeleton with intent: "Searching listings in Lisbon…".
- Do **not** gate first paint on `tool-input`: some hosts deliver input only after
  the user approves the call, and on re-render of an old message you may get
  `tool-result` alone.
- Handle `tool-cancelled` (show a quiet "cancelled" state) and `isError` results
  (readable message, not a dead widget).

## Theming that survives every host

Three layers, in order of preference, all in the template:

1. **Host CSS variables** with fallbacks:
   `background: var(--color-background-primary, Canvas);`
   Claude injects ~60 variables (colors, fonts, radii, shadows — list in
   `apps_protocol.md`); using them makes your app look native for free.
2. **`hostContext.theme`** — the template mirrors it to
   `<html data-theme="dark">` and `color-scheme`. Target it for anything variables
   don't cover: `[data-theme="dark"] .chart-line { stroke: #eee; }`. (If you use
   Tailwind: `darkMode: ['selector', '[data-theme="dark"]']` — class-based dark mode
   never fires here.)
3. **`color-scheme: light dark`** + system colors (`Canvas`, `CanvasText`) as the
   floor, so even a host sending nothing renders legibly in both modes.

React to `ui/notifications/host-context-changed` (the template re-applies
automatically) — users toggle theme mid-conversation. Brand color: accents only.

## Talking back: three ways, three meanings

The classic App bug: the user acts in the UI and the model doesn't know. After any
meaningful user action, pick exactly one:

| Mechanism | Effect | Use when |
|---|---|---|
| `sendMessage(text)` → `ui/message` | Posts into the chat; the model reacts **now** | The action should drive the conversation: "I've selected the 14:30 slot — please confirm the booking." |
| `updateModelContext(text)` → `ui/update-model-context` | Silently updates model-visible state; no chat message, no model turn | Ambient state the model should know *if asked*: current filter, sort order, which tab is open, cart contents. |
| Nothing | Model stays ignorant | Pure presentation: hover, scroll, collapsed sections. |

Prefer **intent over transcript**: send "User added item #142 (Blue Chair, $89) to
the cart" — a statement of what happened — rather than dumping raw state. Keep both
channels terse; they consume the model's context. Don't spam `ui/message`: every one
triggers a model turn the user watches. And note both are **requests, not
fire-and-forget**: the host may ask the user for consent and may deny — `await` them
and degrade quietly on rejection (the template's helpers return the promise).

## Calling tools from the view

```js
const result = await callTool('board_page', { board: 'default', page: 2 });
render(result);
```

- The call is proxied by the host to your PHP server as an ordinary `tools/call` —
  same handler, same validation, possibly a user consent prompt (design for latency
  and denial: disable the button, show a spinner, re-enable on failure).
- Tools meant only for the view should be registered with `visibility: ['app']`
  server-side so the model can't misfire them.
- Debounce user-driven calls (search-as-you-type must not fire per keystroke — a
  human is watching each consent prompt and paying for each request).
- On rejection (`err.code`/`err.message` from the bridge promise), show the message;
  never silently retry a denied call.

## State and persistence

- **In-memory** is fine for the lifetime of one rendered view.
- **Across re-renders** the iframe may be torn down and recreated (scrollback,
  reopened conversations) — you'll receive the same `tool-result` again. Make
  rendering idempotent from the result; don't depend on prior JS state.
- **`ui/resource-teardown`** (host request) is your save hook: persist anything
  worth keeping (e.g. via a fire-and-forget `['app']` tool call to your server),
  then respond.
- **Durable state lives server-side.** There is no standard cross-conversation
  widget storage; ChatGPT's `widgetState` is proprietary and scoped to the widget
  instance. If a draft or preference matters, store it through a tool.

## Sizing and display modes

- Report height on content change: the template's `ResizeObserver` →
  `ui/notifications/size-changed` handles it. Without this, hosts clip or letterbox.
- Respect `containerDimensions.maxHeight`; scroll *inside* your layout if content
  exceeds it — but avoid nested vertical scrolling inline (host design rule).
- Start `inline`. Fullscreen has three spec-mandated preconditions, all handled by
  the template's helper: (1) declare the mode in
  `appCapabilities.availableDisplayModes` at `ui/initialize` (`APP_DISPLAY_MODES` —
  hosts MUST NOT switch you to an undeclared mode, so an undeclared request can't
  work); (2) check the host offers it (`hostContext.availableDisplayModes` — absent
  on some hosts, e.g. M365 Copilot); (3) apply the **returned** mode, which may
  differ from what you asked for:
  ```js
  const { mode } = await requestDisplayMode('fullscreen');
  if (mode === 'fullscreen') enterFullscreenLayout();   // else stay inline
  ```
  Offer the switch behind an explicit user control. In fullscreen the host chat
  composer stays overlaid — leave breathing room at the bottom and keep working
  with the conversation, not instead of it.

## Accessibility and mobile

- Minimum viewport 320px wide; test there.
- Touch targets ≥ 44×44px; respect `hostContext.safeAreaInsets` on mobile.
- WCAG AA contrast in both themes; alt text on images; the app must tolerate text
  resize. Keyboard: interactive elements are real `<button>`/`<a>`/`<input>`, not
  clickable divs.
- Camera/microphone/geolocation are not granted on Claude mobile even if declared —
  always ship a fallback path (file upload, manual entry).

## Frameworks and build tools

Vanilla HTML/JS is the default and is deliberately aligned with this SDK's no-build
ethos — a `views/*.html` file, editable in place, previewable in a browser. Prefer it
for anything card/form/carousel-sized.

For genuinely app-like views (canvas editors, maps, big tables) a framework is fine
if the output is still one file:

- Vite + `vite-plugin-singlefile` → a single `dist/index.html`; load it from PHP with
  `html: fn() => file_get_contents(__DIR__ . '/dist/index.html')`. Commit the built
  artifact so shared-hosting deployment stays copy-only (no Node on the server).
- The official `@modelcontextprotocol/ext-apps` npm package provides an `App` class
  and React hooks implementing the same bridge; its `examples/` directory (React,
  Vue, Svelte, Solid, Preact, vanilla starters and 15+ demo apps) is the reference
  gallery. Using it is optional — the template's bridge speaks the identical protocol.

## Sandbox gotchas

- `alert` / `confirm` / `prompt` — blocked. Use in-DOM dialogs.
- `window.open` / `<a target="_blank">` — blocked; use `openLink(url)`
  (`ui/open-link`), which also gets the host's safe-link handling.
- `navigator.clipboard` — blocked on some hosts unless `clipboardWrite` permission
  is declared; feature-detect and fall back to a visible "copy" text box.
- `localStorage` / cookies — unreliable across opaque-origin sandboxes; treat as
  absent (see State above).
- External fonts/CDNs — CSP-blocked unless declared; prefer `--font-sans` from the
  host, falling back to `system-ui`.
- `Date`/locale: format with `hostContext.locale` and `timeZone`
  (`new Intl.DateTimeFormat(locale, { timeZone })`) — the server's timezone and the
  user's rarely match.
- Console: errors inside the iframe are invisible unless you inspect the iframe or
  use MCPJam; during development add a visible error surface
  (`window.onerror` → the template's `#error` div saves hours).
