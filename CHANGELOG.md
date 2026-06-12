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
    issuers, fresh registration at the new AS, and an in-process
    migration marker — one that survives retries within the client
    instance without retaining stale bearer tokens — when pre-registered
    credentials block automatic migration), SEP-2207 `offline_access` gating on
    `scopes_supported` — plus the `client_credentials` grant
    (private_key_jwt with ES256/RS256 assertions including DER→raw
    signature conversion, and client_secret_basic) and the SEP-990
    cross-app-access flow (RFC 8693 token exchange + RFC 7523
    jwt-bearer).
  - New typed exceptions `Mcp\Shared\UnknownMethodException` and
    `Mcp\Client\Transport\ReadTimeoutException` replace exception-message
    string matching in unknown-method handling and client read-timeout
    classification (messages unchanged for backward compatibility).

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

### Fixed

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
  - The SEP-2352 migration block for pre-registered credentials now
    survives retries within the client instance: the old issuer is
    retained in an in-process migration marker, so rejected bearer tokens
    are discarded immediately while a retried 401 still cannot skip the
    guard and present old credentials to the new authorization server.
    (The marker is per-instance; durable cross-process binding requires
    issuer-bound pre-registered credentials, a planned follow-up.)
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
