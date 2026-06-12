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
  `UnsupportedProtocolVersionError` is `-32004` with
  `data: {supported: string[], requested: string}`; missing-capability is
  `-32003` with `data.requiredCapabilities`. Nothing replaces
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
version-header gate so an unsupported version yields the spec's `-32004`
with the original request id and `data.supported`/`data.requested`, and the
sessionless path maps the modern error codes (`-32602`/`-32003`/`-32004`)
onto HTTP 400 as SEP-2575 mandates — discover responses are deliberately
never SSE-framed, even on SSE-enabled servers, because the result is a
single self-contained cacheable document and only a plain JSON response
can carry those statuses (the status mapping for the *general*
per-request modern path — including the removed-method 404s — lands with
WS2's era detection; header/`_meta` mismatch `-32001` remains WS3); legacy clients now also get JSON-array
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
`-32004`, the `resultType`/`ttlMs`/`cacheScope` result surface with
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

## WS3 — Transport changes

The HTTP-layer and streaming changes of the stateless revision, plus the
authorization-hardening SEPs (which ride in this workstream because they live
in the same transport/auth layer).

**Scope**

- **SEP-2243:** emit and validate the request-metadata headers — `Mcp-Method`
  on all requests and notifications; `Mcp-Name` on the name/uri-bearing
  methods (`tools/call`, `resources/read`, `prompts/get`; reused by Tasks for
  the task id). Mismatch with the body, or a missing required header, is
  rejected `400` with the `HeaderMismatch` / `-32001` error — which also
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

**Completion criteria**

- Header emission/validation covered by unit tests on both runner and client
  transport, including every `-32001` rejection case.
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

## WS4 — Tasks extension

The SEP-2663 stateless redesign of the experimental Tasks surface. Breaking
redesign, no deprecation shims — the existing surface is pre-release
(per the project decision recorded in the roadmap).

**Scope**

- Redesign to `tasks/get` / `tasks/update` / `tasks/cancel` with the task
  handle returned from `tools/call`; **remove `tasks/list`** (unscopeable
  without sessions) from the `2026-07-28` path and the client convenience API.
- Keep the file-based `TaskManager` store (shared-hosting compatibility);
  rework state transitions and TTL/expiry to the SEP-2663 model.
- `Mcp-Name` carries the task id on task methods (from WS3).
- Close the task-augmented gaps: carry the `task` parameter through
  `ElicitationContext::form()` / `::url()` (currently nulled with an in-code
  comment) and expose `task` on `SamplingContext::createMessage()` — if and
  only if the final extension text settles the wire format.
- Declare Tasks per the SEP-2133 extensions framework (reverse-DNS id,
  independent versioning).

**Research focus:** the final SEP-2663 method set and task-handle schema
(the RC moved this surface substantially and it may move again); the
extension's declared id/version string; whether task-augmented
elicitation/sampling is in the stable extension text or still draft.

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
feature-lifecycle states.

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
