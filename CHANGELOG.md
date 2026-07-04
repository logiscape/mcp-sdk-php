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

### Added

- Add pre-alpha v2 notice to README
- `docs/v2-development-plan.md` — the main working plan for v2 development:
  ordered workstreams with dependencies and completion criteria, the
  research → implement → human review → human commit milestone process, and
  release gates G1–G4. Referenced from ROADMAP.md and AGENTS.md.
- Dual-track conformance testing for the `2026-07-28` RC window, mirroring
  the official TypeScript SDK v2 setup: the stable conformance tool pin
  (`0.1.16`) remains the legacy regression gate, and a second pin on the
  upstream RC-validation line (`0.2.0-alpha.2`, npm alias
  `conformance-draft`) runs the `2026-07-28` draft-spec suite via
  `composer conformance-draft` against its own
  `conformance/conformance-draft-baseline.yml` (initial baseline populated
  from a real run, each entry annotated with the v2 workstream that will
  close it). Includes a CI draft job and draft-track rules in the
  development plan (WS7) and `conformance/README.md`. The tracks converge
  to a single pin when the stable `0.2.0` tool ships.
- **v2 WS1 — stateless foundation.** `2026-07-28` added to the supported
  protocol versions with feature gating for the new behaviors
  (`stateless_lifecycle`, `caching_hints`,
  `resource_not_found_invalid_params`, `json_schema_2020_12`).
- `server/discover` (SEP-2575) answered on stdio and HTTP without any prior
  handshake: validates the required per-request `_meta` envelope
  (protocol version / client info / client capabilities, keys in the new
  `MetaKeys` class; malformed envelopes get `-32602`), answers an
  unsupported version with `-32004` plus `data.supported`/`data.requested`,
  and advertises capabilities wire-identical to the legacy `initialize`
  result. On HTTP the request is fully sessionless (SEP-2567): any
  `Mcp-Session-Id` is ignored, none is minted or echoed, the ephemeral
  processing context is deleted rather than persisted, responses are plain
  JSON (never SSE-framed), and the SEP-2575 error codes map to HTTP 400.
  Client side: `ClientSession::discover()` sends the enveloped probe.
- SEP-2549 caching hints: required `ttlMs`/`cacheScope` fields on the six
  cacheable result types (four list results, `resources/read`,
  `server/discover`) via the new `CacheableResult` interface/trait, plus
  the `resultType` discriminator on every result — stamped with
  conservative defaults for `2026-07-28` clients and stripped for legacy
  clients in `adaptResponseForClient()`.
- SEP-2106: full JSON Schema 2020-12 accepted in tool schemas (composition,
  conditionals, `$ref` passed through without dereferencing), and
  `structuredContent` may be any JSON value — including an explicit
  `null` — when an `outputSchema` is declared; scalars, explicit `null`,
  and non-empty list arrays are stripped for legacy clients (an empty PHP
  array is preserved, as the established way to express an empty object).
- SEP-414: W3C Trace Context pass-through — the reserved bare
  `traceparent`/`tracestate`/`baggage` keys in `_meta` with a new
  `TraceContext` accessor class, no OpenTelemetry dependency.
- **v2 WS2 — client/server negotiation.** Dual-era interoperability per
  SEP-2575's detection rules. Server side: per-request era detection — a
  request carrying the modern `_meta` envelope (or a modern
  `MCP-Protocol-Version` header) is served statelessly under `2026-07-28`
  semantics on a fresh ephemeral context (`Mcp-Session-Id` ignored, never
  echoed), adopting protocol version, client info, and client capabilities
  from that request's envelope; an `initialize` without modern metadata
  selects legacy semantics unchanged. Removed methods (`initialize`,
  `ping`, `logging/setLevel`, `resources/subscribe`/`unsubscribe`) and
  unknown methods answer `-32601` with HTTP 404; envelope/version/
  capability errors map to HTTP 400 via a structured status hint carried
  on the response message (replacing the WS1 body-re-decoding shim);
  missing client capabilities now fail modern requests with `-32003` and
  `data.requiredCapabilities` instead of degrading silently. The RC-window
  `DRAFT-2026-v1` identifier is accepted as an alias for `2026-07-28` on
  the per-request path only (advertised in `supportedVersions` and
  `-32004 data.supported`; never negotiable via `initialize`; retires at
  the WS7 tool convergence). Client side:
  `ClientSession::negotiate()`/`Client::connect()` probe
  `server/discover` first and fall back to the legacy handshake per the
  spec's rules — retry with an advertised version on `-32004` (never
  falling back), no fallback on the other recognized modern errors, and
  fallback on any other error or probe timeout; modern sessions stamp the
  `_meta` envelope on every request with the `MCP-Protocol-Version` header
  mirrored from it. `connect()` gains `protocolMode`
  (`auto`/`legacy`/`modern`) and `probeTimeout` options. The adopted era
  is request-scoped (no modern state leaking into later bare stdio
  requests, no clobbering of a legacy session's negotiated state);
  envelope/version validation precedes method routing for unknown and
  removed methods too; the modern HTTP response is always the single JSON
  object the Streamable HTTP spec requires, with the SEP-2575 status
  surviving an interleaved notification (legacy multi-message behavior
  unchanged); and HTTP discover probes are bounded by the probe timeout,
  with cURL timeouts surfacing as the typed `HttpRequestTimeoutException`
  that negotiation treats as a silent legacy server. `-32003`'s
  `data.requiredCapabilities` is emitted as the ClientCapabilities object
  the SEP-2575 final text and draft schema specify (e.g.
  `{"sampling": {}}`); the pinned conformance tool's divergent
  string-array assertion is documented in the draft baseline as an
  upstream tool bug. `McpServer`'s tools/call wrapper now propagates
  SDK-raised `McpError`s as JSON-RPC protocol errors (matching the
  existing `McpServerException` handling) instead of converting them into
  `isError` tool results, so the SEP-2575 missing-capability error
  reaches the wire with HTTP 400 — an API-visible change for tool
  handlers that deliberately throw `McpError`.
- **v2 WS3 — transport changes.** The HTTP-layer and streaming surface of
  the `2026-07-28` stateless revision, plus the authorization-hardening
  SEPs.
  - SEP-2243 request-metadata headers. Servers validate `Mcp-Method` (all
    modern requests and notifications) and `Mcp-Name` (`tools/call` and
    `prompts/get` → `params.name`; `resources/read` → `params.uri`;
    `tasks/*` → `params.taskId`): a missing or body-mismatched header, or
    an `MCP-Protocol-Version` header that disagrees with the `_meta`
    envelope, is rejected HTTP 400 + `-32001 HeaderMismatch`; `-32004`
    fires only when header and `_meta` agree on an unsupported version.
    Header names compare case-insensitively, values case-sensitively
    after RFC 9110 OWS trimming. Designated tool parameters
    (`x-mcp-header` annotations — valid at any nesting depth on
    string/integer/boolean properties; `number` is not permitted, and
    integer values must be finite, integral, and within ±(2^53−1), even
    when large JSON integers decode as floats) are validated server-side
    against the request's `Mcp-Param-*` headers with strict
    base64-sentinel decoding, and mirrored client-side from cached
    `tools/list` schemas: null arguments omit the header, unsafe values
    get the lowercase `=?base64?…?=` wrapper, and out-of-range values
    fail before any wire traffic. HTTP clients exclude tools whose
    annotations violate the constraints. Shared rules live in
    `Mcp\Shared\McpHeaders`; stdio is exempt (headers are HTTP-only).
  - SEP-2575 streams. Handler-emitted notifications ride a request-scoped
    buffered SSE response on the modern path (error responses stay plain
    JSON so the 400/404 statuses hold), and `subscriptions/listen` is
    implemented on HTTP and stdio: the
    `notifications/subscriptions/acknowledged` ack is the stream's first
    message and echoes only the filter subset the server can actually
    deliver, every frame carries
    `_meta["io.modelcontextprotocol/subscriptionId"]` (the stringified
    listen request id), the `SubscriptionFilter` is strictly contained
    (no un-opted-in notification types; `resourceSubscriptions` is
    honored where resource-update delivery is possible, independent of
    the legacy `resources.subscribe` capability), and the listen request
    never receives a JSON-RPC response. On stdio,
    `notifications/cancelled` referencing the listen request id ends the
    subscription. Event fan-out crosses processes through the new
    `SubscriptionBusInterface` (`FileSubscriptionBus` for typical PHP
    hosting, `InMemorySubscriptionBus` for tests and long-running
    runtimes) with `McpServer` publish helpers
    (`publishToolsListChanged()` etc.); a server without a configured
    bus answers `subscriptions/listen` with `-32601` rather than
    acknowledging subscriptions it cannot serve. No SSE event ids or
    `Last-Event-ID` resumption exist on the modern path; legacy streams
    keep both.
  - SEP-2322 multi-round-trip input. `tools/call` and `prompts/get`
    answer `InputRequiredResult` (`resultType: "input_required"`,
    `inputRequests`, signed `requestState`) instead of server-initiated
    sampling/elicitation/roots requests on the modern path, via ephemeral
    re-execution: `ElicitationContext`/`SamplingContext` resolve from the
    round's `inputResponses` or suspend (new optional `inputKey`
    parameter), and the new `InputContext` batches mixed elicitation +
    sampling + roots requests into a single round. `RequestStateCodec`
    HMAC-signs `requestState` (attacker-controlled input per spec) and
    binds it to the authenticated principal — the token's `sub` claim,
    or a SHA-256 fingerprint of the bearer token when the validated
    claims carry no usable subject — so tampering, expiry, and
    cross-user replay are all rejected with `-32602`; consumed results
    are carried between rounds inside the state, and the
    per-installation file-backed signing secret initializes race-safely
    under an exclusive lock (reclaiming stubs left by crashed writers),
    locks its permissions to 0600 before any secret byte is written,
    refuses symlinks and foreign-owned or other-readable files at the
    predictable default shared-temp path (POSIX), and fails loudly when
    no shared secret can be established. The client side services
    `inputRequests` through
    `onElicit`/`onSampling` (new)/roots handlers and retries with
    key-matched `inputResponses`, verbatim `requestState` echo, fresh
    request ids, a 16-round cap, and absent `resultType` treated as
    `"complete"`. `Client::connect()` gains `protocolMode: 'modern'`
    (skip the probe entirely) and a `protocolVersion` preference; modern
    sessions adopt an advertised version and retry once on `-32004`.
  - Authorization hardening (client): SEP-2468 `iss` validation per RFC
    9207 (non-normalized byte comparison; error params never acted on
    when `iss` fails; new `AuthorizationCallbackResult` with
    backward compatibility for string-returning handlers), SEP-837
    `application_type` on dynamic registration (derived native/web),
    SEP-2352 authorization-server migration (PRM re-fetched on 401 and on
    403 `insufficient_scope`, tokens/credentials never reused across
    issuers, fresh registration at the new AS, and issuer-bound
    pre-registered credentials per the spec's Authorization Server
    Binding rule: the new `ClientCredentials::$issuer` names the AS the
    credentials were registered with and is enforced before every grant
    flow — durably across PHP processes; it is required by default
    (unbound pre-registered credentials are rejected with a typed
    error), with the explicit
    `OAuthConfiguration::$allowUnboundClientCredentials` legacy opt-in
    restoring the published-spec behavior of pinning to the first
    validated issuer for the lifetime of the OAuthClient instance, and
    bearer tokens bound to a previous issuer
    are always deleted when migration is detected), SEP-2207 `offline_access` gating on
    `scopes_supported` — plus the `client_credentials` grant
    (private_key_jwt with ES256/RS256 assertions including DER→raw
    signature conversion, and client_secret_basic) and the SEP-990
    cross-app-access flow (RFC 8693 token exchange + RFC 7523
    jwt-bearer).
  - New typed exceptions `Mcp\Shared\UnknownMethodException` and
    `Mcp\Client\Transport\ReadTimeoutException` replace exception-message
    string matching in unknown-method handling and client read-timeout
    classification (messages unchanged for backward compatibility).
- **v2 WS4 — Tasks extension (SEP-2663).** A clean, breaking redesign of the
  pre-release experimental Tasks surface (no deprecation shims) to the
  `2026-07-28` stateless model, declared through the SEP-2133 extensions
  framework.
  - Methods reduced to `tasks/get` / `tasks/update` / `tasks/cancel`;
    `tasks/list` and `tasks/result` are removed and now answer `-32601`
    (the completed result is inlined in the `tasks/get` response). A
    `tools/call` the server augments as a task returns a flat
    `CreateTaskResult` (`Result & Task`, discriminated by
    `resultType: "task"` — not a nested object or `_meta` key); `tasks/get`
    returns a flat `DetailedTask` (`resultType: "complete"`) that inlines
    `result` (completed), `error` (failed), or `inputRequests`
    (input_required) by status; `tasks/update` and `tasks/cancel` return
    empty `{ "resultType": "complete" }` acks. Task fields are renamed
    `ttl` → `ttlMs` (always emitted, `null` = unlimited) and
    `pollInterval` → `pollIntervalMs`; the `io.modelcontextprotocol/
    related-task` `_meta` key is dropped.
  - Declared via the new SEP-2133 `extensions` capability map (new
    `extensions` field on `ServerCapabilities`/`ClientCapabilities`,
    `Mcp\Types\ExtensionIds::TASKS`): advertised in `server/discover` and
    declared per-request in the `_meta` clientCapabilities envelope
    (`ClientSession::declareExtension()`). The v1 `tasks` capability slot
    and `TaskCapability` type are removed. A malformed (non-object)
    extension value — a scalar or a JSON array — is ignored, so it can
    never unlock a feature.
  - Server: `McpServer::enableTasks()` registers the three task methods and
    declares the extension; a tool opts into task augmentation via
    `tool(..., taskSupport: …)` with the new `Mcp\Server\TaskSupport`
    (`FORBIDDEN` default / `OPTIONAL` / `REQUIRED`). The file-based
    `TaskManager` is reworked to the SEP-2663 state machine (working ⇄
    input_required → terminal, `ttlMs` expiry, idempotent cancel, in-task
    input persisted with a signed `requestState`). Every `tasks/*` method
    requires the client to have declared the extension (rejected `-32021`
    regardless of era); a `REQUIRED` tool called by an undeclared modern
    client is rejected `-32021` with
    `data.requiredCapabilities.extensions["io.modelcontextprotocol/tasks"]`,
    while an `OPTIONAL` tool degrades to a synchronous result. Execution is
    synchronous-capture for shared-hosting compatibility (the tool body runs
    within the creating request and the outcome is stored); genuine
    async/working tasks are driven by the application via
    `McpServer::getTaskManager()`. In-task input reuses the SEP-2322
    machinery: a task tool that elicits parks as `input_required`, surfaces
    its `inputRequests` via `tasks/get`, and resumes on `tasks/update`.
  - Client: `ClientSession::getTask()` / `updateTask()` / `cancelTask()`
    (and `Client` wrappers); `listTasks()` and `getTaskResult()` are removed.
  - Conformance: `everything-server.php` gained the Tasks fixtures
    (`enableTasks()` plus `greet`, `slow_compute`, `failing_job`,
    `protocol_error_job`, `confirm_delete`, `multi_input`,
    `test_tool_with_task`), and the draft `server-draft`/`draft` gate runs
    the tool's ten `pending`-suite SEP-2663 scenarios explicitly
    (`DRAFT_SERVER_EXTRA_SCENARIOS`): eight pass,
    `tasks-status-notifications` is skipped by the tool (pending its own
    `subscriptions/listen` rewrite), and `tasks-mrtr-composition` is an
    expected baseline failure (its pre-creation-MRTR sequence is mutually
    exclusive with the SDK's in-task-input model — both spec-permitted).
- **v2 WS5 — MCP Apps extension (SEP-1865).** Server-side support for the
  MCP Apps extension (ext-apps stable revision `2026-01-26`), where the UI
  renders host-side in a sandboxed iframe and the SDK's role is capability
  declaration plus `_meta` plumbing — the extension adds no new RPC method.
  - New `McpServer::ui(tool, uri, name, html, …)` helper attaches a `ui://`
    template resource to a registered tool in one call: it registers the
    resource with MIME `text/html;profile=mcp-app`
    (`McpServer::UI_MIME_TYPE`), links the tool through `_meta.ui.resourceUri`
    (dual-writing the deprecated flat `_meta["ui/resourceUri"]` key for host
    back-compat, mirroring the reference ext-apps server SDK), and declares
    the extension. Optional host hints: `visibility` (`model`/`app`), `csp`,
    `permissions` (camera/microphone/geolocation/clipboardWrite, emitted as
    empty objects), `domain`, and `prefersBorder`. The HTML may be a string
    or a callback invoked lazily at read time. Resource-level `_meta.ui` is
    emitted on the `resources/read` content (where the stable revision reads
    it) and mirrored on the listed resource (draft dual-location).
  - Declared via the SEP-2133 extensions map
    (`capabilities.extensions["io.modelcontextprotocol/ui"] = { mimeTypes:
    ["text/html;profile=mcp-app"] }`, `Mcp\Types\ExtensionIds::UI`),
    advertised in `initialize` and `server/discover` through the new generic
    `Server::declareExtension()`.
  - Graceful degradation is automatic: a host that cannot render the UI
    ignores `_meta.ui` and the tool still returns its ordinary `content`;
    UI-originated interactions arrive as ordinary `tools/call` requests.
  - Conformance: the pinned draft tool publishes no Apps scenarios, so the
    extension is covered by unit tests (`tests/Server/AppsExtensionTest.php`)
    and a runnable example (`examples/apps_server/`); both conformance tracks
    remain regression-free.
  - Fix: resource-content `_meta` now round-trips. On serialize,
    `ResourceContents::jsonSerialize()` no longer leaks the trait's internal
    `extraFields` storage as a literal wire key; on parse,
    `TextResourceContents::fromArray()` / `BlobResourceContents::fromArray()`
    now preserve extra fields instead of discarding them, so a host/client
    retains the SEP-1865 `_meta.ui` (CSP, permissions, domain, border hints)
    on `resources/read` content. `ExtraFieldsTrait` gains
    `setExtraField()`/`getExtraField()` accessors.
- **Post-RC spec drift round (2026-07-01)** — three normative draft-spec
  changes merged upstream after the RC lock, absorbed per the plan's
  "official text wins" rule (details in `docs/v2-development-plan.md`, WS3):
  - `SubscriptionsListenResult` (spec PR #2953): when the server ends a
    subscription on its own initiative it answers the original
    `subscriptions/listen` request with `{ resultType: "complete", _meta:
    { "io.modelcontextprotocol/subscriptionId": <listen id> } }` before
    closing, so clients can tell a graceful end from an abrupt drop. New
    `Mcp\Types\SubscriptionsListenResult`; the HTTP listen stream emits it
    as the final SSE frame when the lifetime budget elapses (never after a
    detected client disconnect), and stdio answers every active
    subscription at server-initiated session stop (client-cancelled
    subscriptions are never answered). Review follow-up: the
    `subscriptionId` `_meta` value is typed `RequestId` in the schema and
    now preserves the listen id's original JSON-RPC wire type (an integer
    id stays a JSON number) on EVERY frame of the subscription channel —
    acknowledgement, stream notifications, and the graceful-end result —
    replacing the earlier stringified stamping.
  - SEP-2243 base64 sentinel made case-sensitive (spec PR #2937): only the
    exact-lowercase `=?base64?…?=` wrapper is decoded; a non-lowercase
    prefix (e.g. `=?BASE64?`) is treated as a literal header value on both
    the emit and validate sides.
  - SEP-2243 `Mcp-Param-*` emission decoupled from schema TTL (spec PR
    #2972): verified already-conformant (the SDK always built headers from
    the most recently obtained `inputSchema` and never consulted `ttlMs`)
    and pinned with regression tests.
- **v2 WS6 — SEP-2596/SEP-2577 feature-lifecycle deprecation.** The spec's
  deprecated-features registry is mirrored as `Mcp\Shared\FeatureLifecycle`
  (Roots, Sampling, and Logging deprecated at `2026-07-28` by SEP-2577;
  Dynamic Client Registration at `2026-07-28` by spec PR #2858 in favor of
  Client ID Metadata Documents; the `includeContext:
  "thisServer"|"allServers"` sampling values at `2025-11-25` by SEP-2596).
  Per SEP-2596 there is NO wire-level deprecation signal and wire behavior
  is unchanged — deprecated features keep working for at least the
  twelve-month window. The SDK's obligations are implemented as:
  - Runtime warnings (SEP-2596/2577 SHOULD): one PSR-3 warning per feature
    per session when a deprecated feature is exercised on a session whose
    negotiated revision deprecates it (`EmitsDeprecationWarnings` trait on
    both sessions). Warned exercise points: server `sendLogMessage()`, the
    `io.modelcontextprotocol/logLevel` `_meta` opt-in,
    `sendSamplingRequest()`, `SamplingContext::createMessage()`,
    `InputContext::wantSample()/wantRoots()`, and the deprecated
    `includeContext` values; client `setLoggingLevel()`,
    `sendRootsListChanged()`, and MRTR servicing of sampling/roots input
    requests; auth `DynamicClientRegistration::register()`. Sessions on
    revisions where a feature is still Active stay silent.
  - Language-native API deprecation marking: `@deprecated` docblocks on the
    20 roots/sampling/logging `Types/` classes (including the sampling
    `ToolUseContent`/`ToolResultContent` content types), the deprecated
    capability slots themselves (`ClientCapabilities::$roots`/`$sampling`,
    `ServerCapabilities::$logging` — matching the schema's member-level
    markers), the `MetaKeys::LOG_LEVEL` constant, the feature APIs
    (including method overrides, which do not inherit PHPDoc), and the
    value-level `includeContext` deprecation (2025-11-25, SEP-2596) on
    `CreateMessageRequest::$includeContext`, the `RequestParams`
    `@property` line, and `SamplingContext::createMessage()`, mirroring
    the draft schema's annotation wording.
  - `MetaKeys::LOG_LEVEL` re-verified against the draft schema as official
    and deprecated-at-birth (SEP-2577).
- **v2 WS6 — cross-revision matrix, mixed-era hardening, API audit.**
  - `tests/Server/CrossRevisionMatrixTest.php`: every supported revision
    (`2024-11-05` through `2026-07-28`) exercised against the real
    `McpServer` surface over the HTTP runner — handshake negotiation,
    session-header contract, real `Last-Event-ID` SSE resumption
    round-trips on every legacy revision (and none on the modern path),
    era-correct SEP-2164 error codes, and SEP-2549 result
    stamping/stripping.
  - Fix: `ServerSession::adaptResponseForClient()` adapts a shallow clone
    instead of mutating the handler's `Result` in place — a handler-cached
    result served to a legacy client no longer loses its own
    `ttlMs`/`cacheScope` for subsequent modern clients, and modern
    stamping no longer leaks SDK defaults into handler state (covered by a
    cross-era cached-result test on one runner).
  - Fix: `initialize` requests carrying `_meta` (e.g. SEP-414 trace
    context) no longer crash typed request construction with a TypeError —
    the wire `_meta` array is converted like every other request family.
  - `docs/api-audit-v2.md`: the v1→v2 PHP API audit — breaking changes
    (typed `McpError` on HTTP, `McpError` propagation from tool handlers,
    `callTool()` return union, removed experimental Tasks surface,
    mandatory issuer binding, `x-mcp-header` filtering, and more),
    behavioral changes, and the additive v2 surface — feeding the WS10
    migration guide.

### Changed

- Roadmap: `v2` confirmed as the release vehicle for `2026-07-28` day-one
  support (aligned with the official TypeScript and Python SDK v2 timelines),
  and the MCP Apps extension (SEP-1865) promoted from long-term/conditional
  to a committed v2 release feature.
- Update README to credit AI models used for v2 development.
- Deliberate era split for version negotiation: the new
  `Version::LATEST_LEGACY_PROTOCOL_VERSION` (`2025-11-25`) caps what the
  `initialize` handshake can negotiate — the handshake itself is removed in
  `2026-07-28` (SEP-2575), so the stateless revision is only selectable via
  the per-request `_meta` envelope. The client's `initialize()` now
  requests the latest legacy revision.
- SEP-2164: a missing resource is reported as `-32602` (Invalid params) to
  `2026-07-28` clients while legacy revisions keep `-32002`; both shapes
  now carry the requested `uri` in `error.data`, and a missing resource is
  always an error, never an empty `contents` array.
- Draft conformance baseline re-curated for the WS1 milestone:
  `json-schema-ref-no-deref` passes and left the baseline;
  `sep-2164-resource-not-found` and `caching` re-attributed to WS2 with a
  documented shared root cause (the draft tool's per-request stateless
  lifecycle needs WS2's era detection). v2 development plan updated with
  the WS1 status and spec-drift notes in the same change set.
- HTTP client error handling brought to parity with stdio (WS2): JSON-RPC
  error responses — including those delivered with the modern HTTP 400/404
  statuses — now surface to callers as typed `Mcp\Shared\McpError` with
  code and data intact, where the HTTP transport previously threw an opaque
  `RuntimeException("Critical MCP error: …")`. A configured client
  `readTimeout` is now also enforced against a peer that sends nothing at
  all (previously the timeout could only fire between messages). Both are
  wire-compatible behavior fixes; the `McpError` change is API-visible to
  v1 code that caught `RuntimeException` from HTTP tool calls.
- Draft conformance baseline re-curated for the WS2 milestone:
  `sep-2164-resource-not-found` (3/3) and `caching` (7/7) pass and left the
  baseline; `server-stateless` passes 17/19, its two failing checks being
  the SEP-2243 header/`_meta` mismatch `-32001` check and the upstream
  tool's string-array `requiredCapabilities` assertion (a documented
  upstream tool bug; the SDK keeps the schema's object shape);
  `http-custom-header-server-validation` removed as inactive (its checks
  only engage once an `x-mcp-header` tool exists). The stable track stays
  regression-free (291 passed, up 4 from the typed-error fix).
- `Server::getCapabilities()` derives the `resources.subscribe`
  capability from actual `resources/subscribe` handler registration
  instead of hardcoding `false`, so the advertisement matches what the
  server really serves.
- Conformance updated for the WS3 milestone: **both stable baselines are
  now empty** — 100% of stable scenarios pass (40 server + 319 client) —
  and the draft baselines are down to two documented upstream-tool
  entries: `sep-2575-server-rejects-undeclared-capability` (the pinned
  tool asserts a string-array `requiredCapabilities` against the draft
  schema's ClientCapabilities object) and `http-custom-headers` (the
  pinned tool still requires mirroring `number`-typed designated
  parameters, which the final SEP-2243 text prohibits).
  `run-conformance.php` starts the fixture server with
  `PHP_CLI_SERVER_WORKERS` on POSIX so concurrent-stream scenarios
  exercise real parallelism; the everything-server gained the WS3 fixture
  tools (header params, listen triggers, per-request-logLevel logging,
  and the SEP-2322 input-required suite) and the everything-client gained
  the matching scenario handlers. `conformance/README.md` and
  `ROADMAP.md` reflect the emptied stable baseline.
- Draft error codes renumbered to the draft allocation policy in spec PR
  modelcontextprotocol#2907: `HeaderMismatch` `-32001` → `-32020`,
  `MissingRequiredClientCapability` `-32003` → `-32021`, and
  `UnsupportedProtocolVersion` `-32004` → `-32022` (constants in
  `Mcp\Shared\McpError`; both the server emission and the client
  negotiation paths route through the constants). These are `2026-07-28`
  draft-only codes, so legacy (`2025-11-25` and earlier) behavior is
  unchanged. Verified against the conformance draft track (the server
  header-mismatch and unsupported-version checks pass against the
  renumbered codes).
- Draft conformance pin bumped `0.2.0-alpha.2` → `0.2.0-alpha.7` (latest
  published alpha; `alpha.5` is the minimum carrying the #2907 renumber),
  re-curating `conformance/conformance-draft-baseline.yml` from a real run.
  Server track: `server-stateless` now 26/27 (up from 22/23 — alpha.4's
  #343 added the HTTP-400-on-invalid-`_meta` and `data.requested`-echo
  checks, both already satisfied), its single remaining failure the
  documented upstream string-array-`requiredCapabilities` tool bug (now at
  the renumbered `-32021`, and now contradicting the tool's own draft
  schema and Tasks scenario). Client track gains two NEW upstream-drift
  expected failures, both spec-correct on the SDK side and unavoidable on
  any renumber-bearing alpha: `json-schema-ref-no-deref` (alpha.5 #347 made
  the mock answer `server/discover` advertising `2026-07-28` while its
  TS-SDK stateful transport rejects that version) and `request-metadata`
  (alpha.3 #331 retired the `DRAFT-2026-v1` retry identifier, so the
  first-rejection retry test now advertises the same version the client
  just tried, which the SDK's infinite-loop guard declines to re-attempt).
  `http-custom-headers` and `auth/pre-registration` remain documented
  upstream entries; the former clears once the pin reaches `0.2.0-alpha.8`
  (upstream PR #371, merged after the alpha.7 tag).
- WS4 v1→v2 API-surface changes (for the migration guide): the pre-release
  Tasks API is replaced, not deprecated. `ClientSession::callTool()` now
  returns `CallToolResult|CreateTaskResult` (it surfaces a task handle when
  the server augments the call); `ClientSession::listTasks()` and
  `getTaskResult()` are removed; the `Task` fields `ttl`/`pollInterval` are
  renamed `ttlMs`/`pollIntervalMs`; the `tasks` capability slot and the
  `TaskCapability`, `TaskListRequest`/`TaskListResult`,
  `TaskResultRequest`, and `TaskStatusNotification` types are removed; and
  `ElicitationContext::form()`/`url()`/`requiresForm()` no longer take the
  (previously stubbed) `$task` parameter.

### Fixed

- `ClientSession::negotiate()` now performs the spec's select-and-continue
  corrective retry after `-32022 UnsupportedProtocolVersion` exactly once,
  with the advertised version permitted to equal the one just rejected (a
  transient-rejection shape a server may legitimately produce); a second
  `-32022` propagates instead of looping. Previously the retry guard
  excluded every already-attempted version, which is stricter than the
  draft spec's retry rule (no already-attempted exclusion exists in the
  text) and stricter than the reference TypeScript and Python clients
  (both re-send the identical advertised version once, then hard-stop).
  `pickAdvertisedModernVersion()` dropped its attempted-versions
  parameter; `sendRequest()`'s adopt-and-retry keeps its structural
  retry-once semantics and now also recovers from a same-version transient
  rejection. Clears the `request-metadata` scenario from the draft
  conformance baseline.
- The stdio server now detects EOF on stdin and shuts down cleanly instead
  of busy-waiting forever (issue #61). Per the MCP lifecycle, a client
  initiates stdio shutdown by closing the server's stdin and *waiting for
  the process to exit*; previously `StdioServerTransport::readMessage()`
  treated EOF as "no data yet", leaving the process spinning in a 10 ms
  sleep loop until the client escalated to `SIGTERM` — which never arrives
  in some Docker setups, orphaning the process. `readMessage()` now throws
  the new `Mcp\Server\Transport\TransportClosedException` at EOF, which
  `ServerSession` treats as a clean shutdown signal (the message loop exits
  and the session stops). Handler-wrapping catch blocks in `ServerSession`
  and `McpServer` rethrow it so a tool blocked on a stdio
  elicitation/sampling round-trip cannot swallow the shutdown into an
  error response aimed at a dead stream.
- `HttpServerSession::toArray()` deep-normalizes `clientParams`, so
  declared client capabilities (e.g. a bare `elicitation: {}`) survive
  cross-request restoration on session stores that keep PHP values in
  memory — previously `fromArray()`'s array guards silently dropped them.
- WS3 post-commit review (four findings, all with regression tests):
  - `HttpServerRunner` no longer leaks the ephemeral modern session into a
    later legacy request on a reused runner: the modern session is
    installed only for the duration of its own dispatch and the previous
    session is restored on both the runner and `Server` facade on every
    exit path. Previously a legacy `initialize` following a modern request
    on one runner instance
    (long-running runtimes, embedding, tests) was served by the stale
    modern-declared session — rejected `-32602` for lacking the `_meta`
    envelope — which also carried the prior request's headers and
    authenticated principal.
  - The SEP-2352 migration block for pre-registered credentials is no
    longer single-shot: rejected bearer tokens are discarded immediately,
    yet a retried 401 still cannot skip the guard and present old
    credentials to the new authorization server. Implemented via the
    spec's Authorization Server Binding model rather than mutable
    migration state: `ClientCredentials::$issuer` (new) binds the
    credentials to the AS they were registered with, enforced in
    `getClientCredentials()` after issuer validation and before every
    grant flow — durable across PHP processes, and self-healing once the
    operator configures credentials bound to the new issuer. Unbound
    (legacy) credentials are pinned to the first validated issuer —
    from first use or from the stored tokens' issuer when a migration is
    detected — for the lifetime of the OAuthClient instance, and the pin
    now logs a warning recommending `ClientCredentials::$issuer` since an
    unbound pin cannot protect a fresh process (e.g. each PHP-FPM
    request). A follow-up review round tightened the binding: issuer
    binding comparisons are exact code-point equality per RFC 8414 §3.3
    (no scheme/host case folding, default-port or trailing-slash
    normalization — those remain only in token-based migration
    *detection*, where normalization cannot grant access), and
    multi-AS protected-resource metadata is now resolved in favor of the
    bound (or pinned) issuer when it appears anywhere in
    `authorization_servers` (RFC 9728 §7.6 assigns AS selection to the
    client) instead of always selecting the first entry and then
    rejecting the connection. The webclient reference now collects the
    issuer alongside pre-registered credentials and persists it
    through the callback exchange. The follow-up also confirmed the
    remaining unbound-credentials exposure was upstream drift (the
    pinned alpha conformance tool supplies pre-registered credentials
    without the issuer context the draft's binding rule requires) and
    aligned the SDK with the spec: **issuer binding is now mandatory by
    default** — pre-registered credentials without
    `ClientCredentials::$issuer` are rejected before any authorization
    or token request with a typed
    `REASON_UNBOUND_CLIENT_CREDENTIALS` error — and the per-process
    first-use pinning survives only behind the new explicit
    `OAuthConfiguration::$allowUnboundClientCredentials` legacy-compat
    flag (published 2025-11-25 behavior). The drift is recorded in
    `docs/v2-development-plan.md` (WS3) with the pinned tool version and
    upstream commit, the draft-track conformance client now runs the
    strict default (`auth/pre-registration` baselined with its root
    cause), and the stable-track client opts into the legacy flag. The
    webclient reference is strict by default to match: its connection
    form requires the issuer when a Client ID is supplied and gates the
    legacy unbound mode behind an explicit, clearly-warned "Allow unbound
    credentials (legacy)" checkbox (it had previously hard-coded the flag
    on, which silently reopened the cross-process exposure on PHP-FPM).
  - The SEP-2352 migration guard also runs on the 403
    `insufficient_scope` path (PRM cache bypassed, issuer change
    detected) — previously only the 401 path checked, so a step-up flow
    could carry credentials to a new issuer unchecked.
  - `RequestStateCodec::withFileSecret()` hardened for multi-tenant
    hosts: see the SEP-2322 entry above (flock-based initialization with
    stub reclamation, verified 0600 before write, path/handle identity plus
    symlink/ownership/permission refusal at the default path). Previously a
    co-tenant could pre-plant a known secret at the predictable shared-temp
    path, the secret was momentarily world-readable on creation, and a
    crashed writer left a stub that permanently blocked initialization.
- HTTP session detach/resume now restores the modern (`2026-07-28`) era.
  `ClientSession::createRestored()` predates the dual-era negotiation work
  and left a resumed session legacy-era regardless of the negotiated
  version, so its requests carried neither the SEP-2575 `_meta` envelope
  nor the mirrored `MCP-Protocol-Version` header — a modern server's
  per-request era detection then classified them as legacy and correctly
  rejected them with HTTP 400 "Session ID required" (the webclient hit this
  on the first `tools/list` after resuming). `createRestored()` and
  `Client::resumeHttpSession()` accept a new optional trailing
  `$modernWireVersion` parameter (pass the original session's
  `getModernWireVersion()` to preserve the RC-window `DRAFT-2026-v1` alias)
  and auto-detect modern mode when the persisted negotiated version is
  itself a modern revision — sound because `initialize` caps at
  `2025-11-25`, so a modern negotiated version proves the original session
  probed `server/discover`. Resumed modern sessions stamp the per-request
  envelope again, and `resumeHttpSession()` now mirrors `connect()`'s era
  split by skipping the legacy-only steps on the modern path (no
  `MCP-Protocol-Version` force-set on the session manager, no standalone
  GET SSE stream — the sessionless lifecycle has neither). The webclient
  reference persists `modernWireVersion` in its session snapshot and passes
  it through on resume. Legacy resumes are byte-identical to before.

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
