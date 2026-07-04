# v2 Development Plan

This is the **main working plan** for v2 development of `logiscape/mcp-sdk-php`.
[ROADMAP.md](../ROADMAP.md) describes direction and rationale; this document
describes execution — the ordered workstreams, their dependencies and
completion criteria, the milestone process each one follows, and the release
gates that stand between today's pre-alpha `main` and a tagged `v2.0.0`.

**v2 has two defining goals** (see the roadmap for the full rationale):

1. **Day-one support for the `2026-07-28` MCP spec revision** — the stateless
   core — with a clean conformance run inside the ten-week RC-to-final
   validation window (RC locked 2026-05-21; final spec 2026-07-28).
2. **Full support for the MCP Apps extension** (SEP-1865, stable extension
   revision `2026-01-26`) as a v2 release feature.

Both goals are subject to the roadmap's guiding principles, two of which act
as hard constraints on every workstream below: **no conformance shortcuts**
(principle #2) and **cPanel/Apache/PHP shared-hosting compatibility for all
core features** (principle #3). 100% spec conformance and shared-hosting
compatibility are joint release gates, not a trade-off.

## Source-of-truth references

Every milestone's research step starts from official sources, not from this
document. This plan reflects the RC as of June 2026; several SEPs were still
settling when it was written, and the RC may drift before the final spec.
Where this plan and the current official text disagree, **the official text
wins** and this plan gets amended (see "Maintaining this plan").

- [`2026-07-28` Release Candidate announcement](https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/)
- [MCP specification repository](https://github.com/modelcontextprotocol/modelcontextprotocol)
  (SEP texts, schema, spec revisions)
- [MCP Apps extension (ext-apps)](https://github.com/modelcontextprotocol/ext-apps)
  — stable revision `2026-01-26` plus the in-progress draft
- [Official conformance suite](https://github.com/modelcontextprotocol/conformance)
  (stable and draft track versions both pinned in
  [`package.json`](../package.json) — see WS7 for the dual-track strategy)
- Reference SDKs for behavioral comparison: the TypeScript SDK v2 (`main`,
  pre-alpha) and Python SDK v2 (`main`) — useful as a second opinion on
  ambiguous spec text, never as a substitute for it

## The milestone process

Every workstream below is delivered as one or more **milestones**, and every
milestone follows the same four-step flow:

1. **Research (AI coding agent).** Before any code is written, an agent
   gathers the latest comprehensive details for the specific work from the
   official MCP sources above: the current SEP text and schema, conformance
   scenarios that exercise it, and how the reference SDKs interpret any
   ambiguity. The output is a written research summary in the session,
   explicitly flagging anything that has drifted from this plan since it was
   written.
2. **Implementation (AI coding agent).** The agent implements the work,
   including automated tests following the project's testing conventions
   ([docs/testing.md](testing.md), [CONTRIBUTING.md](../CONTRIBUTING.md)).
   Verification means `composer check` (PHPUnit + PHPStan) passes, and
   `composer conformance` (the stable conformance track) is regression-free
   for any milestone touching protocol handling, transports, session
   management, or `McpServer`. Milestones touching `2026-07-28` behavior
   additionally run `composer conformance-draft` (the draft conformance
   track — see WS7) and update
   [`conformance/conformance-draft-baseline.yml`](../conformance/conformance-draft-baseline.yml)
   to reflect honest progress: newly passing draft scenarios leave the
   baseline, still-unimplemented ones stay with root causes. On both tracks,
   honest conformance failures go into the track's baseline file with a root
   cause — never an engineered workaround.
3. **Code review (human-initiated).** Once the agent has verified the
   milestone is achieved and all tests pass, the human user initiates a code
   review of the changes. Findings are addressed (typically by an agent) and
   re-verified the same way.
4. **Approval and commit (human).** When all review findings are resolved,
   the human user approves the milestone and commits the changes.

**All commits to the repository are human-initiated.** AI coding agents do
not run `git commit`, `git push`, or tag releases — they leave verified work
in the working tree for human review and commit. This applies to every
milestone, gate, and hotfix in the v2 process without exception.

## Workstream overview

Workstreams are listed in dependency order. "Gate" is the release gate each
workstream must clear (defined after the workstream details). WS6 and WS7 are
*continuous* — they have per-workstream exit criteria but also run as standing
checks on every other workstream's milestones.

| #    | Workstream                          | Depends on        | Gate |
| ---- | ----------------------------------- | ----------------- | ---- |
| WS1  | Stateless foundation                | —                 | G1   |
| WS2  | Client/server negotiation           | WS1               | G1   |
| WS3  | Transport changes                   | WS1, WS2          | G1   |
| WS4  | Tasks extension                     | WS1–WS3           | G2   |
| WS5  | MCP Apps extension                  | WS1 (parallel OK) | G2   |
| WS6  | Backward compatibility (continuous) | WS1–WS5           | G2   |
| WS7  | Conformance (continuous)            | WS1–WS6           | G3   |
| WS8  | Shared-hosting validation           | WS3, WS5          | G3   |
| WS9  | Examples and webclient              | WS1–WS8           | G4   |
| WS10 | Documentation                       | WS1–WS9           | G4   |

Parallelism notes: WS5 (Apps) builds on the extension-declaration surface from
WS1 but does not need WS3/WS4, so it can proceed in parallel with them. WS6
and WS7 run incrementally from WS1 onward; their table rows mark when they
must be *complete*, not when they start.

---

## WS1 — Stateless foundation

Core protocol-layer changes that everything else builds on: the new protocol
version, the per-request `_meta` envelope, and the type-system changes.

**Scope**

- Add `2026-07-28` to `Version::SUPPORTED_PROTOCOL_VERSIONS` /
  `LATEST_PROTOCOL_VERSION` and extend `FEATURE_VERSIONS`
  (`src/Shared/Version.php`); extend the existing feature-gating seams
  (`ServerSession::clientSupportsFeature()`,
  `ClientSession::supportsFeature()`, `ServerSession::adaptResponseForClient()`).
- **SEP-2575 (types half):** carry protocol version, client info, and
  capabilities in `_meta` on every request — the merged draft schema fixes
  the keys as `io.modelcontextprotocol/protocolVersion` / `clientInfo` /
  `clientCapabilities`, all three **required** on every modern request;
  implement the `server/discover` method reusing `Server::getCapabilities()`.
  Its result is `InitializeResult` minus negotiation plus caching:
  `supportedVersions: string[]`, `capabilities`, `serverInfo`,
  `instructions?`, **plus** the required `resultType` discriminator (a
  SEP-2322 ripple on *every* modern result; `"complete"` outside MRTR) and
  required `ttlMs`/`cacheScope` (the schema makes `DiscoverResult` a sixth
  SEP-2549 carrier). Error surfaces fixed by the schema:
  `UnsupportedProtocolVersionError` is `-32022` with
  `data: {supported: string[], requested: string}`; missing-capability is
  `-32021` with `data.requiredCapabilities`. (These draft-only codes —
  together with `HeaderMismatch` `-32020`, WS3 — are shown throughout this
  plan at their post-renumber values per spec PR modelcontextprotocol#2907;
  they were originally `-32004`/`-32003`/`-32001` and were reallocated when
  the draft tool adopted #2907 at `0.2.0-alpha.5`. See the WS7 update for
  the bump record.) Nothing replaces
  `notifications/initialized` — readiness is implicit per-request. (The
  handshake-removal and detection logic is WS2; the GET-SSE replacement is
  WS3.)
- **SEP-2567 (types half):** the protocol-level session disappears from the
  `2026-07-28` code path — internal state must not assume a session identity.
  (Header emission/acceptance changes are WS3.)
- **SEP-2106:** accept full JSON Schema 2020-12 in tool schemas (composition,
  conditionals, `$ref`), enforcing the spec's constraints — `inputSchema`
  keeps a root `type: "object"`, `outputSchema` is unrestricted,
  `structuredContent` may be any JSON value conforming to it.
- **SEP-2164:** missing-resource error changes from `-32002` to `-32602`
  under `2026-07-28` (legacy revisions keep `-32002`). The final text adds:
  `error.data` SHOULD carry the requested `uri`, servers MUST NOT return an
  empty `contents` array for a nonexistent resource, and clients SHOULD keep
  accepting `-32002` from legacy servers.
- **SEP-2549:** `ttlMs` / `cacheScope` fields — both **required** in the
  final schema (`ttlMs` integer ≥ 0, `cacheScope` exactly
  `"public" | "private"`, no default scope) — on **six** result types:
  the four list results, `resources/read`, and `server/discover`.
  `CallToolResult` / `GetPromptResult` do not carry them.
- **SEP-414:** preserve and expose W3C Trace Context fields in `_meta` —
  pass-through and accessor surface only, no OpenTelemetry dependency
  ([docs/dependency-policy.md](dependency-policy.md)). **Drift note:** the
  reserved keys are the *bare* `traceparent`, `tracestate`, and `baggage`
  (three keys, not two) — an explicit documented exception to the `_meta`
  DNS-prefix convention; they must never be namespaced or stripped. Carried
  on requests and notifications; MCP imposes no generation obligation.

**Research focus (step 1):** final SEP-2575 `_meta` field names and the
`server/discover` request/response schema; exact SEP-2106 validation
obligations on the server side (validate vs. pass through); whether SEP-2549
fields appear on additional result types in the final schema.

**Completion criteria**

- All new/changed `Types/` classes round-trip serialize/deserialize with unit
  coverage, including `ExtraFieldsTrait` forward-compatibility behavior.
- `server/discover` answers correctly on stdio and HTTP with capabilities
  identical to what the legacy `initialize` result advertises.
- Version gating proven by tests: every WS1 behavior activates only when the
  negotiated revision is `2026-07-28`, and all legacy-revision tests still
  pass unchanged.
- Draft-track entries attributed to this workstream in
  [`conformance/conformance-draft-baseline.yml`](../conformance/conformance-draft-baseline.yml)
  pass and leave the draft baseline (or are re-attributed with a documented
  reason).
- `composer check` green; `composer conformance` regression-free.

**Status (2026-06-12):** implemented, verified, and code-reviewed (step 3);
all six review findings were assessed as WS1-scope and fixed: ephemeral
discover sessions are now deleted from the session store rather than
persisted unreachable; discover requests bypass the transport's legacy
version-header gate so an unsupported version yields the spec's `-32022`
with the original request id and `data.supported`/`data.requested`, and the
sessionless path maps the modern error codes (`-32602`/`-32021`/`-32022`)
onto HTTP 400 as SEP-2575 mandates — discover responses are deliberately
never SSE-framed, even on SSE-enabled servers, because the result is a
single self-contained cacheable document and only a plain JSON response
can carry those statuses (the status mapping for the *general*
per-request modern path — including the removed-method 404s — lands with
WS2's era detection; header/`_meta` mismatch `-32020` remains WS3); legacy clients now also get JSON-array
(PHP list) `structuredContent` stripped, not just scalars; the `_meta`
envelope validation type-checks `clientInfo` (Implementation shape) and
`clientCapabilities` (object, not array/scalar); explicit
`structuredContent: null` is representable end-to-end and any-JSON returns
(including strings) from tools with an `outputSchema` produce conforming
structured output; and `completion/complete` preserves `_meta` like every
other request family. Version constants and feature gating
(`stateless_lifecycle`, `caching_hints`, `resource_not_found_invalid_params`,
`json_schema_2020_12`), the `_meta` envelope types (`MetaKeys`),
`server/discover` answering on stdio and HTTP with envelope validation and
`-32022`, the `resultType`/`ttlMs`/`cacheScope` result surface with
modern-stamping/legacy-stripping in `adaptResponseForClient()`, version-gated
SEP-2164 error codes, SEP-2106 schema pass-through plus any-JSON
`structuredContent`, and SEP-414 trace-context accessors (`TraceContext`)
are in place with unit coverage. A deliberate era split was introduced:
`LATEST_LEGACY_PROTOCOL_VERSION` (`2025-11-25`) caps what the initialize
handshake can negotiate, since the handshake itself is removed in
`2026-07-28`. Draft-baseline curation: `json-schema-ref-no-deref` passes and
left the baseline; `sep-2164-resource-not-found` and `caching` were
re-attributed to WS2 with a documented shared root cause (the draft tool
speaks the per-request stateless lifecycle with the `DRAFT-2026-v1`
identifier, which requires WS2's per-request era detection to serve).

## WS2 — Client/server negotiation

Dual-era interoperability: each side detects which era its peer speaks,
following the spec's documented detection rules rather than guessing.

**Scope**

- **Server side:** key off per-request version metadata (the
  `MCP-Protocol-Version` header with matching `_meta`) and the `initialize`
  method for legacy clients — never the session id. Under `2026-07-28` the
  `Mcp-Session-Id` header is ignored, not treated as a routing or mode signal.
- **Client side, HTTP:** send a modern request first; on `400`, inspect the
  body. A recognized modern JSON-RPC error (e.g.
  `UnsupportedProtocolVersionError`) means the server is modern — retry with
  one of its advertised versions, do **not** fall back. An empty or
  unrecognized body means fall back to `initialize` and continue legacy.
- **Client side, stdio:** probe `server/discover` with the preferred version
  in `_meta`; fall back to the legacy handshake on `Method not found`
  (`-32601`); on `UnsupportedProtocolVersionError` retry with an advertised
  version instead of falling back.
- Version-mismatch error surfaces (`UnsupportedProtocolVersionError`) emitted
  and consumed per spec.

**Research focus:** the final spec's normative detection language (the RC text
may tighten); the exact wire shape of `UnsupportedProtocolVersionError` and
its advertised-versions payload; how the reference SDKs sequence the probe.
**Added by WS1 (2026-06-12):** how implementations should treat the
`DRAFT-2026-v1` identifier during the RC window — the pinned draft
conformance tool sends `MCP-Protocol-Version: DRAFT-2026-v1` (header and
`_meta`) on every stateless request, so WS2's per-request era detection must
decide whether/where the draft identifier is accepted alongside
`2026-07-28` (check how the TypeScript SDK v2 handles it) without leaking it
into the legacy negotiation surface.
**Added by WS1 re-review:** implementation notes for the per-request
detection work, from the post-commit review of the WS1 change set:
(a) `HttpServerRunner::applyStatelessErrorStatus()` maps modern error codes
to HTTP statuses by re-decoding the serialized response body — acceptable
for the narrow discover-only path, but the general modern status mapping
(400/404) landing here should use a structured signal between session and
runner rather than extending body-sniffing; (b) a discover
POST body is JSON-decoded three times on the transport path —
`HttpServerTransport::isDiscoverRequest()`, then `isInitializeRequest()`
(computed before the discover branch returns early), then again inside
`handlePostRequest()` — if era detection also needs to inspect bodies,
consolidate all of them to a single parse; (c) `ClientSession::discover()` duplicates the hardcoded
client identity (`mcp-client`/`1.0.0`) from `initialize()` — extract a
shared helper before the client-side probe builds on it; (d) cosmetic:
`validateModernRequestMeta()` reports a required `_meta` field explicitly
set to JSON `null` as "missing" rather than "invalid" (`isset()` semantics)
— tighten the message while reworking envelope validation for per-request
use.

**Completion criteria**

- A test matrix covering all four pairings — modern client × modern server,
  modern × legacy, legacy × modern, legacy × legacy — over both stdio and
  HTTP (in-memory transports acceptable), each negotiating the correct era
  with no spurious fallbacks.
- The fallback path is provably not triggered by non-legacy `400`s
  (missing-capability and header-validation failures covered by tests).
- Draft-track entries attributed to this workstream in the draft baseline
  pass and leave the baseline (or are re-attributed with a documented
  reason).
- `composer check` green; `composer conformance` regression-free.

**Status (2026-06-12):** implemented, verified, and code-reviewed (step 3);
Research resolved the `DRAFT-2026-v1` question with a drift
finding: the spec repository renamed the draft wire identifier to the dated
`2026-07-28` at RC lock and conformance `main` followed, but the pinned
`0.2.0-alpha.2` tool still sends `DRAFT-2026-v1` on every stateless
request — so the SDK accepts it as an RC-window alias for `2026-07-28` on
the per-request path only (`Version::DRAFT_MODERN_PROTOCOL_VERSION`,
canonicalized for all feature gating, advertised in
`DiscoverResult.supportedVersions` and `-32022 data.supported`, never
negotiable via `initialize`; it retires at WS7's tool convergence). The
TypeScript SDK v2 has types only — no stateless runtime or probe logic —
so the spec's normative text (the new Modern/Legacy/Dual-era terminology
and compatibility matrix on the draft versioning page) was the authority.
Server side: per-request era detection in `ServerSession::handleRequest()`
(envelope or transport-declared header signal; modern `_meta` wins over
the `initialize` method name), per-request adoption of version/clientInfo/
clientCapabilities (capabilities are provably not inferred from prior
requests), removed-method and unknown-method `-32601`→404, the
400-mapping for `-32602`/`-32021`/`-32022` via a structured
`httpStatusHint` on `JsonRpcMessage` (replacing body re-decoding, WS1
re-review item a), one POST body parse in the HTTP transport (item b),
every modern request served sessionless on a fresh `HttpServerSession`,
and `-32021` `MissingRequiredClientCapabilityError` raised from the
sampling/elicitation entry points (everything-server gained the
`test_missing_capability` tool the draft suite calls). Client side:
`ClientSession::negotiate()` implements the spec's probe/fallback rules
(retry-on-`-32022`, never-fallback on recognized modern errors, fallback
on any other error/timeout/malformed discover result), a shared
`clientIdentity()` helper (item c), envelope stamping on every modern
request with the `MCP-Protocol-Version` header mirrored from `_meta`, and
`Client::connect()` `protocolMode`/`probeTimeout` options; the legacy
GET-SSE stream is not opened on the modern path. `validateModernRequestMeta()`
distinguishes null from missing fields (item d). Two latent client
transport bugs surfaced by the four-way matrix and conformance were fixed:
`readTimeout` now fires against a fully silent peer, and HTTP-delivered
JSON-RPC error responses surface as typed `McpError` instead of an opaque
"Critical MCP error" `RuntimeException` (recorded for WS6's API audit).
Completion criteria met: the four-pairing matrix and fallback-safety tests
are in `tests/Server/ServerEraDetectionTest`,
`tests/Server/HttpModernRequestTest`, and
`tests/Client/ClientNegotiationTest`; `composer check` green;
stable conformance regression-free (291 passed, +4 from the typed-error
fix); draft baseline curation: `sep-2164-resource-not-found` (3/3) and
`caching` (7/7) pass and left the baseline, `server-stateless` passes
17/19 after the review round (the two capability checks only began
executing once `McpServer` propagated `McpError` — see below) with two
failing checks: SEP-2243's header/`_meta` mismatch `-32020` plus the
`subscriptions/listen` SHOULD warnings (re-attributed to WS3), and the
upstream tool's string-array `requiredCapabilities` assertion (a
documented upstream tool bug, not pursued in the SDK — see the review
spec question below); and `http-custom-header-server-validation` left the
baseline as inactive (see the WS3 note below).
**Review round (step 3):** all four findings assessed as WS2-scope and
fixed with regression tests. (1) The era a modern request adopts is now
request-scoped — session state (initialization, negotiated version,
client params) is snapshotted and restored around modern dispatch, so a
modern stdio request can no longer mark the session initialized for later
bare requests, and no longer clobbers a legacy-initialized stdio
session's negotiated state. (2) The SEP-2575 pre-dispatch checks
(envelope `-32602`, version `-32022`) were extracted into
`modernEnvelopePreDispatchError()` shared with the malformed-request
answer path, so unknown/removed methods with a broken envelope are
rejected 400/`-32602` before any `-32601` routing. (3)
`createJsonResponse()` keeps the SEP-2575 status when a handler emits a
notification before its response — and (follow-up review finding) the
modern JSON response is now always the single JSON object the Streamable
HTTP spec requires, never a `[notification, error]` array: interleaved
notifications are dropped on the modern JSON path, with WS3's
request-scoped SSE and `subscriptions/listen` as their carriers (see the
WS3 note). Legacy multi-message behavior is unchanged. (4) HTTP probes are bounded by the
probe timeout: `StreamableHttpTransport::setProbeTimeout()` caps cURL for
requests carrying the modern envelope (set by `Client::connect()` around
negotiation, so the legacy fallback `initialize` keeps the normal
timeout), and a cURL operation timeout now throws the typed
`HttpRequestTimeoutException`, which `negotiate()` classifies as a silent
legacy server (fallback) rather than a transport failure.
**Review spec question (resolved per "official text wins"):** the review
flagged that the SDK emitted `-32021`'s `data.requiredCapabilities` as a
string array matching the pinned conformance tool, while SEP-2575
describes it as a ClientCapabilities object. Verified accurate: the SEP
final text, the draft `schema.ts` (with the canonical example
`{"requiredCapabilities": {"elicitation": {}}}`), the TypeScript SDK v2
types, and even the conformance repo's own vendored draft types all
specify the OBJECT shape — while the tool's check asserts a string array
on the pinned 0.2.0-alpha.2 *and* on conformance `main` (an unreconciled
upstream tool bug, contradicting types in its own repository). The SDK
now emits the schema's object shape; the resulting
`sep-2575-server-rejects-undeclared-capability` failure is documented in
the draft baseline as the upstream bug (re-checked at every draft-pin
bump; candidate for an upstream issue per SEP-2484). Chasing the
end-to-end repro also uncovered that `McpServer`'s tools/call wrapper had
been converting SDK-raised `McpError`s into `isError` tool results — the
`-32021` never actually reached the wire and the tool's capability checks
were silently skipping. The wrapper now propagates `McpError` as protocol
errors (consistent with its existing `McpServerException` handling), so
`sep-2575-missing-capability-http-400` genuinely passes; this is a
client-visible behavior change for tool handlers that deliberately throw
`McpError` (JSON-RPC error instead of `isError` result) — added to WS6's
v1→v2 API audit list.

## WS3 — Transport changes

The HTTP-layer and streaming changes of the stateless revision, plus the
authorization-hardening SEPs (which ride in this workstream because they live
in the same transport/auth layer).

**Scope**

- **SEP-2243:** emit and validate the request-metadata headers — `Mcp-Method`
  on all requests and notifications; `Mcp-Name` on the name/uri-bearing
  methods (`tools/call`, `resources/read`, `prompts/get`; reused by Tasks for
  the task id). Mismatch with the body, or a missing required header, is
  rejected `400` with the `HeaderMismatch` / `-32020` error — which also
  covers a version-header/`_meta` mismatch. Support the `x-mcp-header` schema
  annotation mirroring designated tool parameters into `Mcp-Param-*` headers.
- **SEP-2567 (header half):** stop emitting and honouring `Mcp-Session-Id`
  on the `2026-07-28` path; legacy revisions keep it.
- **SEP-2575 (stream half) + SEP-2260:** remove the standalone GET SSE stream
  on the modern path; implement `subscriptions/listen` as the long-lived
  channel, restricted to server→client *notifications* only. Request-scoped
  SSE response streams (request-related notifications, then the final
  response) remain. `Last-Event-ID` resumption does not exist on the modern
  path (legacy keeps it).
- **SEP-2322:** the multi-round-trip request mechanism — sampling,
  elicitation, and roots become `InputRequiredResult` exchanges
  (`inputRequests` / `requestState` / `inputResponses`) instead of
  server-initiated requests. Rework the existing
  `ClientRequestSuspendException` suspend/resume pattern onto this wire shape
  for the modern path.
- **Authorization hardening:** SEP-2468 (`iss` validation per RFC 9207),
  SEP-837 (`application_type` on registration), SEP-2352 (credential binding
  to the issuing server's `issuer`), SEP-2207 (refresh-token clarifications),
  SEP-2350 (scope accumulation on step-up), SEP-2351 (`.well-known` discovery
  suffix expectations) — all within the existing `Client/Auth/` /
  `Server/Auth/` framework. The near-term conformance gaps
  (`client_credentials` with JWT assertions and HTTP Basic) close here too.

**Research focus:** the final `subscriptions/listen` method/stream semantics
and its interaction with request-scoped streams; the complete
`InputRequiredResult` state machine including `requestState` round-tripping
and timeout/abandonment semantics; the normative `Mcp-Param-*` encoding
rules; final text of each auth SEP.
**Added by WS1 re-review:** WS1 forward-declared
`MetaKeys::SUBSCRIPTION_ID` (`io.modelcontextprotocol/subscriptionId`) for
the `subscriptions/listen` correlation id — re-verify the key against the
final SEP-2260 text before building on it.
**Added by WS2 (2026-06-12):** (a) the one remaining `server-stateless`
check is SEP-2243's version-header/`_meta` mismatch — must answer `-32020`
HeaderMismatch + 400 (WS2's era detection currently answers from the
envelope, yielding `-32022`); wire it into the same header-validation
layer as `Mcp-Method`/`Mcp-Name`. (b)
`http-custom-header-server-validation` left the draft baseline reporting
0 checks: its checks only engage once the everything-server exposes a tool
with `x-mcp-header` annotations — add one with the SEP-2243 work and
re-baseline honestly if its checks then fail. (c) The alpha.2 run surfaced
two additional `input-required-result-*` scenarios
(`-unsupported-methods`, `-validate-input`) that already pass — confirm
they stay green when SEP-2322 lands. (d) Note for the draft-pin bump: the
next conformance release sends the dated `2026-07-28` instead of
`DRAFT-2026-v1`; the SDK already accepts both, and the alias constant
retires at WS7 convergence. (e) Notifications emitted while serving a
modern request are currently DROPPED by
`HttpServerTransport::createJsonResponse()` — the modern JSON response
mode carries a single object, and WS2 ships no modern streaming. When
this workstream adds request-scoped SSE, route handler-emitted
notifications onto the request's stream and remove the drop (and its
note in the WS2 status).
**Added by WS2 post-commit review (2026-06-12):** two robustness/accuracy
nits in code this workstream touches, to clean up alongside its work:
(a) two WS2 code paths discriminate exceptions by message string —
`ServerSession::answerMalformedRequest()` keys `-32601` vs `-32602` off
`str_contains($e->getMessage(), 'Unknown client request method')`
(coupled to the literal in `Types/ClientRequest.php`), and
`ClientSession::negotiate()` detects stdio probe timeouts via
`str_starts_with($e->getMessage(), 'Timed out waiting for response')`
(coupled to `readNextMessage()`'s message). Both are SDK-internal and
test-covered, but replace them with typed exceptions (an
unknown-method exception from typed request construction; a typed stdio
read-timeout mirroring the HTTP path's `HttpRequestTimeoutException`)
while reworking these layers. (b) The
`StreamableHttpTransport::receiveFromHttp()` docblock overclaims that
every queued message carries a request id ("`enqueueJsonRpcPayload()`
refuses id-less payloads") — `deliverServerInitiatedMessage()` falls
back to the same pending queue for id-less notifications when no
dispatcher is registered or dispatch fails; the behavior is correct,
fix the comment when touching the transport.

**Completion criteria**

- Header emission/validation covered by unit tests on both runner and client
  transport, including every `-32020` rejection case.
- `InputRequiredResult` round-trips for sampling and elicitation work
  end-to-end through `HttpServerRunner` and the stdio runner, with the
  multi-round-trip state machine tested across at least two rounds.
- `subscriptions/listen` delivers list-changed notifications on the modern
  path; an attempted server-initiated *request* over that channel is
  impossible by construction or rejected by tests.
- Auth additions pass the previously-failing baseline scenarios
  (`auth/client-credentials-jwt`, `auth/client-credentials-basic`), or the
  baseline documents precisely why not; `auth/cross-app-access-complete-flow`
  investigated and resolved or re-documented.
- Draft-track entries attributed to this workstream in the draft baseline
  (the SEP-2243 header scenarios, the `InputRequiredResult` scenarios, and
  the SEP-2468/auth-hardening scenarios) pass and leave the baseline.
- `composer check` green; `composer conformance` regression-free, with both
  tracks' baselines shrinking, not growing.

**Status (2026-06-12):** implemented, verified, and code-reviewed (step 3);
Committed and tagged as v2.0.0-alpha1. Research drift findings, applied per
"official text wins": (1) `subscriptions/listen` is **SEP-2575**, not
SEP-2260 — SEP-2260 is the rule that server requests must be associated
with a client request (the basis of the no-server-requests-on-streams
restriction that MRTR replaces); (2) the listen channel carries only the
four *opted-in* change-notification types via a `SubscriptionFilter`
(`toolsListChanged`/`promptsListChanged`/`resourcesListChanged`/
`resourceSubscriptions`, the last replacing the removed
`resources/subscribe` RPC), the `notifications/subscriptions/acknowledged`
ack MUST be the stream's first message, every frame carries
`_meta` `io.modelcontextprotocol/subscriptionId` = the stringified listen
request id (WS1's forward-declared key re-verified correct), and the listen
request never gets a JSON-RPC response; (3) SEP-2350 (scope union) and
SEP-2351 (discovery order) were already conformant — they shipped as
verify-plus-regression-tests, not new work; (4) the failing checks in the
"accept-side" iss scenarios and `auth/offline-access-not-supported` were
SEP-837's cross-cutting `application_type` assertion, not iss/refresh
logic. Delivered: SEP-2243 server validation (`Mcp-Method`/`Mcp-Name`
presence+match, OWS trim, case-sensitive values, header-vs-`_meta` version
mismatch — `-32020` evaluated BEFORE `-32022`, which fires only when
header and `_meta` agree; `Mcp-Param-*` designated parameters validated
against tool schemas with strict base64-sentinel decoding) and client
emission (headers derived from each enveloped message; `Mcp-Param-*` from
`x-mcp-header` annotations with invalid-annotation tools excluded from
modern HTTP `tools/list`), shared rules in `Mcp\Shared\McpHeaders`
(tasks/get|update|cancel → `params.taskId` mapping already included for
WS4). SEP-2567: no session id minted/echoed/honored anywhere on the modern
path, including streams. SEP-2575 streams: handler-emitted notifications
now ride request-scoped buffered SSE on success responses (the WS2 drop is
removed; error responses stay plain JSON since the 400/404 statuses cannot
ride a committed SSE stream); `subscriptions/listen` implemented on HTTP
(runner-held stream: ack first, bus-polled events, strict filter
containment, keep-alive abort detection, no event ids/resumption) and
stdio (in-session subscriptions, id-tagged demux), with a pluggable
`SubscriptionBusInterface` (`FileSubscriptionBus` for multi-process PHP
hosting, in-memory for tests/long-running runtimes) and `McpServer`
publish helpers. SEP-2322: `InputRequiredResult` exchanges on tools/call
and prompts/get over both transports via ephemeral re-execution (the
model the TS SDK's exploration branches validated): `ElicitationContext`/
`SamplingContext` gained exchange-backed modern paths and optional
`inputKey` naming, the new `InputContext` batches mixed input requests
into a single round, and `requestState` is HMAC-SHA256-signed
(`RequestStateCodec`, file-backed per-installation secret for
multi-process deployments, expiry enforced, tamper → `-32602`) carrying
consumed results between rounds; resources/read accepts the retry params
but no context injection ships for resource callbacks (the spec's MAY —
recorded as intentionally descoped). Client MRTR loop services
`inputRequests` through `onElicit`/`onSampling` (new)/roots handlers with
verbatim state echo, fresh ids, a 16-round cap, per-call isolation, and
absent-`resultType`-means-complete. `Client::connect()` gained
`protocolMode: 'modern'` (no probe — required by conformance mocks that
reject `initialize` AND `server/discover`) and a `protocolVersion`
preference; modern sessions adopt an advertised version and retry once on
`-32022`. Auth hardening: SEP-837 `application_type` (derived
native/web), SEP-2468 iss validation (non-normalized byte comparison,
error params suppressed on mismatch, `AuthorizationCallbackResult` with
BC for string-returning handlers), SEP-2352 PRM re-fetch on 401 +
re-registration at the new AS without credential reuse, SEP-2207
offline_access gating — plus the `client_credentials` grant
(private_key_jwt with ES256/RS256 incl. DER→raw conversion, and
client_secret_basic) and the SEP-990 cross-app-access flow (RFC 8693 +
RFC 7523), closing the stable track's last three baseline entries. WS2
review carry-overs done: typed `UnknownMethodException` and
`ReadTimeoutException` replace both message-string discriminations; the
`receiveFromHttp()` docblock overclaim is fixed. A latent serialization
bug surfaced by testing was fixed: `HttpServerSession::toArray()` now
deep-normalizes `clientParams`, so client capabilities survive session
persistence on non-JSON stores (previously the in-memory store silently
dropped them between requests). Conformance: `composer check` green (1116
tests); stable track 40 server + 319 client scenarios pass with **both
stable baselines now empty**; draft track passes everything except the
one documented upstream tool bug (`sep-2575-server-rejects-undeclared-
capability` asserts a string array against the schema's ClientCapabilities
object — re-checked at every pin bump), the draft client baseline is
empty, and the two listen SHOULD warnings pass only where
`PHP_CLI_SERVER_WORKERS` is available (POSIX; `run-conformance.php` now
sets it — the trigger call must be served concurrently with the open
stream, impossible on Windows's single-worker CLI server). Two earlier
baseline comments were corrected during re-curation (`request-metadata`
was about the SEP-2575 envelope checks, not Mcp-Method/Mcp-Name;
`offline-access-not-supported` was about SEP-837).
**Review round (step 3):** all seven findings assessed as legitimate and
fixed with regression tests. (1) SEP-2322 `requestState` is now
cryptographically bound to the authenticated principal: the runner
forwards the validated token's `sub` claim into the session, the signed
payload carries it, and a different user replaying captured state fails
verification exactly like tampering. (2) x-mcp-header validation now
matches the final SEP-2243 text: annotations are collected at any
nesting depth (dot-path keys, case-insensitive uniqueness across the
whole schema), type `number` is prohibited, and designated integer
values are enforced within ±(2^53−1) on both sides (the client throws
before wire traffic; the server rejects -32020). Consequence, per the
no-shortcuts rule: the pinned alpha tool's `http-custom-headers`
scenario still REQUIRES mirroring number-typed parameters, so the SDK's
spec-faithful rejection re-enters the draft baseline as documented
upstream staleness (the draft baseline now holds exactly two
upstream-tool entries; `http-invalid-tool-headers` and the server-side
custom-header scenario still pass). (3) `notifications/cancelled`
referencing a listen request id now terminates the stdio subscription.
(4) A server that cannot deliver subscription events no longer
acknowledges them: the HTTP path answers -32601 without a configured
bus, and `resources.subscribe` is derived from handler registration —
`McpServer::subscriptionBus()` registers minimal legacy
subscribe/unsubscribe acceptors so the convenience API can honor
`resourceSubscriptions`. (5) The file-backed MRTR secret initializes
via exclusive create (one writer; losers read the winner's bytes) and
fails loudly when no shared secret can be established, instead of
silently falling back to a process-local secret that would break
cross-worker verification. (6) The listen loop captures the bus cursor
BEFORE the acknowledgement is flushed, closing the window where an
event triggered on seeing the ack could be lost. (7)
conformance/README.md and ROADMAP.md were brought in line with the
emptied stable baseline.
**Follow-up review round:** three further findings, all fixed with
regression tests. (1) Principal binding no longer collapses to null for
valid tokens with empty claims (TokenValidationResult permits them): the
runner prefers the `sub` claim (`sub:` prefix), falls back to a SHA-256
fingerprint of the presented bearer token (`tok:` prefix) so distinct
credentials never share a binding, and mints a random identity (fails
closed) in the unreachable claims-without-token case; null remains only
where authorization is not in play at all. (2) The modern
`resourceSubscriptions` honor was separated from the legacy capability:
`McpServer::subscriptionBus()` no longer registers no-op legacy
subscribe handlers (which advertised a legacy RPC that delivered
nothing), and `SubscriptionFilter::intersectWithCapabilities()` gates
`resourceSubscriptions` on actual deliverability (the bus on HTTP, the
in-session channel on stdio, plus the server serving resources) rather
than on `resources.subscribe` — the acknowledgement frame is the modern
honor signal; `Server::getCapabilities()` keeps deriving the legacy
flag from real handler registration. (3) The ±(2^53−1) integer bound
now also covers integral floats (how PHP decodes large JSON integers):
`McpHeaders::isSafeIntegerValue()` requires finite, integral, in-range
values, enforced on integer-typed designated parameters by both the
client (throws before wire traffic, non-finite floats rejected
outright) and the server (-32020).
**Post-commit review round (2026-06-12):** four major findings from the
post-commit review of the WS3 change set, all fixed with regression
tests. (1) `HttpServerRunner` kept the ephemeral modern session in
`$this->serverSession` after the sessionless early-return, so a later
legacy request with no saved state (e.g. a fresh `initialize`) on a
reused runner — long-running runtimes, embedding, tests — was served by
the stale modern-declared instance (rejected `-32602` for lacking the
`_meta` envelope) still carrying the prior request's headers and
authenticated principal; the modern session is now installed only while
its own request dispatches, with the previous session restored on both
the runner and `Server` facade on every exit path. (2) The SEP-2352
pre-registered-credentials migration block was single-shot: it discarded
the stored tokens — whose recorded issuer was what armed migration
detection — before throwing, so a retried 401 found no tokens, skipped
the guard, and silently presented the old credentials to the new AS; the
fixed first with an in-process migration marker, then superseded in the
same review cycle by spec-model issuer binding (see the credential
binding round below). (3) The 403
`insufficient_scope` path had no SEP-2352 guard
at all; it now busts the PRM cache and runs the same
issuer-change check as the 401 path before any grant flow, closing the
hostile-RS `resource_metadata` redirection vector. (4) The default MRTR
signing secret lived at a predictable shared-temp path with a
world-readable creation window and a permanent-DoS stub on writer
crash; `RequestStateCodec::withFileSecret()` now initializes under an
exclusive flock (reclaiming crashed-writer stubs), verifies 0600 before
any secret byte is written, and — at the default path, or on request
via the new `$verifyOwnership` parameter — verifies the pathname still
matches the locked handle and refuses symlinks and (POSIX) foreign-owned
or group/other-readable files, failing loudly with
explicit-secret guidance. Re-verified: `composer check` green (1148
tests); stable conformance 40 server + 325 client checks, zero
failures, both baselines still empty — six additional checks now
execute and pass in the scope step-up scenarios (`auth/scope-step-up`
21→23, `auth/scope-retry-limit` 22→26), exercised by the 403-path
discovery re-fetch that fix (3) added; draft track unchanged at the two
documented upstream-tool baseline entries.
**Credential binding round (2026-06-12):** the in-process migration
marker from review fix (2) was a workaround for a missing data-model
concept the `2026-07-28` draft makes normative (Authorization Server
Binding, client-registration page): clients using pre-registered
credentials "MUST associate those credentials with the specific
authorization server that issued them, keyed by the authorization
server's `issuer` identifier", MUST NOT reuse them across authorization
servers, and SHOULD surface an error when PRM points at a different
issuer than the one the credentials were registered with. Implemented
directly: `ClientCredentials` gains a trailing optional `issuer`
binding, enforced in `OAuthClient::getClientCredentials()` after RFC
8414 issuer validation and before every grant flow (authorization code,
refresh, client_credentials, cross-app access) — so bound credentials
are blocked from a migrated/hostile AS even in a fresh PHP process with
no stored tokens, and the block self-heals once the operator configures
credentials bound to the new issuer (no manual token-storage cleanup).
Unbound (legacy) credentials are pinned to the first validated issuer
per OAuthClient instance — from first use, or from the stored tokens'
issuer when `handleIssuerChangeIfAny()` detects a migration — replacing
the marker entirely; unbound mode remains supported because the
conformance harness supplies pre-registered credentials without issuer
context (the AS issuer is a runtime-chosen localhost port), and
hard-rejecting unbound credentials would force a conformance-client
workaround. `AuthorizationRequest::$issuer` is carried onto rebuilt
credentials (code exchange, AUTH_METHOD_AUTO resolution), the webclient
persists the issuer alongside captured credentials, and
`docs/client-dev.md` documents the binding. Verified: `composer check`
green (1154 tests, 6 new — cross-process block without stored tokens,
matching-issuer pass-through, remediation self-healing, first-use
pinning across resources, AUTO-resolution binding preservation, issuer
field defaults); stable conformance 40 server + 325 client, zero
failures, baselines empty (`auth/pre-registration`,
`auth/token-endpoint-auth-*`, and cross-app scenarios exercise the
unbound pinning path); draft track unchanged at the two documented
upstream-tool baseline entries, `auth/authorization-server-migration`
28/28.
**Binding review round (2026-06-12):** a follow-up review of the
credential binding raised three findings; all three were verified
against the code and addressed with red-first regression tests. (1)
Issuer *binding* comparisons used `urlsMatch()` normalization
(scheme/host case folding, default-port and trailing-slash stripping),
but RFC 8414 §3.3 requires the issuer to be byte-identical — binding
enforcement and the remediation carve-out now compare with exact
code-point equality, while `urlsMatch()` deliberately remains in
token-based migration *detection* only, where normalization can only
suppress a false migration alarm, never grant a mismatched issuer
access. (2) Multi-AS selection ignored the binding:
`resolveAuthorizationServer()` always took `authorization_servers[0]`
and the binding check then rejected the connection even when the bound
issuer appeared later in the list — the resolver now prefers the bound
(or pinned) issuer when it appears anywhere in the PRM list, per RFC
9728 §7.6 which assigns AS selection to the client. (3) The unbound
pinning trade-off (each PHP-FPM request starts unbound) is retained for
the documented conformance-harness reason, but is now louder and
narrower: the pin logs a warning recommending
`ClientCredentials::$issuer`, and the webclient reference collects an
optional issuer on the connection form (round-tripped through the
redacted prefill — the issuer is a public URL, not a secret) and feeds
it into both the form-entered and callback-captured credential paths.
Verified: `composer check` green (1157 tests, 3 new — bound-issuer
selection from a multi-AS list, pinned-issuer preference across
resources, exact-comparison rejection of a default-port issuer
variant); stable conformance 40 server + 325 client, zero failures,
baselines empty; draft track unchanged at the two documented
upstream-tool baseline entries, `auth/authorization-server-migration`
28/28.
**Post-RC spec drift round (2026-07-01):** three normative changes merged
into the draft spec after the RC lock were researched and absorbed, per
"official text wins". (1) Spec PR #2953 (2026-06-23) added
`SubscriptionsListenResult` — when the SERVER ends a subscription on its
own initiative it SHOULD answer the original `subscriptions/listen`
request with `{resultType: "complete", _meta:
{"io.modelcontextprotocol/subscriptionId": <listen id>}}` before closing,
so clients can distinguish a graceful end from an abrupt transport drop
(which stays response-less and MAY trigger a reconnect). Implemented:
`Mcp\Types\SubscriptionsListenResult`; the HTTP listen stream emits it as
the final SSE frame when the lifetime budget elapses with the client
still connected (never after a detected disconnect); stdio answers every
active subscription at server-initiated session stop
(`ServerSession::respondToActiveSubscriptions()`, original ids preserved
int-vs-string, written transport-direct so legacy-era response adaptation
cannot strip the modern-only shape; client-cancelled subscriptions are
never answered). (2) Spec PR #2937 (2026-06-29) resolved the SEP-2243
base64-sentinel case-sensitivity contradiction: the sentinel is
case-sensitive lowercase `=?base64?…?=` and a non-lowercase prefix (e.g.
`=?BASE64?`) is a literal value, never decoded — `McpHeaders` previously
implemented the contradicted "accept case-insensitively" reading and was
tightened on both encode and decode sides (a server now rejects an
uppercase-wrapped header that mismatches the body as -32020, and matches
it as a literal when the body carries the same literal). (3) Spec PR
#2972 (2026-06-30, rc-high-priority) decoupled `Mcp-Param-*` emission
from schema TTL: clients MUST build the headers from the most recently
obtained `inputSchema` regardless of `ttlMs` and only omit them when no
schema was ever retrieved — the SDK never coupled emission to TTL (the
annotation cache refreshes on each `tools/list`), so this was
verified-already-conformant and pinned with regression tests (`ttlMs: 0`
listing still drives headers; never-listed tool sends headerless).
`composer check` green; both conformance tracks regression-free against
their baselines. Also noted for WS6/WS10: the draft changelog now
describes OAuth Dynamic Client Registration as deprecated in favor of
Client ID Metadata Documents (spec PR #2858) — a deprecation-registry
item, no wire break.
**Drift-round review (step 3):** two findings raised; verified against
the official sources with one confirmed and one refuted. (1) CONFIRMED
and widened: `_meta["io.modelcontextprotocol/subscriptionId"]` is typed
`RequestId` in the draft schema — on the graceful-end result it "equals
this response's `id`", and the subscriptions prose shows an integer
listen id `1` carried as the JSON NUMBER `1` on the acknowledgement,
every stream notification, and the result. WS3's stringify-everywhere
reading ("the stringified listen request id") is therefore superseded
for the WIRE value on all frames of the channel, not just the new
result: the SDK now stamps the listen id in its original JSON-RPC type
everywhere (HTTP stream frames, stdio frames, the graceful-end `_meta`),
while the stringified form survives only as the internal bookkeeping
key (`activeSubscriptions` map keys,
`SubscriptionListenException::subscriptionId()` — docblocks updated).
(2) REFUTED: the review claimed spec PR #2972 (Mcp-Param TTL decoupling)
was still open and the drift round had treated proposed text as
official; verified via the GitHub API (`merged: true`, merged
2026-06-30, label rc-high-priority) and the raw `main` transport page,
which carries the new normative text verbatim ("Clients MUST construct
`Mcp-Param-*` headers using the most recently obtained `inputSchema`") —
the rendered site had likely not rebuilt. No change; the drift-round
records stand as written.
**Upstream drift: Authorization Server Binding vs the alpha conformance
tool (recorded 2026-06-12, MONITOR at every draft-pin bump).** The
binding review's finding (1) — unbound pre-registered credentials
preserve the cross-process exposure — turned out to be a drift between
the `2026-07-28` draft and the pinned alpha conformance tool, not an SDK
judgment call. Verified against both sources: the draft's
client-registration page makes binding unconditional ("MUST associate
those credentials with the specific authorization server that issued
them, keyed by the authorization server's `issuer` identifier") with no
provision for issuer-less pre-registered credentials, while the pinned
draft tool — `@modelcontextprotocol/conformance@0.2.0-alpha.2`, upstream
commit `25fd44323ff3fe28967b95ea8105de47f674b7d8` (stable pin `0.1.16`,
commit `21a9a2febd7100d7c17ac1021ee7f2ed9f66a1e0`) — supplies its
`auth/pre-registration` scenario context as `{client_id, client_secret}`
with no issuer, even though the scenario is tagged `DRAFT-2026-v1` in
the tool's own scenario list and the mock AS URL is known when the
context is built. Per the project policy for spec/tool drift, the SDK
aligns with the spec and the misaligned scenario is baselined:
mandatory issuer binding is now the SDK default (unbound pre-registered
credentials are rejected before any authorization or token request with
an actionable `REASON_UNBOUND_CLIENT_CREDENTIALS` error), and the
first-use pinning behavior survives only behind the new explicit
`OAuthConfiguration::$allowUnboundClientCredentials` legacy-compat flag
(published 2025-11-25 behavior, where no binding rule exists). The
conformance runner now passes `--track=draft` to `everything-client.php`
on the draft track: the draft client runs the strict default (verified:
`client-draft auth/pre-registration` fails its `pre-registration-auth`
check with "Client did not make a token request", 2/3 — exactly the
spec-mandated refusal — and is baselined in
`conformance-draft-baseline.yml` with the missing-issuer-context root
cause), while the stable client opts into the legacy flag (legitimate:
the stable track validates the published spec, and the option is a
documented public SDK path, not a bypass). `--suite draft` selects only
DRAFT-2026-v1-exclusive scenarios and `auth/pre-registration` is tagged
`[2025-11-25, DRAFT-2026-v1]`, so it is not in the draft suite; to keep
the baseline entry from going stale unnoticed, `run-conformance.php`'s
aggregate `draft`/`client-draft` gate runs it explicitly after the
suite (via `DRAFT_CLIENT_EXTRA_SCENARIOS`) and propagates its exit code
— verified by a negative test (removing the entry makes the aggregate
`client-draft` exit non-zero, where before the change the scenario was
never run). The webclient reference is strict by default too: its
connection form requires the issuer when a Client ID is supplied and
exposes the legacy unbound mode only behind an explicit, clearly-warned
"Allow unbound credentials (legacy)" checkbox that sets
`allowUnboundClientCredentials` (a later review found the webclient had
been hard-coding that flag on, silently reopening the cross-process
exposure on PHP-FPM — now off unless the box is ticked). **Monitoring:**
at every draft-pin bump and at
WS7 convergence, re-check whether upstream has added issuer context to
the scenario (then bind and drop the baseline entry) or started
asserting the strict refusal (then the entry leaves the baseline by
passing); candidate for an upstream issue per SEP-2484. Verified:
`composer check` green (1158 tests, 1 new — default rejection of
unbound credentials; the pinning/retry tests now opt into the legacy
flag, and incidental unbound fixtures in the iss-validation and
scope/grant suites were bound to their test issuer); stable conformance
40 server + 325 client, zero failures, baselines still empty; draft
track regression-free with `auth/authorization-server-migration` 28/28
and the new documented `auth/pre-registration` entry alongside the two
existing upstream-staleness entries.

## WS4 — Tasks extension

The SEP-2663 stateless redesign of the experimental Tasks surface. Breaking
redesign, no deprecation shims — the existing surface is pre-release
(per the project decision recorded in the roadmap).

**Scope**

- Redesign to `tasks/get` / `tasks/update` / `tasks/cancel` with the task
  handle returned from `tools/call`; **remove `tasks/list`** (unscopeable
  without sessions) **and `tasks/result`** (result is now inlined in the
  `tasks/get` completed response — calling either MUST return `-32601`) from
  the `2026-07-28` path and the client convenience API.
- Keep the file-based `TaskManager` store (shared-hosting compatibility);
  rework state transitions and TTL/expiry to the SEP-2663 model.
- `Mcp-Name` carries the task id on task methods (from WS3).
- Close the task-augmented gaps via the mechanism the final extension text
  actually settled on — **`inputRequests` / `inputResponses`** on
  `tasks/get` / `tasks/update`, NOT a `task` parameter on
  elicitation/sampling (see the drift note below). The stubbed `task`
  parameter on `ElicitationContext::form()` / `::url()` is removed.
- Declare Tasks per the SEP-2133 extensions framework (reverse-DNS id
  `io.modelcontextprotocol/tasks`, capability value the empty object `{}`,
  declared per-request in `_meta` and in `server/discover`).

**Research focus:** the final SEP-2663 method set and task-handle schema
(the RC moved this surface substantially and it may move again); the
extension's declared id/version string; whether task-augmented
elicitation/sampling is in the stable extension text or still draft.
**Added by WS3 (2026-06-12):** the SEP-2663 `Mcp-Name`-carries-the-task-id
routing rule is already wired: `Mcp\Shared\McpHeaders` maps
`tasks/get|update|cancel` → `params.taskId` for both client emission and
server validation — re-verify the method list against the final extension
text rather than re-implementing.

**Research findings (2026-06-27, step 1).** Tasks has been externalized from
core per SEP-2133 into its own independently-versioned repository
(`modelcontextprotocol/ext-tasks`, schema under `schema/draft/`); the core
`2026-07-28` schema carries zero task references. Three sources were
cross-checked: the ext-tasks `schema/draft` + `specification/draft` prose,
the pinned `0.2.0-alpha.7` draft conformance tool, and the existing SDK
surface. Significant drift from this plan, applied per "official text wins":
- **Method set:** `tasks/get` / `tasks/update` / `tasks/cancel` plus the
  optional `notifications/tasks` push. `tasks/list` AND `tasks/result` are
  both removed — both MUST answer `-32601`. The completed result is inlined
  in the `tasks/get` response (`DetailedTask`), so there is no separate
  result retrieval.
- **Field renames:** `ttl` → `ttlMs` (`int|null`, null = unlimited),
  `pollInterval` → `pollIntervalMs` (`int`). Legacy `ttl`/`pollInterval`
  keys MUST be absent on the wire.
- **Task handle:** `tools/call` returns a flat `CreateTaskResult`
  (`Result & Task`) discriminated by `resultType: "task"` — NOT a nested
  `task` object and NOT an `_meta` key. `resultType: "task"` MUST appear on
  no other result type.
- **`tasks/get` result:** `Result & DetailedTask` with `resultType:
  "complete"`, status-discriminated: `working`/`cancelled` carry only task
  fields; `input_required` adds `inputRequests` (a keyed map of full
  `ElicitRequest`/`CreateMessageRequest`/`ListRootsRequest` objects);
  `completed` adds the inlined `result` (original tool result, e.g.
  `CallToolResult` with non-empty `content[]`, `isError:true` for tool
  errors); `failed` adds `error` (`{code,message,data?}`), no `result`.
- **`tasks/update`:** `params {taskId, inputResponses}` where
  `inputResponses` is a keyed map of `ElicitResult`/`CreateMessageResult`/
  `ListRootsResult`; result is an empty `{resultType:"complete"}` ack.
  Partial fulfillment keeps the task `input_required` until all keys arrive.
  This — not a `task` parameter on elicitation/sampling — is the settled
  task-augmented input mechanism. (MRTR handles input *before* task
  creation on the original request; `inputRequests`/`inputResponses` handle
  input *during* a task.)
- **`tasks/cancel`:** `params {taskId}`, empty `{resultType:"complete"}` ack;
  cooperative and eventually-consistent (may settle to a terminal status
  other than `cancelled`); unknown `taskId` → `-32602`;
  `notifications/cancelled` MUST NOT be used for tasks.
- **`io.modelcontextprotocol/related-task` _meta key is DROPPED** — it must
  not appear on the inlined `tasks/get` result.
- **Server-directed creation:** there is no per-tool wire flag and no `task`
  request parameter — the server alone decides per-request whether to return
  a `CreateTaskResult`. A legacy `task` param on `tools/call` is tolerated
  and ignored (must not promote a sync tool to a task). A tool the server
  can only serve as a task ("task-required"), called by a client that did
  not declare the extension, → `-32021` with
  `data.requiredCapabilities.extensions["io.modelcontextprotocol/tasks"]`
  (an object). Calling `tasks/*` without declaring the extension → `-32021`.
- **Error codes:** the conformance tool at alpha.7 uses the post-#2907
  numbers (`-32020` HeaderMismatch, `-32021` MissingRequiredClientCapability)
  the SDK already adopted; the ext-tasks prose still shows the pre-renumber
  `-32003` and lags — the SDK uses the renumbered codes.
- **Conformance gating note:** the 10 SEP-2663 scenarios in the alpha.7 tool
  are registered in the tool's `pending` suite, NOT `draft`, so
  `--suite draft` alone does not exercise them. The runner's
  `server-draft`/`draft` gate therefore runs each of them explicitly after
  the suite (`DRAFT_SERVER_EXTRA_SCENARIOS`, mirroring the existing
  `DRAFT_CLIENT_EXTRA_SCENARIOS`), so they are gated against the draft
  baseline rather than skipped.

**Completion criteria**

- Full state-transition test coverage on the redesigned lifecycle, including
  TTL expiry and cancellation races, against the file-based store.
- A task-returning tool works end-to-end (create via `tools/call`, poll via
  `tasks/get`, cancel via `tasks/cancel`) over both transports.
- No `tasks/list` on the modern path; legacy experimental behavior is
  removed cleanly rather than shimmed.
- Conformance scenarios for the Tasks extension (when published) pass or are
  honestly baselined.
- `composer check` green; `composer conformance` regression-free.

**Status (2026-06-27):** implemented, verified, and code-reviewed (two review
rounds, all findings fixed); Human approved and committed. The pre-release
Tasks surface was replaced cleanly (no shims) with the SEP-2663 model:
- Types: flat `CreateTaskResult` (`Result & Task`, `resultType: "task"`) from
  `tools/call`, flat `TaskGetResult` (`DetailedTask`, `resultType: "complete"`,
  inlined `result`/`error`/`inputRequests` by status), empty `TaskUpdateResult`
  / `TaskCancelResult` acks, `Task` with `ttlMs`/`pollIntervalMs`; the
  `tasks/list`, `tasks/result`, `TaskCapability`, and `TaskStatusNotification`
  surfaces were removed (`tasks/list` and `tasks/result` now answer `-32601`).
- Tasks declared through the SEP-2133 `extensions` capability map (new
  `extensions` field on `Server`/`ClientCapabilities`, `ExtensionIds::TASKS`),
  advertised in `server/discover` and declared per-request in the `_meta`
  clientCapabilities envelope (`ClientSession::declareExtension()`); the v1
  `tasks` capability slot is gone. Malformed (non-object) extension values are
  ignored so they cannot unlock a feature.
- `McpServer::enableTasks()` registers `tasks/get`/`tasks/update`/`tasks/cancel`
  and a tool opts in via `tool(..., taskSupport:)` (`TaskSupport`
  FORBIDDEN/OPTIONAL/REQUIRED). The file-based `TaskManager` was reworked to the
  SEP-2663 state model (ttlMs expiry, idempotent cancel, in-task input via a
  stored signed `requestState`). Every `tasks/*` method requires the extension
  to be declared (era-independent `-32021`); a REQUIRED tool called by an
  undeclared modern client is rejected `-32021`, an OPTIONAL tool degrades to a
  synchronous result. Execution is synchronous-capture (shared-hosting model):
  the tool body runs in the creating request and the outcome is stored;
  genuine async/working tasks are application-driven via `getTaskManager()`.
- Client: `getTask()`/`updateTask()`/`cancelTask()`; `callTool()` returns
  `CallToolResult|CreateTaskResult` so a task handle is surfaced rather than
  mis-typed. The stubbed `task` parameter on `ElicitationContext` was removed;
  a legacy `task` param on `tools/call` is tolerated and ignored.
- Verification: `composer check` green (1174 tests; PHPStan clean); both stable
  conformance baselines stay empty. The 10 SEP-2663 tool scenarios are wired
  into the gated draft run (`DRAFT_SERVER_EXTRA_SCENARIOS`) backed by fixtures
  in `everything-server.php`: **8 pass**, `tasks-status-notifications` is
  skipped by the tool (0 checks, pending its subscriptions/listen rewrite), and
  `tasks-mrtr-composition` is the one baselined expected failure — its
  pre-creation-MRTR sequence is mutually exclusive with the SDK's spec-permitted
  in-task-input model (`tasks-mrtr-input` passes 3/3). End-to-end coverage in
  `tests/Server/TasksExtensionTest.php` (HTTP and stdio).

## WS5 — MCP Apps extension

Full server-side support for MCP Apps (SEP-1865, ext-apps stable revision
`2026-01-26`) as a committed v2 release feature. The UI renders host-side in
a sandboxed iframe; the SDK's role is server-side emission and ordinary
tool-call handling.

**Scope**

- Declare the Apps extension per the SEP-2133 framework.
- Register `ui://` template resources with the MIME-type and size conventions
  the extension defines; associate templates with tools through tool metadata
  so hosts can prefetch, cache, and security-review them ahead of execution.
- Handle UI-originated messages as the ordinary tool calls they are — no
  special server path.
- First-class `McpServer` helper (working name `->ui(...)`) bundling those
  conventions, so an app-enabled server stays a few lines of PHP.
- Graceful degradation where the host cannot display the UI: the tool must
  still function, and nothing fatals (guiding principle #3).

**Research focus:** the `2026-01-26` extension text in full — exact MIME
type, template metadata keys, size bounds, capability/extension negotiation,
and host↔UI message envelope; whether a newer stable revision has shipped;
what the official conformance suite covers for Apps; the ext-apps TypeScript
server package as the behavioral reference.

**Completion criteria**

- An example Apps server built only on the public `->ui(...)` API renders in
  at least one real host that supports MCP Apps (e.g. Claude or VS Code),
  verified manually as part of the milestone.
- Unit coverage for template registration, metadata emission, and the
  degraded (non-rendering-host) path.
- Apps conformance scenarios (if published) pass or are honestly baselined;
  the extension declaration is correct per SEP-2133.
- Works on the shared-hosting profile: plain resource emission over standard
  HTTP, validated again in WS8.
- `composer check` green; `composer conformance` regression-free.

**Status (2026-06-28):** implemented, verified, and code-reviewed (step 3);
Research confirmed the prior notes against the ext-apps stable revision with
**no drift**: extension id `io.modelcontextprotocol/ui`; UI template MIME
`text/html;profile=mcp-app` (exact casing); tool→UI link
`_meta.ui.resourceUri` with optional `_meta.ui.visibility` (`["model","app"]`,
default both) and the deprecated flat `_meta["ui/resourceUri"]` (the reference
ext-apps server SDK dual-writes both keys, so the SDK does too for host
back-compat); capability value `extensions["io.modelcontextprotocol/ui"] = {
mimeTypes: [...] }`. New findings folded into the implementation: (a) the spec
sets **no** size bounds on template resources (host-defined; non-normative
resource-limit guidance only); (b) the server's role is purely capability +
`_meta` plumbing — the extension adds **no** new RPC method and the host↔iframe
`ui/*` postMessage envelope never reaches the server, so UI-originated actions
arrive as ordinary `tools/call`; (c) `specification/` has only `2026-01-26`
and `draft` — no newer stable revision shipped, and the draft adds
app-provided tools / sampling / file-download / view-initiated teardown plus a
dual-location `_meta.ui` precedence rule (content wins), none adopted here;
(d) the official conformance suite has **zero** Apps/`ui` scenarios, so the
milestone is covered by unit tests and a manually-verifiable example rather
than a conformance gate. Delivered: `Mcp\Types\ExtensionIds::UI`,
`McpServer::UI_MIME_TYPE`, the generic `Server::declareExtension()` (Apps adds
no handler to key capabilities off), and the first-class
`McpServer::ui(tool, uri, name, html, …)` helper — registering the `ui://`
template resource, linking the tool's `_meta.ui` (current + deprecated keys +
validated `visibility`), emitting validated resource-level `_meta.ui`
(`csp`/`permissions` as empty objects/`domain`/`prefersBorder`) on both the
read content (stable) and the listed resource (draft), and declaring the
extension in `initialize`/`server/discover`. Graceful degradation is by
construction (the linked tool keeps returning ordinary `content`; `_meta.ui`
is additive). A latent bug was fixed in passing:
`ResourceContents::jsonSerialize()` leaked the trait's `extraFields` storage
as a literal wire key, which blocked `_meta` on read content;
`ExtraFieldsTrait` gained typed `setExtraField()`/`getExtraField()` accessors.
Unit coverage in `tests/Server/AppsExtensionTest.php` (template registration,
metadata emission, capability/discover declaration, visibility/csp/permission
validation, and the degraded non-UI-host path); a runnable example built only
on `->ui(...)` in `examples/apps_server/` (server + `dashboard.html` view
implementing the host↔view handshake + README). Verification: `composer check`
green (1192 tests; PHPStan clean); both stable conformance baselines stay
empty and the draft track is regression-free (no Apps scenarios upstream).
The completion criterion of rendering in a real MCP-Apps host (Claude /
VS Code) is the human's manual milestone step and is not yet performed.

## WS6 — Backward compatibility (continuous)

The additive, version-negotiated strategy means nothing in WS1–WS5 may break
a legacy client or server. This workstream is the standing enforcement of
that promise plus the spec's own deprecation bookkeeping.

**Scope**

- Maintain a regression test matrix across all supported revisions
  (`2024-11-05`, `2025-03-26`, `2025-06-18`, `2025-11-25`, `2026-07-28`):
  handshake, session header, SSE resumption, and error-code behavior stay
  era-correct on every path.
- Backward shaping via `ServerSession::adaptResponseForClient()` extended
  for any new result fields (e.g. `ttlMs` / `cacheScope` stripped for legacy
  clients if the spec requires).
- **SEP-2596 / SEP-2577:** adopt the feature-lifecycle states (Active /
  Deprecated / Removed, 12-month minimum) for Roots, Sampling, and Logging —
  **deprecated, not removed**; methods keep working with deprecation
  annotations surfaced per spec.
- Decide and document the v1→v2 PHP API surface changes (what breaks at the
  Composer/API level, distinct from the wire level) feeding WS10's migration
  guide.

**Research focus:** the final deprecation-annotation mechanism (how a server
marks a feature deprecated on the wire); any spec text on serving mixed-era
traffic concurrently from one endpoint.
**Added by WS1 re-review:** three items for this workstream from the
post-commit review of the WS1 change set: (a)
`ServerSession::adaptResponseForClient()` mutates the handler's `Result`
in place on the legacy path (nulls `resultType`, clears cache hints) — the
mixed-era same-server test should cover a handler-cached `Result` reused
across eras, or the adaptation should clone before stripping; (b) the
v1→v2 API audit must record the SEP-2106 behavior change in `McpServer`:
with an `outputSchema` declared, a string return now produces JSON-encoded
`TextContent` (`"hello"` with quotes) plus `structuredContent`, where v1
emitted the raw string and no `structuredContent` — wire-visible to legacy
clients of such tools, so it belongs in WS10's migration guide; (c) WS1
forward-declared `MetaKeys::LOG_LEVEL`
(`io.modelcontextprotocol/logLevel`, SEP-2577) — re-verify the key and its
deprecation semantics against the final text when adopting the
feature-lifecycle states; WS2's research found SEP-2577 already deprecates
the Roots, Sampling, and Logging features wholesale upstream, so treat
the `logLevel` key as deprecated-at-birth.
**Added by WS2 (2026-06-12):** the v1→v2 API audit must record three
behavior changes shipped with WS2: (a) JSON-RPC error responses on
the HTTP transport now surface as typed `Mcp\Shared\McpError` (code/data
intact) where v1 threw `RuntimeException("Critical MCP error: …")` —
v1 code catching `RuntimeException` around HTTP tool calls needs
updating; (b) a configured client `readTimeout` now also fires against a
peer that sends nothing at all (previously it only fired between
messages), so very slow legacy servers that relied on the dead timeout
may need a larger explicit `readTimeout`; (c) `McpServer` tool handlers
that deliberately throw `Mcp\Shared\McpError` now produce a JSON-RPC
protocol error (matching the long-standing `McpServerException`
behavior) instead of an `isError` tool result — handlers wanting a tool
execution error should throw any other exception type. Also:
`Client::connect()` now probes `server/discover` before falling back to
`initialize` by default (`protocolMode: 'auto'`) — operators of fragile
legacy servers that mishandle unknown pre-initialize requests can pin
`protocolMode: 'legacy'`.
**Added by WS2 post-commit review (2026-06-12):** a fourth candidate for
the v1→v2 API audit: `HttpServerTransport::start()` is now idempotent —
it previously threw `RuntimeException('Transport already started')` on a
second call, and now silently returns (required by the per-request
ephemeral sessions of the 2026-07-28 sessionless lifecycle, which call
`start()` on the same long-lived transport). Decide during the audit
whether the old throw counts as supported v1 surface; if so, record the
change in WS10's migration guide.
**Added by WS3 (2026-06-12):** further v1→v2 audit items, all shipped with
WS3: (a) `HttpServerSession::toArray()` now deep-normalizes
`clientParams` to plain arrays — a behavior FIX for in-memory session
stores, which previously dropped declared client capabilities (e.g.
`elicitation: {}`) between requests; cover the fixed path in the
cross-revision matrix. (b) On modern HTTP clients, `listTools()` results
EXCLUDE tools whose `x-mcp-header` annotations violate the SEP-2243
constraints, and `callTool()` on such a tool throws before any wire
traffic (spec MUST; legacy and stdio results are unfiltered). (c) Typed
exceptions replaced message sniffing: `Types/ClientRequest` throws
`Mcp\Shared\UnknownMethodException` (subclass of
`InvalidArgumentException`) and client read timeouts throw
`Mcp\Client\Transport\ReadTimeoutException` (subclass of
`RuntimeException`) — messages unchanged, so string-matching v1 code
keeps working, but document the typed forms. (d) Additive client surface
to document: `negotiate(mode, probeTimeout, preferredVersion)`,
`protocolMode: 'modern'`, the `protocolVersion` HTTP option,
`onSampling()`, `JsonRpcMessage::$httpHeaderHints`. (e) A legacy-era
HTTP `prompts/get` whose callback declares an `ElicitationContext` now
fails with the session's BadMethodCallException (-32603) instead of
silently lacking injection — prompt-side input gathering is modern-only
by design (the legacy suspend/resume store is tools-only).

**Status (2026-07-01, deprecation-lifecycle milestone):** the SEP-2596 /
SEP-2577 scope item is implemented, verified, and code-reviewed (step 3);
Human approved and committed. Research findings, applied per "official
text wins": (1) there is **no wire-level deprecation annotation** — SEP-2596
explicitly scopes a wire signal out (an Open Question), so "annotations
surfaced per spec" means schema/docs `@deprecated` markers plus two SDK
obligations: language-native API deprecation marking, and a SHOULD-level
runtime warning when a deprecated feature is exercised (SEP-2596) or a
deprecated capability is negotiated (SEP-2577) — "wire-level behavior is
unchanged. No types are removed, no capability negotiation changes."
(2) The deprecated-features registry (`docs/specification/draft/deprecated.mdx`)
holds six rows, not three: Roots/Sampling/Logging (SEP-2577, `2026-07-28`),
Dynamic Client Registration (spec PR #2858, `2026-07-28`, migrate to CIMD),
the `includeContext: "thisServer"|"allServers"` sampling values (SEP-2596
transition provisions, `2025-11-25` — servers SHOULD omit or use `"none"`,
and SHOULD only send the deprecated values to a client declaring
`sampling.context`), and the 2024-11-05 HTTP+SSE transport (`2025-03-26`;
N/A — the SDK v2 implements Streamable HTTP only). (3) WS1's
forward-declared `MetaKeys::LOG_LEVEL` re-verified against the draft
schema: official (`RequestMetaObject`, optional `LoggingLevel`),
deprecated-at-birth by SEP-2577 as WS2's research predicted — WS1 item (c)
closed. Delivered: `Mcp\Shared\FeatureLifecycle` (the registry mirrored as
code: states, deprecating revisions, migration paths, warning messages) and
the `EmitsDeprecationWarnings` trait on both sessions — one PSR-3 warning
per feature per session, gated on the negotiated revision having the
feature Deprecated (a 2025-11-25 session exercising Sampling is exercising
an Active feature and stays silent; the RC-window draft alias
canonicalizes). Exercise points wired: server `sendLogMessage()`, the
`logLevel` `_meta` opt-in, `sendSamplingRequest()`,
`SamplingContext::createMessage()`, `InputContext::wantSample()/wantRoots()`,
the deprecated `includeContext` values (both sampling senders); client
`setLoggingLevel()`, `sendRootsListChanged()`, and the MRTR servicing of
sampling/roots input requests; auth `DynamicClientRegistration::register()`
(no negotiated revision to gate on — the warning states the deprecating
revision). `@deprecated` docblocks added to the 20 roots/sampling/logging
`Types/` classes (including `ToolUseContent`/`ToolResultContent`, added in
the step-3 review round), the deprecated capability slots themselves
(`ClientCapabilities::$roots`/`$sampling`, `ServerCapabilities::$logging`
as promoted-property docblocks, matching the schema's member-level markers
— second review round; the schema's deprecated members are exactly those
three plus the `logLevel` meta key), the `MetaKeys::LOG_LEVEL` constant,
and the feature APIs (mirroring the schema's annotation wording; PHPStan
has no deprecation-rules extension, so internal use stays build-clean).
A third review round completed the value-level `includeContext`
deprecation (2025-11-25, SEP-2596 — distinct from Sampling's 2026-07-28):
annotated on `CreateMessageRequest::$includeContext` (promoted-property
docblock), the `RequestParams` `@property` line, and
`SamplingContext::createMessage()`'s `@param`; and the
`HttpServerSession::sendSamplingRequest()` override (which throws
unconditionally on HTTP) now carries the same `@deprecated` tag as the
base method — PHPDoc is not inherited by overrides. Wire behavior deliberately untouched — features keep working
through the twelve-month window. Coverage:
`tests/Shared/FeatureLifecycleTest.php`,
`tests/Server/ServerDeprecationWarningsTest.php`,
`tests/Client/ClientDeprecationWarningsTest.php`,
`tests/Client/Auth/DcrDeprecationWarningTest.php`. The negotiation-time
warning for capability declarations rides the exercise points rather than
per-request envelope adoption — on the stateless path every request is a
negotiation, and warning there would repeat the message on every request
carrying the capability (documented judgment call; the SHOULD is honored
at first exercise per session).

**Status (2026-07-01, matrix/mixed-era/audit milestone):** the remaining
WS6 deliverables are implemented, verified, and code-reviewed (step 3);
Human approved and committed. Research: the draft versioning page's
dual-era text was re-verified — "A dual-era server selects its behavior
from how the client opens" (modern per-request `_meta` → stateless; an
`initialize` request → legacy semantics scoped to the process/session) and
"A dual-era server MAY serve both eras concurrently on the same endpoint
or process" — no drift from WS2's implementation. Delivered:
(1) `tests/Server/CrossRevisionMatrixTest.php` — the consolidated
cross-revision matrix over the real `McpServer` surface on the HTTP
runner, per revision (`2024-11-05` → `2025-11-25` legacy columns plus the
`2026-07-28` modern column): handshake echo, session-header
minting/echoing/requirement, SEP-2549 stamping-vs-absence, SEP-2164
`-32002` (HTTP 200) vs `-32602`+`data.uri` (HTTP 400), and a REAL
`Last-Event-ID` resumption round-trip (progress-token SSE response →
replay from its first event id) on every legacy revision, with the modern
column asserting no handshake (404/-32601), no session header, and no
resumable channel. Runs in `composer test` (CI). (2) The mixed-era
one-instance criterion: `testMixedEraTrafficOnOneRunner` and the reverse
interleaving already existed (WS2); the missing piece — a handler-CACHED
`Result` reused across eras — was added
(`testHandlerCachedResultSurvivesCrossEraAdaptation`) and drove the WS1
re-review fix: `ServerSession::adaptResponseForClient()` now adapts a
shallow CLONE, never the handler's instance (red-first verified: without
the clone, a legacy response stripped the cached instance's
`ttlMs`/`cacheScope` and later modern clients received re-stamped
conservative defaults; modern stamping likewise no longer leaks SDK
defaults into handler state). (3) The v1→v2 API audit document,
[docs/api-audit-v2.md](api-audit-v2.md) — the scattered WS1–WS4 audit
items consolidated into Breaking (B1–B8) / Behavioral (M1–M9) / Additive /
wire-automatic sections with explicit dispositions (notably:
`HttpServerTransport::start()` idempotency recorded as behavioral, not
breaking), feeding WS10's migration guide. A latent parse bug surfaced by
the matrix was fixed: `ClientRequest::createInitializeRequest()` passed
raw wire `_meta` (a decoded array) into the typed `?Meta` parameter, so
any `initialize` carrying `_meta` — SEP-414 trace context, or a
modern-enveloped probe hitting the removed method — crashed with a
TypeError instead of being answered per era; it now converts via the same
`extractMeta()` helper as every other request family. Still open in WS6
(continuous duties): the standing no-legacy-regression watch (measured at
every milestone) and any future deprecation-registry additions.

**Completion criteria**

- The cross-revision matrix runs in CI as part of `composer test` and is
  green for every revision.
- A `2025-11-25` client and a `2026-07-28` client exercising the same server
  instance in one test both work, with era-correct behavior asserted.
- Deprecated features carry the spec's annotations under `2026-07-28` and
  behave identically under legacy revisions.
- No legacy conformance scenario regresses at any point in v2 development
  (checked at every milestone, gated at G2).

## WS7 — Conformance (continuous)

Conformance is the measure of done for the spec work. Target: **100% of
applicable required tests** on the `2026-07-28` suite, inside the RC-to-final
window, with no shortcuts (guiding principle #2).

**Scope**

- **Dual-track tooling during the RC window.** The official tool publishes
  `2026-07-28` draft-spec scenarios on its `0.2.0-alpha` line (npm `alpha`
  dist-tag; started the day after the RC locked, and the line the official
  TypeScript SDK v2 runs in CI), while `latest` stays on the stable `0.1.x`
  line. The SDK pins both in [`package.json`](../package.json): the stable
  pin is the legacy regression gate (`composer conformance`,
  `conformance/conformance-baseline.yml`), and the alpha pin — installed
  under the npm alias `conformance-draft` — runs the `draft` suite
  (`composer conformance-draft`) against its own
  `conformance/conformance-draft-baseline.yml`. Separate baselines mean
  alpha-line churn re-curates only the draft baseline, never the stable
  gate.
- Bump the draft pin deliberately at milestone boundaries (each baseline
  file is tied to its installed tool version), re-curating the draft
  baseline in the same change set; draft entries name the workstream that
  will make them pass and only shrink as workstreams complete.
- **Converge at stable `0.2.0`.** When the tool's stable release covering
  `2026-07-28` ships (expected around the final spec), collapse back to a
  single pin and a single baseline; the draft alias, draft baseline, and
  draft composer scripts retire in that change set.
- Expand `conformance/everything-server.php` and
  `conformance/everything-client.php` to exercise the new surface: stateless
  negotiation, `server/discover`, metadata headers, `subscriptions/listen`,
  multi-round-trip exchanges, Tasks, and Apps.
- Curate both baselines: every remaining entry has a root cause and either a
  plan or an explicit not-pursuing rationale; entries only shrink.
- Note SEP-2484: Standards-Track SEPs must ship matching conformance
  scenarios — where a scenario is missing upstream, an honest gap report
  (or upstream contribution) beats a private workaround.

**Status (2026-06-11):** the dual-track scaffolding is in place — both pins
installed (`0.1.16` stable, `0.2.0-alpha.2` draft), `composer
conformance-draft` and a CI draft job wired up, and the initial draft
baseline populated from a real run (all `2026-07-28` draft scenarios
annotated with the workstream that will close them). Both tracks verified
green against their baselines.
**Update (2026-06-12, WS3):** the stable track now passes 100% of its
scenarios — both stable baseline lists are empty (WS3's auth hardening
closed the last three client entries). The draft baseline is down to a
single entry: the documented upstream tool bug in
`sep-2575-server-rejects-undeclared-capability` (string-array
`requiredCapabilities` assertion contradicting the draft schema), to be
re-checked at every draft-pin bump and a candidate for an upstream
issue/PR per SEP-2484. `run-conformance.php` now sets
`PHP_CLI_SERVER_WORKERS` (POSIX) so the concurrent-stream scenarios
(`subscriptions/listen`, `server-sse-multiple-streams`) exercise real
parallelism.
**Update (2026-06-27, draft pin `0.2.0-alpha.2` → `0.2.0-alpha.7`):** the
SDK adopted the SEP-2907 draft error-code renumber (HeaderMismatch
`-32001`→`-32020`, MissingRequiredClientCapability `-32003`→`-32021`,
UnsupportedProtocolVersion `-32004`→`-32022`; the tool adopted them in
alpha.5 #353), and the draft pin was bumped to the latest published alpha
and the baseline re-curated from a real run. Server track: `server-stateless`
is 26/27 (alpha.4 #343 added HTTP-400-on-invalid-`_meta` and the
`data.requested` echo, both already satisfied), the single failure still the
`requiredCapabilities` string-array upstream tool bug — now at `-32021` and
now contradicting the tool's OWN vendored draft schema and its new SEP-2663
Tasks scenario (both object-shaped), strengthening the SEP-2484 upstream
case. Client track gained two NEW upstream-drift entries, both spec-correct
on the SDK side and unavoidable on any renumber-bearing alpha (the renumber
#353, the `DRAFT-2026-v1` retirement #331, and the discover-on-mock change
#347 all landed by alpha.5): `json-schema-ref-no-deref` (mock advertises
`2026-07-28` in discover but its TS-SDK stateful transport rejects it) and
`request-metadata` (first-rejection retry test now advertises the same
version the client just attempted; the SDK's infinite-loop guard declines to
re-send it). `http-custom-headers` stays an expected failure until alpha.8
(upstream fix #371 merged after the alpha.7 tag); `auth/pre-registration`
(issuer binding) unchanged. Whether the SDK should retry an
already-attempted-but-advertised version once (the `request-metadata`
question) is flagged for human/upstream review, not changed silently.
**Update (2026-07-03, draft baseline 5 → 4):** the `request-metadata`
question above is RESOLVED — yes, retry once. Verified against three
sources: the draft's retry rule is an unconditional select-and-continue
SHOULD with no already-attempted exclusion anywhere in the text; the
reference TypeScript client's corrective continuation "runs exactly once
(even when the mutual version equals the just-rejected one)"
(`versionNegotiation.ts`, one-shot guard) and the Python client matches
(`_probe.py`, `attempt == 0`); and conformance issue #280 documents the
same-version transient rejection as the scenario's intended design.
`ClientSession::negotiate()` now performs exactly one corrective `-32022`
retry (same version permitted) and throws on a second rejection;
`pickAdvertisedModernVersion()` dropped its attempted-versions exclusion
(`sendRequest()`'s adopt-and-retry was already structurally once-only).
Covered by three new `ClientNegotiationTest` tests; `composer check`
green; `request-metadata` now passes 4/4 and left the draft baseline;
stable track re-verified regression-free (40 server + 325 client). The
remaining four draft-baseline entries' root causes were re-verified the
same day against the reference SDKs, the draft spec text, and the tool
source, and their baseline comments rewritten to stand alone — including
the new finding that the `server-stateless` fixture contract for
`test_streaming_elicitation` appears to be non-eliciting upstream (the
referee's own reference server and the TS SDK fixture both stream without
eliciting). That finding identifies a possible fixture-alignment exit
path for the entry, but whether to take it — versus the
capability-declaration fix proposed in upstream PR #383 / issue #382
(filed 2026-07-02) — is deliberately held for the maintainers' answer:
the fixture contract is undocumented upstream, and reference SDKs
matching each other does not settle it. The same posture applies to the
`json-schema-ref-no-deref` harness accommodation used by the reference
SDKs (a per-scenario legacy pin): candidate remedy, not adopted without
upstream clarification. Only the `request-metadata` SDK fix — grounded
in normative spec text — was applied.

**Completion criteria**

- `composer conformance` passes 100% of applicable required tests on the
  newest pinned suite covering `2026-07-28`, with the baseline containing
  only documented optional-extension entries.
- Legacy-revision scenarios still pass on the same run (WS6's promise,
  measured here).
- A final clean run happens against the suite version current at the final
  spec's publication — the Tier-1 expectation this whole plan serves.

## WS8 — Shared-hosting validation

Guiding principle #3 as a workstream: prove v2 on a standard
cPanel/Apache/PHP shared-hosting environment, not just assert it.

**Scope**

- Validate the full modern path on a real or faithfully-simulated
  cPanel/Apache/FPM host using `NativePhpIo`: stateless requests,
  `server/discover`, request-scoped SSE, `subscriptions/listen` (with the
  documented long-lived-stream caveats), Tasks (file-based store), and Apps
  resource emission.
- Verify the request-metadata headers (`Mcp-Method`, `Mcp-Name`,
  `MCP-Protocol-Version`, `Mcp-Param-*`) survive `.htaccess` and typical
  proxy configurations; document required `.htaccess` rules alongside the
  existing `Authorization` forwarding guidance.
- Confirm graceful degradation everywhere the environment can't deliver:
  long-lived streams cut by host timeouts, Apps on non-rendering hosts,
  missing optional PHP extensions.
- Update [docs/compatibility.md](compatibility.md) with the v2 findings —
  it is the canonical record of what shared hosting can and cannot do.

**Dependencies:** WS3 (headers, streams) and WS5 (Apps emission) complete;
WS4 useful but its file-based store is already hosting-proven.

**Completion criteria**

- A written validation report (kept under `docs/`, linked from
  compatibility.md) covering each feature on the hosting profile:
  works / works-with-config / degrades-gracefully, with the config spelled
  out.
- Every "degrades gracefully" claim backed by an automated test using
  `BufferedIo` or an equivalent harness where the failure mode can be
  simulated.
- No core feature (tools, prompts, resources, `server/discover`) requires
  anything beyond a stock cPanel/Apache/PHP account.

## WS9 — Examples and webclient

Working code is the SDK's primary documentation surface; all of it must
demonstrate v2.

**Scope**

- Update the existing example programs and the `webclient/` reference
  implementation to v2 APIs and the `2026-07-28` defaults.
- Add new examples: a minimal stateless server, dual-era negotiation from
  the client side, a Tasks-extension server, and an MCP Apps server using
  `->ui(...)`.
- Keep the `McpServer` quick-start promise: the few-lines example in the
  README/AGENTS.md must still run verbatim on v2.

**Completion criteria**

- Every example runs against the v2 SDK (`php -l` clean for webclient files,
  executed end-to-end for runnable examples — at minimum via the Inspector
  smoke-test flow in [docs/testing.md](testing.md)).
- Each new v2 feature has at least one example demonstrating it.
- Examples referenced from documentation actually exist at the referenced
  paths.

**Status (2026-07-04):** implemented and verified (steps 1–2); code review
(step 3) pending. Research found zero upstream drift since the 2026-07-01
assessment (no normative spec/ext-tasks/ext-apps merges after 2026-07-01;
spec PR #2972 was already absorbed by WS3's drift round). Delivered: an
`examples/README.md` index organizing the directory (existing paths kept
stable — every documentation-referenced path still exists); four new-feature
example sets — `stateless_server.php` (the 2026-07-28 stateless model, with
structured output, resources, a resource template, and a prompt),
`client_negotiation.php` (dual-era negotiation with
`--mode=auto|modern|legacy`), `tasks_server.php`/`tasks_client.php`
(SEP-2663 end-to-end: extension declaration, `CreateTaskResult` handle,
`tasks/get` polling, in-task input via `input_required` → `tasks/update`),
and `elicitation_server.php`/`elicitation_client.php` (SEP-2322 MRTR through
`ElicitationContext`/`onElicit`); the Apps example (WS5) already existed and
was re-verified end-to-end (`ui://` resource, `mcp-app` MIME profile,
`structuredContent`). Existing examples: the three `simple_server*.php`
files were already v2-clean and are unchanged — the README and AGENTS.md
quick-start snippets were extracted verbatim and executed against the SDK
(both run, negotiating the modern era); `client_http.php` gained
negotiated-era reporting, an explicit-nullable logger parameter, and
CWD-relative autoloading consistent with the other examples;
`server_auth/server_auth.php` was modernized from the v1 low-level
`Server`+`registerHandler()` pattern onto the fluent `McpServer` API with
`withAuth()` (filename preserved — its README, config, and `.htaccess`
rules reference it), its `test-client.html` initialize probe bumped from
`2025-03-26` to `2025-11-25`, and its README aligned (RS256 file default
noted). The webclient audited as already v2-current (WS3 auth +
modern-resume work; no hardcoded protocol versions) — no changes needed;
all webclient and example files are `php -l` clean. Every runnable example
was executed end-to-end over BOTH stdio and HTTP: auto/modern/legacy
negotiation, task polling with in-task input, MRTR elicitation, and the
OAuth server (metadata endpoint, 401-without-token, 404 path gating, plus
authenticated legacy `initialize` and modern discover/list flows against an
HS256 validator). One behavioral note is captured in the tasks example: a
server tool may only elicit from clients advertising the elicitation
capability, so `tasks_client.php` registers an `onElicit` handler even
though in-task input arrives via `tasks/get`. `composer check` green (1237
tests; PHPStan clean). Conformance was deliberately not re-run: the change
set touches only `examples/` (zero `src/` or `conformance/` changes), so
neither track's fixtures load any changed code.
**Review round (step 3, 2026-07-04):** two findings raised; both verified
as legitimate and fixed. (1) `server_auth/test-client.html` exercised only
the legacy handshake path — it now offers both eras side by side: modern
"Discover (2026-07-28)" and "Tools List (2026-07-28)" buttons send
stateless requests carrying the SEP-2575 `_meta` envelope
(protocolVersion/clientInfo/clientCapabilities) with the
`MCP-Protocol-Version` and SEP-2243 `Mcp-Method` headers and never send
`Mcp-Session-Id`, while the legacy buttons are labeled as such and keep
the initialize→session flow; the server_auth README's testing walkthrough
now describes both paths. Verified live against the served example with an
HS256 validator: modern discover returns the full DiscoverResult
(resultType, supportedVersions, ttlMs/cacheScope), modern stateless
tools/list works with no session, unauthenticated modern requests get 401,
and the legacy initialize→tools/list flow is unchanged (no `resultType` on
the legacy-adapted result). (2) `server_auth/server_auth.php` required
`__DIR__ . '/vendor/autoload.php'` (deployment layout) and fataled when
run from the repository root as `examples/README.md` instructs — it now
falls back to the repo-root `vendor/` when no local `vendor/` exists,
verified by serving it from the checkout with no vendor junction present.
`php -l` clean on the changed files.

## WS10 — Documentation

The last gate: the docs describe what v2 actually is.

**Scope**

- Update [README](../README.md), [docs/server-dev.md](server-dev.md),
  [docs/client-dev.md](client-dev.md), [docs/testing.md](testing.md), and
  [AGENTS.md](../AGENTS.md) (architecture notes, negotiation description,
  capability tables) for the v2 surface.
- Write a v1→v2 **migration guide** (`docs/migration-v2.md`): wire-level
  changes (handled automatically by negotiation) versus PHP API changes
  (requiring user action), with before/after snippets that are functional,
  not illustrative.
- [CHANGELOG.md](../CHANGELOG.md) entries for each released pre-release and
  the final `v2.0.0`; roadmap refresh moving shipped items out of the
  "working on" section.

**Completion criteria**

- No documentation references a removed or renamed API; code snippets are
  runnable as written.
- The migration guide covers every breaking change identified in WS6's API
  audit.
- README v2 notice replaced with real v2 documentation at release.

---

## Release gates

Gates are human decisions. A gate is passed when its criteria are met, the
constituent milestones have each completed the four-step process (including
human review), and the human maintainer approves and commits/tags. Pre-release
tags below are intentions, not promises (roadmap preamble applies).

**G1 — Stateless core proven (target: `v2.0.0-alpha`).**
WS1–WS3 complete. The four-way era matrix passes; `composer check` green;
conformance regression-free against the then-current suite; legacy scenarios
untouched.

**G2 — Feature complete (target: `v2.0.0-beta`).**
WS4–WS6 complete. Tasks and Apps work end-to-end; the cross-revision
regression matrix is in CI and green; deprecation annotations in place. From
this point the public v2 API only changes in response to review findings,
spec drift, or conformance failures.

**G3 — Validated (target: `v2.0.0-RC`).**
WS7–WS8 complete. 100% of applicable required conformance tests on the
newest `2026-07-28` suite with an honest, shrinking baseline; the
shared-hosting validation report is written and every core feature works on
the stock hosting profile.

**G4 — Release (`v2.0.0`).**
WS9–WS10 complete; the final `2026-07-28` spec is published and a clean
conformance run has been made against the suite version current at
publication. The human maintainer tags the release.

If the final spec lands changes after G3, the affected workstreams re-open as
new milestones (research → implement → review → approve) before G4 — the
gates are checkpoints, not a ratchet that forbids going back.

## Maintaining this plan

- This plan is amended through the same process as code: an agent researches
  and drafts the change, a human reviews, approves, and commits. Material
  scope changes are also announced in [CHANGELOG.md](../CHANGELOG.md), per
  the roadmap's revision-history policy.
- Each milestone's research step (step 1) is the standing mechanism for
  catching spec drift: when the official text has moved away from this plan,
  the milestone updates the plan in the same change set as the code.
- When a workstream completes, its section gains a short **Status** line
  (date, gate, anything intentionally descoped) rather than being deleted —
  the plan doubles as the v2 development record.
