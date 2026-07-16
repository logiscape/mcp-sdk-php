# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Patch releases (`v1.7.X`) carry non-breaking bug fixes and minor features;
minor releases (`v1.X`) carry breaking changes, major features, and expanded
MCP protocol version support; a major release (`v2`) will align with a
corresponding `v2` in the wider MCP SDK ecosystem. See
[CONTRIBUTING.md](CONTRIBUTING.md) for the full versioning policy.

This file was introduced during the v1.7.x series. Structured entries below cover
**v1.6.0 and later**; earlier releases can be reviewed via the
[Git tag history](https://github.com/logiscape/mcp-sdk-php/tags).

## [Unreleased]

The v2 pre-release of the SDK, now in beta, adding day-one support for the
MCP `2026-07-28` "stateless core" spec revision alongside the existing
`2024-11-05` … `2025-11-25` support. Contains breaking API changes relative to v1; see
[docs/api-audit-v2.md](docs/api-audit-v2.md) for the full v1 → v2 API audit
that will feed the migration guide.

### Added

- **MCP `2026-07-28` protocol support** (SEP-2575 stateless core):
  - `server/discover` answered on stdio and HTTP without any prior
    handshake, fully sessionless on HTTP (no `Mcp-Session-Id` minted or
    echoed, responses are plain JSON); client-side probing via
    `ClientSession::discover()`. The new `MetaKeys` class holds the keys of
    the per-request `_meta` envelope (protocol version, client info, client
    capabilities).
  - Dual-era negotiation. Server side: the era is detected per request — a
    modern `_meta` envelope (or modern `MCP-Protocol-Version` header)
    selects stateless `2026-07-28` semantics on a fresh ephemeral context,
    while a legacy `initialize` is served unchanged. Methods removed by the
    stateless revision (`initialize`, `ping`, `logging/setLevel`,
    `resources/subscribe`/`unsubscribe`) answer `-32601` on the modern
    path, and the RC-window `DRAFT-2026-v1` identifier is accepted as an
    alias for `2026-07-28` on the per-request path only. Client side:
    `Client::connect()` probes `server/discover` first and falls back to
    the legacy handshake per the spec's rules; it gains `protocolMode`
    (`auto`/`legacy`/`modern`), `protocolVersion`, and `probeTimeout`
    options, and modern sessions stamp the `_meta` envelope on every
    request with the `MCP-Protocol-Version` header mirrored from it.
  - Caching hints (SEP-2549): `ttlMs`/`cacheScope` on the six cacheable
    result types via the new `CacheableResult` interface/trait, plus the
    `resultType` discriminator on every result — stamped with conservative
    defaults for `2026-07-28` clients and stripped for legacy clients.
  - JSON Schema 2020-12 in tool schemas (SEP-2106): composition,
    conditionals, and `$ref` are passed through without dereferencing, and
    `structuredContent` may be any JSON value — including an explicit
    `null` — when an `outputSchema` is declared, with adaptation for
    legacy clients.
  - W3C Trace Context pass-through (SEP-414): the reserved
    `traceparent`/`tracestate`/`baggage` keys in `_meta` with a new
    `TraceContext` accessor class; no OpenTelemetry dependency.
  - Request-metadata headers (SEP-2243): servers validate `Mcp-Method`,
    `Mcp-Name`, and a consistent `MCP-Protocol-Version` on modern HTTP
    requests (mismatches rejected with `-32020 HeaderMismatch`).
    Designated tool parameters (`x-mcp-header` schema annotations on
    string/integer/boolean properties) are validated server-side against
    the request's `Mcp-Param-*` headers and mirrored client-side from
    cached `tools/list` schemas, with strict exact-lowercase
    `=?base64?…?=` sentinel encoding for unsafe values. Shared rules live
    in `Mcp\Shared\McpHeaders`; stdio is exempt (headers are HTTP-only).
  - Subscription streams (SEP-2575): `subscriptions/listen` implemented on
    HTTP and stdio — the acknowledgement is the stream's first message,
    every frame carries the `io.modelcontextprotocol/subscriptionId`
    `_meta` key (preserving the listen id's original wire type), the
    `SubscriptionFilter` is strictly contained, and the new
    `SubscriptionsListenResult` is sent as a closing frame when the server
    ends a subscription on its own initiative. Event fan-out crosses
    processes through the new `SubscriptionBusInterface`
    (`FileSubscriptionBus` for typical PHP hosting,
    `InMemorySubscriptionBus` for tests and long-running runtimes) with
    `McpServer` publish helpers (`publishToolsListChanged()` etc.).
  - Multi-round-trip input (SEP-2322): on the modern path, `tools/call`
    and `prompts/get` answer `InputRequiredResult` (`resultType:
    "input_required"`, `inputRequests`, signed `requestState`) instead of
    server-initiated sampling/elicitation/roots requests.
    `ElicitationContext`/`SamplingContext` resolve from the round's
    `inputResponses` or suspend, and the new `InputContext` batches mixed
    elicitation + sampling + roots requests into a single round. The new
    `RequestStateCodec` HMAC-signs `requestState` and binds it to the
    authenticated principal, so tampering, expiry, and cross-user replay
    are rejected. Clients service `inputRequests` through `onElicit`, the
    new `onSampling`, and roots handlers, retrying with key-matched
    `inputResponses`, verbatim `requestState` echo, and a 16-round cap.
- **Tasks extension (SEP-2663), declared via the new SEP-2133 `extensions`
  capability.** A clean, breaking redesign of the pre-release experimental
  Tasks surface to the `2026-07-28` stateless model (see Removed):
  - Methods are `tasks/get` / `tasks/update` / `tasks/cancel`. A
    `tools/call` the server augments as a task returns a flat
    `CreateTaskResult` (discriminated by `resultType: "task"`); `tasks/get`
    returns a flat `DetailedTask` that inlines `result` (completed),
    `error` (failed), or `inputRequests` (input_required) by status;
    `tasks/update` and `tasks/cancel` return empty acks. Task fields are
    named `ttlMs` (always emitted, `null` = unlimited) and
    `pollIntervalMs`.
  - Server: `McpServer::enableTasks()` registers the task methods and
    declares the extension; a tool opts into task augmentation via
    `tool(..., taskSupport: …)` with the new `Mcp\Server\TaskSupport`
    (`FORBIDDEN` default / `OPTIONAL` / `REQUIRED`). The file-based
    `TaskManager` implements the SEP-2663 state machine (working ⇄
    input_required → terminal, `ttlMs` expiry, idempotent cancel). Every
    `tasks/*` method requires the client to have declared the extension
    (rejected `-32021` otherwise). Execution is synchronous-capture for
    shared-hosting compatibility; genuine async tasks are driven by the
    application via `McpServer::getTaskManager()`. In-task input reuses
    the SEP-2322 machinery: a task tool that elicits parks as
    `input_required`, surfaces its `inputRequests` via `tasks/get`, and
    resumes on `tasks/update`.
  - Client: `ClientSession::getTask()` / `updateTask()` / `cancelTask()`
    (and `Client` wrappers); `ClientSession::declareExtension()` declares
    extensions in the per-request capability envelope.
  - The SEP-2133 framework itself: a new `extensions` field on
    `ServerCapabilities`/`ClientCapabilities` and the
    `Mcp\Types\ExtensionIds` constants. A malformed (non-object) extension
    value is ignored, so it can never unlock a feature.
- **Application-driven task deferral (`TaskContext::defer()`).** A
  task-augmented tool can now hand its work to an out-of-band worker (a
  queue consumer, cron job, or another process) instead of finishing
  synchronously — the in-band entry point to the application-driven model
  the Tasks guide describes. The tool type-hints the new injectable
  `Mcp\Server\Tasks\TaskContext` (always injected non-null and stripped
  from the tool's input schema; inert on plain synchronous calls, with
  `isTask()`/`taskId()` for branching), hands the taskId to its worker,
  and calls `defer(?statusMessage)`: the task stays `working`, the client
  receives the flat `CreateTaskResult` handle and polls `tasks/get`, and
  the worker settles the record through the existing
  `McpServer::getTaskManager()` API. Deferral is race-safe against fast
  workers — anything the worker writes before `defer()` unwinds wins (an
  earlier progress message is kept, and a task the worker already settled
  is returned settled on the create response). Calling `defer()` outside a
  task round is a `-32603` programming error. `TaskManager` gains the
  `working → working` self-transition so workers can refresh progress
  (the documented worker call previously threw), and a transition to
  `working` sheds stale `inputRequests`/`requestState` so a resumed tool
  that defers surfaces handle-only. Documented in the Tasks guide
  ("Deferring to a background worker"); `examples/tasks_server.php`
  demonstrates the full flow with a `queue-batch` tool and a `--worker`
  mode that settles queued tasks.
- **MCP Apps extension (SEP-1865, ext-apps revision `2026-01-26`).**
  Server-side support for host-rendered tool UIs — capability declaration
  plus `_meta` plumbing; the extension adds no new RPC method:
  - The new `McpServer::ui(tool, uri, name, html, …)` helper attaches a
    `ui://` template resource to a registered tool in one call: it
    registers the resource with MIME `text/html;profile=mcp-app`
    (`McpServer::UI_MIME_TYPE`), links the tool through
    `_meta.ui.resourceUri` (dual-writing the deprecated flat key for host
    back-compat), and declares the extension. Optional host hints:
    `visibility`, `csp`, `permissions`, `domain`, and `prefersBorder`; the
    HTML may be a string or a callback invoked lazily at read time.
  - Declared through the new generic `Server::declareExtension()`,
    advertised in both `initialize` and `server/discover`. Graceful
    degradation is automatic: a host that cannot render the UI ignores
    `_meta.ui` and the tool still returns its ordinary `content`.
- Added a MCP Apps for PHP skill for AI coding agents.
- **Tool annotations on `McpServer::tool()`.** New trailing `annotations:`
  parameter (an array or a `Mcp\Types\ToolAnnotations`, normalized via the
  new `ToolAnnotations::parse()`) emitting the spec's `ToolAnnotations`
  behavioral hints (`readOnlyHint`/`destructiveHint`/`idempotentHint`/
  `openWorldHint`/`title`, spec revision `2025-03-26`) on listed tools;
  stripped automatically for clients that negotiated `2024-11-05`.
- **Feature-lifecycle deprecations (SEP-2596/SEP-2577).** The spec's
  deprecated-features registry is mirrored as
  `Mcp\Shared\FeatureLifecycle` (Roots, Sampling, Logging, and Dynamic
  Client Registration deprecated at `2026-07-28`; the `includeContext`
  sampling values at `2025-11-25`). There is no wire-level deprecation
  signal and wire behavior is unchanged — deprecated features keep
  working. The SDK emits one PSR-3 warning per feature per session when a
  deprecated feature is exercised on a session whose negotiated revision
  deprecates it, and the affected `Types/` classes, capability slots, and
  feature APIs carry `@deprecated` docblocks.
- **Client authorization hardening.** SEP-2468 `iss` validation per RFC
  9207 (error params never acted on when `iss` fails; new
  `AuthorizationCallbackResult`, backward compatible with string-returning
  handlers); SEP-837 `application_type` on dynamic registration; SEP-2352
  authorization-server migration — the PRM is re-fetched on 401 and on 403
  `insufficient_scope`, tokens and credentials are never reused across
  issuers, and pre-registered credentials are issuer-bound via the new
  `ClientCredentials::$issuer`, required by default (the explicit
  `OAuthConfiguration::$allowUnboundClientCredentials` flag restores the
  published-spec behavior of pinning to the first validated issuer for the
  process lifetime); SEP-2207 `offline_access` gating on
  `scopes_supported`; the `client_credentials` grant (private_key_jwt with
  ES256/RS256 assertions and client_secret_basic); and the SEP-990
  cross-app-access flow (RFC 8693 token exchange + RFC 7523 jwt-bearer).
- New typed exceptions `Mcp\Shared\UnknownMethodException`,
  `Mcp\Client\Transport\ReadTimeoutException`, and
  `Mcp\Server\Transport\TransportClosedException`, replacing
  exception-message string matching (messages unchanged for backward
  compatibility).
- `Client::onSampling()` — a pre-connect registration wrapper mirroring
  `onElicit()`/`onListRoots()`, so a sampling handler can be registered
  through the public `Client::connect()` flow (session-level
  `ClientSession::onSampling()` must run before initialization, which
  `connect()` performs; without the wrapper the capability could not be
  advertised). Applied on both `connect()` and `resumeHttpSession()`.
- Pagination cursors on the client list methods, and the new
  `ClientSession::listResourceTemplates()`. `listTools()`, `listPrompts()`,
  `listResources()`, and `listResourceTemplates()` accept an optional
  opaque cursor (a previous page's `nextCursor`), so paginated catalogs
  can be walked entirely through the convenience API — matching the
  Python and TypeScript SDKs — where pagination previously required
  dropping to `sendRequest()` with the typed `List…Request` classes.
  Calls without a cursor are wire-identical to before. On the modern HTTP
  path, a continuation page merges into the SEP-2243 annotation caches
  instead of resetting them, so paginating never drops an earlier page's
  `Mcp-Param-*` hints or rejection guards; a fresh (cursor-free) listing
  still resets both caches as before.
- New examples, one per major v2 feature — `stateless_server.php`,
  `client_negotiation.php`, `tasks_server.php` / `tasks_client.php`,
  `elicitation_server.php` / `elicitation_client.php`, and
  `examples/apps_server/` — plus a new `examples/README.md` index. Every
  runnable example was executed end-to-end over both stdio and HTTP.
- Dual-track conformance testing for the `2026-07-28` RC window: the
  stable conformance tool pin remains the legacy regression gate
  (`composer conformance` — its baseline is now empty, 100% of stable
  scenarios pass), and a second pin on the upstream RC-validation line
  runs the draft-spec suite (`composer conformance-draft`) against
  `conformance/conformance-draft-baseline.yml`, with a CI job for each
  track. See [conformance/README.md](conformance/README.md).
- [docs/v2-development-plan.md](docs/v2-development-plan.md) — the working
  plan for v2 development — and
  [docs/api-audit-v2.md](docs/api-audit-v2.md) — the v1 → v2 API audit
  feeding the migration guide. v2 pre-release notice added to the README.
- The v2 documentation set: a v1 → v2 **migration guide**
  ([docs/migration-v2.md](docs/migration-v2.md)) covering every breaking
  and behavioral change with executed before/after code and the
  deprecated-features registry; **extension guides** for Tasks
  ([docs/tasks.md](docs/tasks.md)) and MCP Apps
  ([docs/apps.md](docs/apps.md)); a documentation **index**
  ([docs/README.md](docs/README.md)) labeling every document's audience;
  and a README rewritten as the v2 front door. The
  [server](docs/server-dev.md) and [client](docs/client-dev.md)
  development guides were overhauled to teach the `2026-07-28` model as
  the default with legacy-only behavior explicitly marked — covering the
  stateless lifecycle, `server/discover`, dual-era negotiation, caching
  hints, `subscriptions/listen` publishing, SEP-2243 designated
  parameters, multi-round-trip input, the Tasks client API, and the
  OAuth additions. Every code snippet in the new and overhauled
  documents was extracted verbatim and executed (or, for fragments,
  syntax-checked) against the SDK. AGENTS.md, CONTRIBUTING.md,
  docs/testing.md (now the canonical test-stack reference),
  docs/compatibility.md, tests/README.md, ROADMAP.md, and SUPPORT.md
  were swept for v1-era drift in the same change set.

### Changed

- The `initialize` handshake now caps at the new
  `Version::LATEST_LEGACY_PROTOCOL_VERSION` (`2025-11-25`) — the handshake
  itself is removed in `2026-07-28` (SEP-2575), so the stateless revision
  is only selectable via the per-request `_meta` envelope. The client's
  `initialize()` now requests the latest legacy revision.
- The new `2026-07-28` error codes follow the draft allocation policy:
  `HeaderMismatch` `-32020`, `MissingRequiredClientCapability` `-32021`,
  and `UnsupportedProtocolVersion` `-32022` (constants on
  `Mcp\Shared\McpError`). Legacy (`2025-11-25` and earlier) behavior is
  unchanged.
- SEP-2164: a missing resource is reported as `-32602` (Invalid params) to
  `2026-07-28` clients while legacy revisions keep `-32002`; both shapes
  now carry the requested `uri` in `error.data`, and a missing resource is
  always an error, never an empty `contents` array.
- HTTP client error handling brought to parity with stdio: JSON-RPC error
  responses now surface to callers as typed `Mcp\Shared\McpError` with
  code and data intact, where the HTTP transport previously threw an
  opaque `RuntimeException("Critical MCP error: …")`. A configured client
  `readTimeout` is now also enforced against a peer that sends nothing at
  all. Both are wire-compatible behavior fixes; the `McpError` change is
  API-visible to v1 code that caught `RuntimeException` from HTTP calls.
- `McpServer`'s tools/call wrapper now propagates SDK-raised `McpError`s
  as JSON-RPC protocol errors (matching the existing `McpServerException`
  handling) instead of converting them into `isError` tool results — an
  API-visible change for tool handlers that deliberately throw `McpError`.
- `ClientSession::callTool()` now returns `CallToolResult|CreateTaskResult`
  — it surfaces a task handle when the server augments the call as a task.
- `Server::getCapabilities()` derives the `resources.subscribe` capability
  from actual `resources/subscribe` handler registration instead of
  hardcoding `false`, so the advertisement matches what the server really
  serves.
- `examples/server_auth/` modernized onto the fluent `McpServer` API with
  `withAuth()` (same filename and endpoints), and its `test-client.html`
  now exercises both protocol eras.
- ROADMAP.md updated: `v2` confirmed as the release vehicle for
  `2026-07-28` day-one support, and the MCP Apps extension (SEP-1865)
  promoted to a committed v2 release feature.
- ROADMAP.md rewritten now that v2 is feature-complete and in testing:
  day-one support for each MCP spec release is codified as the project's
  standing top priority, the immediate items narrow to what remains for
  the `2026-07-28` release gates, and a post-v2 direction is laid out —
  dependency-free embedding and web-integration batteries, with framework
  bridge packages documented as a possible future. See
  [ROADMAP.md](ROADMAP.md) for the plan.

### Removed

- The pre-release experimental Tasks API, replaced by the SEP-2663
  extension without deprecation shims: the `tasks` capability slot and
  `TaskCapability` type; the `tasks/list` and `tasks/result` methods (now
  answered `-32601`; the completed result is inlined in the `tasks/get`
  response) with their `TaskListRequest`/`TaskListResult`,
  `TaskResultRequest`, and `TaskStatusNotification` types;
  `ClientSession::listTasks()` and `getTaskResult()`; the
  `io.modelcontextprotocol/related-task` `_meta` key; and the previously
  stubbed `$task` parameter on
  `ElicitationContext::form()`/`url()`/`requiresForm()`.

### Fixed

- Tool-annotation stripping for pre-`2025-03-26` clients now removes only
  `annotations` and preserves every other tool field — `title`, `icons`,
  `outputSchema`, `execution`, and extra fields such as the Apps
  `_meta.ui` link (the strip path previously rebuilt the tool with only
  name/inputSchema/description) — and `tools/list` results are now walked
  per-tool (the adaptation previously matched only a bare `Tool` result
  and never fired on the list path).
- An empty `ToolAnnotations` now serializes as the spec's object shape
  (`{}`) instead of PHP's default `[]` for an empty array, matching the
  SDK's established empty-object handling (`Meta`,
  `ExperimentalCapabilities`, …) — previously a parsed
  `"annotations": {}` re-emitted as a JSON array on round-trip.
- The stdio server now detects EOF on stdin and shuts down cleanly instead
  of busy-waiting forever
  ([#61](https://github.com/logiscape/mcp-sdk-php/issues/61)):
  `StdioServerTransport::readMessage()` throws the new
  `TransportClosedException` at EOF, which `ServerSession` treats as a
  clean shutdown signal, and handler-wrapping catch blocks rethrow it so a
  tool blocked on a stdio elicitation/sampling round-trip cannot swallow
  the shutdown into an error response aimed at a dead stream.
- `ClientSession::negotiate()` performs the spec's select-and-continue
  corrective retry after `-32022 UnsupportedProtocolVersion` exactly once,
  with the advertised version permitted to equal the one just rejected; a
  second `-32022` propagates instead of looping.
- HTTP session detach/resume now restores the modern (`2026-07-28`) era:
  `ClientSession::createRestored()` and `Client::resumeHttpSession()`
  accept a new optional `$modernWireVersion` parameter and auto-detect
  modern mode when the persisted negotiated version is a modern revision,
  so resumed sessions stamp the per-request `_meta` envelope again and
  skip the legacy-only resume steps. Legacy resumes are byte-identical to
  before. The webclient reference persists and passes through the wire
  version.
- `HttpServerRunner` no longer leaks the ephemeral modern session into a
  later legacy request on a reused runner instance (long-running runtimes,
  embedding, tests): the modern session is installed only for the duration
  of its own dispatch and the previous session is restored on every exit
  path.
- The SEP-2352 issuer-migration guard is durable across PHP processes
  (enforced through issuer-bound credentials rather than mutable migration
  state — see the authorization-hardening entry under Added) and also runs
  on the 403 `insufficient_scope` path, so a step-up flow cannot carry
  credentials to a new issuer unchecked. The webclient reference collects
  and persists the issuer alongside pre-registered credentials and gates
  the legacy unbound mode behind an explicit checkbox.
- `RequestStateCodec::withFileSecret()` hardened for multi-tenant hosts:
  the per-installation signing secret initializes race-safely under an
  exclusive lock (reclaiming stubs left by crashed writers), locks its
  permissions to 0600 before any secret byte is written, and refuses
  symlinks and foreign-owned or other-readable files at the predictable
  default shared-temp path (POSIX).
- Resource-content `_meta` now round-trips: `ResourceContents` no longer
  leaks the trait's internal extra-field storage as a literal wire key on
  serialize, and `TextResourceContents`/`BlobResourceContents` preserve
  extra fields on parse — so a client retains the SEP-1865 `_meta.ui`
  hints on `resources/read` content. `ExtraFieldsTrait` gains
  `setExtraField()`/`getExtraField()` accessors.
- `HttpServerSession::toArray()` deep-normalizes `clientParams`, so
  declared client capabilities (e.g. a bare `elicitation: {}`) survive
  cross-request restoration on session stores that keep PHP values in
  memory.
- `ServerSession::adaptResponseForClient()` adapts a shallow clone instead
  of mutating the handler's `Result` in place — a handler-cached result
  served to a legacy client no longer loses its `ttlMs`/`cacheScope` for
  subsequent modern clients, and modern stamping no longer leaks SDK
  defaults into handler state.
- `initialize` requests carrying `_meta` (e.g. SEP-414 trace context) no
  longer crash typed request construction with a `TypeError`.
- `TaskManager`'s file store is now safe for concurrent cross-process
  mutation — load-bearing for the deferred-worker flow, where a worker,
  `tasks/get`, and `tasks/cancel` race across processes. Every state
  transition runs its complete read-validate-write under a per-record
  advisory lock (a `.lock` sidecar file, `flock` `LOCK_EX`, removed with
  the record and swept by `cleanup()` when orphaned), closing the
  lost-update interleaving where a stale read could overwrite a newer
  transition and resurrect a cancelled or completed task back to
  `working`. Reads take `LOCK_SH`, and writes open non-truncating (`'c'`)
  and truncate only after the exclusive lock is held —
  `file_put_contents(..., LOCK_EX)` truncates before acquiring the lock,
  so a reader could observe an empty record and treat a live task as
  missing. `cleanup()`, which previously read without a lock and deleted
  any record that failed to decode, reads under `LOCK_SH` too, so it can
  no longer delete a live task caught mid-write. The locks are advisory
  and assume a local filesystem, like the file store itself.
- Injected context parameters no longer leak into advertised metadata:
  `buildSchemaFromCallback()` now strips an `InputContext` parameter from
  the reflection-built tool `inputSchema` like the other four injectable
  contexts (an `InputContext`-hinted tool previously advertised a bogus
  required string property — per the `2026-07-28` spec, `inputSchema`
  describes only the client-supplied `tools/call` arguments, and SEP-2322
  input gathering is out-of-band), and prompt registration applies the
  same stripping so a context-hinted prompt callback no longer advertises
  a phantom required argument in `prompts/list`. Dispatch was already
  correct; only the advertised schemas change.

## [1.7.3]

### Added

- URI template matching engine that decides whether a concrete URI matches a
  registered URI template and extracts the variable values.
- Implements resources templates as an API that aligns with the rest of the SDK.
- Server-side completions API
- Server and client doc additions

### Removed

- Old examples that used low-level functions instead of the modern MCP API wrapper

## [1.7.2]

### Added

- Published a day-one support roadmap for the MCP `2026-07-28` "stateless core"
  spec revision (RC locked 2026-05-21). Adoption is additive and
  version-negotiated — the new stateless paths run only for clients that
  negotiate `2026-07-28`, leaving `2024-11-05`…`2025-11-25` core flows untouched.
  The experimental Tasks primitive will be replaced cleanly with the SEP-2663
  extension. See [ROADMAP.md](ROADMAP.md). No protocol changes ship in this entry;
  this records the planned direction only.
- Client side documentation guide
- `ClientSession::onListRoots()` and `Client::onListRoots()` register a
  `roots/list` handler and advertise the `roots` capability in the
  initialization handshake (`listChanged: true` by default; pass
  `listChanged: false` for a static root set). Call before `connect()` /
  `initialize()`.
- `ClientSession::onElicit()` and `Client::onElicit()` accept a new
  `supportsUrlMode` parameter (default `false`) that opts the client into
  advertising the `url` sub-capability for elicitation, alongside `form`.
  Without this flag spec-compliant servers will only send form-mode
  elicitation/create requests; the dispatch path for URL mode requests was
  already in place but unreachable in practice.

### Changed
- Updated CI GitHub action for Node.js 24
- ElicitationCompleteNotification moved to the correct union type
- Expand server side documentation guide
- CancelledNotification now serializes requestId and optional reason under
  params, matching the MCP spec.

### Fixed

- Notifications carrying a `_meta` object no longer throw a `TypeError` while
  parsing. `_meta` is a typed `?Meta` property on `NotificationParams`, but the
  factory methods forwarded leftover wire fields with a direct assignment that
  bypassed the extra-field path and fataled against the typed slot. A spec-valid
  `notifications/cancelled` such as `{requestId, _meta: {...}}` now parses, as do
  `notifications/progress`, `notifications/message`, `notifications/tasks/status`,
  and `notifications/resources/updated`, which shared the same defect. `_meta` is
  now normalized into a `Meta` object via the new
  `NotificationParams::applyWireFields()` helper.
- The client now declares the `roots` capability during initialization when a
  roots handler is registered via `onListRoots()`, satisfying the MCP spec MUST
  for clients that support roots. Previously there was no high-level way to
  advertise `roots`, so a spec-compliant server never called `roots/list` and
  any `notifications/roots/list_changed` the client sent used an un-negotiated
  capability.
- `ClientSession` now responds to server-initiated `ping` requests with an
  empty result, satisfying the MCP spec MUST. Previously the request was
  dispatched into the session but no handler replied, so a server probing
  client liveness would see the ping time out and could consider the
  connection stale. A new public `RequestResponder::hasResponded()` accessor
  lets user-registered `onRequest` handlers coexist with the built-in
  responder.

## [1.7.1]

### Added

- Repository-level governance and process documentation: CONTRIBUTING,
  CODE_OF_CONDUCT, SECURITY, SUPPORT, GOVERNANCE, ROADMAP, CHANGELOG.
- Issue and pull request templates under `.github/`.
- Expanded documentation under `docs/` (compatibility, dependency policy,
  labels, testing) and `conformance/README.md`.
- GitHub action for official MCP conformance tests
- GitHub action for unit tests and PHPStan
- PHP 8.1 compatibility fix for SSE emitter test

### Removed

- Removed Chinese language README, it was a community contribution and we
  cannot currently verify an updated translation would be accurate.

## [1.7.0]

Targets MCP spec revision `2025-11-25`. Expands client-side auth back-compat,
adds server-initiated sampling, and broadens SSE streaming support on both sides
of the wire. Conformance: 100% of applicable required tests pass against suite
`v0.1.16`; three known failures remain in optional MCP Extensions (see
[`conformance/conformance-baseline.yml`](conformance/conformance-baseline.yml)).

### Added

- Full MCP conformance test suite integration (`composer conformance`,
  `composer conformance-server`, `composer conformance-client`) with a
  pinned tool version in `package.json`.
- Server-initiated sampling.
- Server-side SSE elicitation and progress notifications.
- Client-side elicitation handler groundwork and elicitation defaults.
- Client-side SSE retry and improved cURL failure tolerance.
- Client-side back-compat for the `2025-03-26` auth flow.
- Optional `inputSchema` parameter for tool registration.
- DNS rebinding protection on the HTTP server transport.
- Alignment with SEP-1699 on the client.
- Preservation of tool-call metadata to support future progress-notification
  plumbing.
- Additional client-side auth conformance coverage.

### Changed

- HTTP server transport streaming and protocol handling refined.
- Webclient example updated to align with the current SDK client, and the
  automatic GET SSE stream is now disabled by default inside the webclient
  wrapper for broader shared-hosting compatibility.

### Fixed

- HTTP client SSE behavior on PHP 8.1.
- Cross-feature result carry-over across HTTP suspend/resume transitions.
- Unit test output noise.
- Correct sample field name for the `elicitation-sep1330-enums` scenario.
- SSE streaming parser bug.
- HTTP origin allowlist normalization.

## [1.6.1]

### Fixed

- `ElicitationCreateResult` return type corrected.

### Changed

- README and `AGENTS.md` refreshed.

## [1.6.0]

### Added

- Elicitation support
- Ability to set the MCP server version explicitly
  ([#58](https://github.com/logiscape/mcp-sdk-php/pull/58), contributed by
  [@vjik](https://github.com/vjik)).

### Changed

- Server developer documentation updated to cover elicitation.
- Removed the unnecessary `enableElicitation` gate method.

### Fixed

- Experimental task features no longer interfere with elicitation requests.
- `_elicitationResults` is now correctly forwarded on HTTP resume.

### Removed

- Dead code cleanup.

## Earlier versions

Release notes for `v1.0.x` through `v1.5.x` were not captured in this file.
Those tags remain in the repository and their commit history is authoritative.
See [https://github.com/logiscape/mcp-sdk-php/tags](https://github.com/logiscape/mcp-sdk-php/tags).
