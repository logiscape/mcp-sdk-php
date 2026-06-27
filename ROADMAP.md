# Roadmap

This roadmap describes where `logiscape/mcp-sdk-php` stands today, what we are
working on next, and the conditions under which we would seek a higher tier
position in the
[MCP SDK tiering system](https://modelcontextprotocol.io/community/sdk-tiers)
([SEP-1730](https://modelcontextprotocol.io/community/seps/1730-sdks-tiering-system)).

The goal is to be honest about scope and pace. Nothing here is a promise of a
delivery date — targets listed are intentions, not guarantees.

## Guiding principles

These are the principles every roadmap item is judged against:

1. **Track the spec as it evolves.** We aim for full conformance with the
   latest MCP specification revision and sensible back-compat for prior
   revisions.
2. **No conformance shortcuts.** We do not engineer workarounds purely to
   green a conformance test. If a test fails honestly, it goes in the
   relevant conformance baseline file (see
   [`conformance/README.md`](conformance/README.md) — during v2 development
   there is one per track: stable and `2026-07-28` draft)
   with a root cause and a plan — or no plan, if it is genuinely an optional
   extension we are not pursuing yet.
3. **cPanel/Apache compatibility is mandatory for core MCP features.**
   Features that cannot be compatible with shared hosting still ship (for spec
   alignment) but must fail gracefully instead of crashing the SDK. See
   [`docs/compatibility.md`](docs/compatibility.md).
4. **Avoid breaking changes where we can.** On the `1.x` line, when breaking
   the public API or documented flows is genuinely necessary, we bump the
   minor version (`v1.X`), not the patch, and we document the change in
   [CHANGELOG.md](CHANGELOG.md). The `v2` major now in development (see below)
   exists precisely to absorb the breaking `2026-07-28` protocol revision in
   one place rather than dribbling breaks into `v1`.

## Current tier position (self-assessment)

Measured against the SEP-1730 criteria, this is where we stand. This is a
self-assessment, not an official tier assignment — only the MCP SDK Working
Group assigns tiers.

| SEP-1730 criterion          | Target (Tier 1)          | Current state                                                                                                                          |
| --------------------------- | ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| Conformance pass rate       | 100%                     | **100%** of applicable required tests on suite `v0.1.16`. Three known failures, all in optional MCP Extensions (documented in baseline). |
| New protocol features       | Before spec release      | `2025-11-25` supported; back-compat for `2024-11-05`, `2025-03-26`, `2025-06-18`. Day-one support for the `2026-07-28` revision is the focus of `v2` development on `main` (see below).        |
| Issue triage                | 2 business days          | Best-effort; see response-time section below.                                                                         |
| Critical bug resolution     | 7 days                   | Best-effort, typically weeks not days for non-trivial fixes.                                                          |
| Stable release              | Required, clear versioning | Met. Latest stable is `v1.7.3` on the [`1.x` branch](https://github.com/logiscape/mcp-sdk-php/tree/1.x); `main` carries the pre-alpha `v2`. Semver-tagged since `v1.0.0`.       |
| Documentation               | Comprehensive w/ examples | Largely met. Covered by [README](README.md), [server-dev](docs/server-dev.md), [testing](docs/testing.md), [compatibility](docs/compatibility.md), plus 10 example programs and an example web client. |
| Dependency update policy    | Published                | Met ([`docs/dependency-policy.md`](docs/dependency-policy.md)).                                                                        |
| Roadmap                     | Published                | Met — this document.                                                                                                                   |

On **technical** criteria we are comfortably at Tier 1 shape. On **maintenance
response-time** criteria, we cannot guarantee these specific timeframes — and
we would rather be open about that than set expectations we cannot meet.

### Why we aren't claiming Tier 1 today

The SDK is currently maintained by a single core developer and a small group of
volunteer contributors (see [GOVERNANCE.md](GOVERNANCE.md)). Tier 1's
two-business-day triage and seven-day critical-bug windows are demanding, and
it would be dishonest to commit to them without a contributor base large enough
to cover holidays, sickness, and life. If that community grows — and we hope it
will — the arithmetic changes.

## What we are working on

**v2 development has begun.** The `main` branch now carries the pre-alpha `v2`
of the SDK, and the stable `v1` line lives on the
[`1.x` branch](https://github.com/logiscape/mcp-sdk-php/tree/1.x), which
continues to receive bug fixes and low-risk backports. v2 has two defining
goals: day-one support for the `2026-07-28` spec revision, and full support
for the MCP Apps extension as a release feature — both detailed below. The
guiding principles above carry into v2 unchanged. In particular, principle #3
is a v2 release requirement, not a v1 legacy: every core feature of v2 must
run in a standard cPanel/Apache/PHP web hosting environment, while we
simultaneously aim for 100% conformance with the new revision. The
`2026-07-28` stateless core makes these two goals more compatible, not less —
see the compatibility notes below.

**The main working plan for v2 development is
[`docs/v2-development-plan.md`](docs/v2-development-plan.md).** This roadmap
describes direction and rationale; the development plan describes execution —
the ordered workstreams (stateless foundation through documentation), their
dependencies and completion criteria, the research → implement → human review
→ human commit milestone process, and the release gates between today's
pre-alpha and a tagged `v2.0.0`. All commits in that process are
human-initiated.

### Near-term (next release cycle)

- **Close optional conformance gaps, where we can do so spec-faithfully** —
  **done with v2 WS3**: the `client_credentials` grant (JWT assertions and
  HTTP Basic client authentication) and the full cross-app-access flow
  shipped with the WS3 authorization hardening, so
  [`conformance/conformance-baseline.yml`](conformance/conformance-baseline.yml)
  is now empty and the stable suite passes 100% on both tracks.
- **Continue tracking the spec.** Any mid-cycle SEP that reaches "Accepted"
  status is a candidate for inclusion in a `v2` pre-release (and for backport
  to `1.x` where low-risk).
- **Inspector and real-world AI-app smoke tests as part of the contributor
  workflow** — already described in [`docs/testing.md`](docs/testing.md);
  continuing to refine what we check.
- **Expand `conformance/everything-server.php` and `everything-client.php`**
  to cover new tools, prompts, and resources as the official suite grows.

### v2 core: day-one support for the 2026-07-28 spec revision

The [`2026-07-28` Release Candidate](https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/)
(locked 2026-05-21; final spec 2026-07-28) is the largest revision since launch —
a **stateless core** that drops the connection handshake and the protocol-level
session. In line with guiding principle #1 and the SEP-1730 expectation that
Tier 1 SDKs ship support within the ten-week RC-to-final validation window, our
target is a clean conformance run inside that window. The official SDKs are on
the same trajectory and the same timeline: the TypeScript SDK's `main` is a
pre-alpha v2 whose documentation anticipates "a stable v2 release in Q3 2026
along with the updated MCP spec," and the Python SDK's `main` is likewise its
v2 development branch (its latest stable release, `v1.27.2`, still targets
`2025-11-25`). Neither has published `2026-07-28` support yet. This work is the
core of our own `v2` (see the release-vehicle note below).

**Strategy: additive and version-negotiated.** `2026-07-28` becomes a negotiable
protocol version; the new stateless code paths run **only** when a client speaks
that revision. The existing handshake-and-session paths for `2024-11-05`…
`2025-11-25` stay untouched, so no existing client or server breaks (guiding
principle #4). Mechanically this reuses the gating the SDK already has — a new
entry in `Version::SUPPORTED_PROTOCOL_VERSIONS` / `LATEST_PROTOCOL_VERSION` and
`FEATURE_VERSIONS` (`src/Shared/Version.php`), feature checks via
`ServerSession::clientSupportsFeature()` / `ClientSession::supportsFeature()`,
and backward shaping via `ServerSession::adaptResponseForClient()`. The new
wrinkle is dual-era interoperability. The spec lets a server support both eras at
once and defines how each side detects which it is talking to, so back-compat is
achievable additively in both directions:

- **Server side.** A legacy client opens with an `initialize` request; a modern
  client instead sends ordinary requests that carry the negotiated version in the
  `MCP-Protocol-Version` HTTP header with matching `_meta`. The server keys off
  that per-request version metadata (and the `initialize` method for legacy) —
  *not* the session id. Under `2026-07-28` the `Mcp-Session-Id` header is removed
  and ignored rather than used as a routing or mode signal.
- **Client side.** We follow the spec's documented detection rather than guessing.
  Over HTTP: send a modern request first and, on a `400`, inspect the body —
  because a `400` is also returned for `UnsupportedProtocolVersionError`,
  missing-capability, and header-validation failures, the status alone is not a
  legacy signal. A recognized modern JSON-RPC error (e.g.
  `UnsupportedProtocolVersionError`) means the server is modern (retry with one of
  its advertised versions; do **not** fall back), whereas an empty or
  unrecognized body means fall back to `initialize` and continue legacy. Over
  stdio (no per-request status code): probe `server/discover` with the preferred
  version in `_meta`, fall back to the legacy handshake on `Method not found`
  (`-32601`), and on `UnsupportedProtocolVersionError` use one of the server's
  advertised versions instead of falling back.

What we intend to implement (intentions, not final API shapes — the same caveat
the Medium-term section carries; several of these SEPs are still settling):

- **Stateless core.**
  - **SEP-2575** — remove the `initialize`/`initialized` handshake; carry
    protocol version, client info, and capabilities in `_meta` on every request,
    and answer the new `server/discover` method on demand (reusing
    `Server::getCapabilities()`).
  - **SEP-2567** — remove the protocol-level session: under `2026-07-28` the
    SDK stops emitting and stops honouring the `Mcp-Session-Id` header (legacy
    revisions keep it), so any request can land on any instance.
  - **SEP-2243** — read and validate the request-metadata headers so gateways can
    route without inspecting the body: `Mcp-Method` on **all** requests and
    notifications, and `Mcp-Name` only on the name/uri-bearing methods
    (`tools/call`, `resources/read`, `prompts/get`; the Tasks extension reuses it
    for the task id). A header whose value disagrees with the body, or a missing
    required header, is rejected `400` with a `HeaderMismatch` / `-32020` error
    (renumbered from `-32001` per spec PR modelcontextprotocol#2907; see the v2
    plan's WS7 update).
    (The mandatory per-request `MCP-Protocol-Version` header and its
    must-match-`_meta` rule are defined by SEP-2575 above; SEP-2243 supplies the
    `-32020` enforcement, which also covers a version-header/`_meta` mismatch.)
    Also support the `x-mcp-header` schema annotation that mirrors designated tool
    parameters into `Mcp-Param-*` headers (clients MUST emit them; whether those
    headers survive network intermediaries and shared-hosting `.htaccess` is a
    deployment concern, not something the spec obliges intermediaries to do).
  - **SEP-2549** — emit `ttlMs` / `cacheScope` on list and resource-read results
    (HTTP `Cache-Control` semantics).
  - **SEP-414** — preserve and expose W3C Trace Context fields
    (`traceparent` / `tracestate`) carried in `_meta`, per the documented
    distributed-tracing convention. The SDK's role is pass-through and
    accessor surface only — no OpenTelemetry dependency (consistent with
    [`docs/dependency-policy.md`](docs/dependency-policy.md)).
  - **SEP-2322** — the multi-round-trip request mechanism: sampling, elicitation,
    and roots become `InputRequiredResult` exchanges (`inputRequests` /
    `requestState` / `inputResponses`) instead of server-initiated requests. (Two
    related removals live in adjacent SEPs: SEP-2575 removes the standalone GET
    SSE stream, replacing it with `subscriptions/listen`, and SEP-2260 restricts
    that channel to server→client *notifications* only — no independent
    server-initiated *requests*.) Request-scoped SSE response streams
    (request-related notifications then the final response) and long-lived
    `subscriptions/listen` streams for change notifications remain; `Last-Event-ID`
    resumption does not.
  - **SEP-2106** — accept JSON Schema 2020-12 keywords (composition, conditionals,
    `$ref`) in tool schemas, with the spec's constraints: `inputSchema` still
    requires a root `type: "object"`, `outputSchema` is unrestricted, and
    `structuredContent` may be any JSON value conforming to it. **SEP-2164** —
    change the missing-resource error from `-32002` to `-32602`.
- **Authorization.** SEP-2468 (`iss` validation, RFC 9207), SEP-837
  (`application_type` on registration), SEP-2352 (credential binding), SEP-2207
  (refresh-token flow), SEP-2350 (scope accumulation), and SEP-2351
  (`.well-known` discovery). These drop into the existing `Client/Auth/` and
  `Server/Auth/` framework rather than needing a new abstraction.
- **Governance.** Adopt the SEP-2596 / SEP-2577 feature-lifecycle states (Active
  / Deprecated / Removed, 12-month minimum) for the now-deprecated Roots,
  Sampling, and Logging features — deprecated, not removed — and track the
  SEP-2484 requirement that Standards-Track SEPs ship matching conformance
  scenarios.
- **Tasks extension (SEP-2663).** A **breaking redesign** of the experimental
  Tasks primitive already in the tree: `tasks/get` / `tasks/update` /
  `tasks/cancel`, the task handle returned from `tools/call`, and **removal of
  `tasks/list`** (which cannot be scoped without sessions). Because the existing
  Tasks surface is pre-release, we redesign it cleanly — gated to `2026-07-28`,
  no deprecation shims — and keep the file-based store for shared-hosting
  compatibility.
- **MCP Apps extension (SEP-1865) — a committed v2 release feature.** Decision
  finalized June 2026: full support ships with `v2` releases, promoting Apps
  from the wait-and-see long-term position it previously held here. Apps
  looks to be a critical component of MCP going forward — at launch it was
  co-developed by Anthropic and OpenAI with the MCP-UI maintainers, Claude and
  VS Code already render it, and the `2026-07-28` extensions framework
  (SEP-2133) formalises it as an independently versioned first-class
  extension. The stable extension revision is
  [`2026-01-26`](https://github.com/modelcontextprotocol/ext-apps); the
  official ext-apps SDK is TypeScript-only today, so PHP server-side support
  fills a real gap. The UI renders host-side in a sandboxed iframe, so the
  SDK's role is the server side: declaring the extension, registering `ui://`
  template resources with the MIME-type and size conventions the extension
  defines, associating templates with tools through tool metadata so hosts can
  prefetch, cache, and security-review them ahead of execution, and handling
  the UI's tool-call-shaped messages like any other tool call. We will add a
  first-class `McpServer` helper (working name `->ui(...)`) that bundles those
  conventions so an app-enabled server stays a few lines of PHP, and the
  server must degrade gracefully where a host cannot display the UI.

**cPanel/Apache compatibility (guiding principle #3).** On balance this revision
helps shared hosting: dropping sticky sessions and shared session stores, and
turning sampling/elicitation into `InputRequiredResult` round-trips instead of
server-initiated requests over an open stream, removes the most fragile pieces
documented in [`docs/compatibility.md`](docs/compatibility.md). It is not a
clean break from SSE, though — request-scoped SSE responses and opt-in
`subscriptions/listen` streams remain long-lived, so the existing SSE
shared-hosting guidance still applies to those. The items that need attention
rather than a hard requirement are narrow: the request-metadata headers
(`Mcp-Method`, `Mcp-Name`, `MCP-Protocol-Version`, and any `Mcp-Param-*`) must
survive `.htaccess` and any proxy — the same class of forwarding concern we
already document for `Authorization` — and the MCP Apps extension (now a
committed v2 feature, above) renders host-side, so the server's job is plain
resource emission over standard HTTP — a natural fit for shared hosting — and
it must not fatal where a host can't display the UI. Core features (tools,
prompts, resources, `server/discover`) remain a must-work-everywhere
commitment, and that commitment is a v2 release gate alongside the 100%
conformance target, not a trade-off against it.

**Release vehicle — decided: `v2`.** Per CONTRIBUTING's versioning policy, the
major (`v2`) is reserved for "when the official MCP SDKs cut a `v2`." That
linkage is now confirmed rather than speculative: the Python SDK's v1.25.0
release note stated its v2 plan "relies on the next upcoming spec release
which will heavily change how the transport layer works," its `main` branch is
now its v2 development line, and the TypeScript SDK's v2 documentation
anticipates "a stable v2 release in Q3 2026 along with the updated MCP spec."
The ecosystem `v2` and `2026-07-28` are arriving together, so we have aligned:
this repository's `main` branch is the pre-alpha `v2`, and `2026-07-28`
day-one support ships as the headline feature of `v2.0` (with the MCP Apps
extension as a release feature alongside it). The stable `v1` line continues
on the [`1.x` branch](https://github.com/logiscape/mcp-sdk-php/tree/1.x) for
users on the `2024-11-05`…`2025-11-25` revisions, receiving bug fixes and
low-risk backports.

### Medium-term

Items in this section are directions we intend to explore ahead of a formal
spec release, in line with the Tier 1 expectation that SDKs begin implementing
new features before the revision that introduces them ships. Inclusion here is
not a commitment to a specific API shape — SEPs named below are still moving,
and we expect the concrete surface to change. Work that lands on the basis of
a moving SEP goes in under an "experimental" label (matching the convention
the official TypeScript and Python SDKs use for Tasks) and only graduates once
the SEP is stable.

- **Raise response-time expectations** in [SECURITY.md](SECURITY.md) and
  [SUPPORT.md](SUPPORT.md) once enough trusted contributors are on board to
  sustain them. See [GOVERNANCE.md](GOVERNANCE.md) for the path to becoming
  one.
- **Graduate the Tasks primitive.** An experimental implementation is already
  in the tree — `Mcp\Server\TaskManager` with file-based storage (chosen for
  cPanel/Apache compatibility), the `McpServer::enableTasks()` wiring for
  `tasks/get` / `tasks/list` / `tasks/cancel` / `tasks/result`, client-side
  `getTask()` / `listTasks()` / `cancelTask()` methods, and a full
  state-transition validator. As of the `2026-07-28` RC, Tasks has moved from a
  core primitive (formerly tracked here as SEP-1686) to the **SEP-2663
  extension** with a stateless redesign — notably dropping `tasks/list`. That
  redesign is now tracked in the day-one subsection above; this bullet remains
  only for the still-moving pieces (richer retry semantics, configurable
  result-expiry beyond the current TTL, and the task-augmented-request gap
  marked in `Server/Elicitation/ElicitationContext::url()` and the equivalent
  sampling path).
- **Finish task-augmented elicitation and sampling.** Form-mode and URL-mode
  elicitation are already wired on both sides of the wire, and
  `sampling/createMessage` already accepts `tools` / `toolChoice` under the
  `sampling.tools` sub-capability check. What is missing is carrying a
  `task` parameter through to the server-facing ergonomic layer. On the
  elicitation side, `ElicitationContext::form()` and `ElicitationContext::url()`
  accept a `?TaskRequestParams $task` argument but explicitly reassign it
  to `null` before building the request, with an in-code comment stating
  that task-augmented elicitation is not yet implemented. On the sampling
  side, `CreateMessageRequest` already takes a `task` constructor
  argument, but `SamplingContext::createMessage()` does not expose one —
  callers using the ergonomic wrapper have no way to attach task metadata.
  The gap is deliberate: the surrounding wire format is not fully settled,
  and closing it is a prerequisite for calling Tasks "done" rather than an
  independent item.
- **Shared session store for clustered HTTP deployments.** The
  `SessionStoreInterface` seam already accepts pluggable implementations;
  file-based and in-memory stores ship in-box. The stateless transport work
  once described here as "forthcoming from the Transports WG" is now concrete in
  the `2026-07-28` RC (SEP-2567 removes the protocol-level session, SEP-2575
  removes the handshake), which makes a shared session store **optional** rather
  than a horizontal-scaling prerequisite under the new revision. The remaining
  value is for clients still on `2024-11-05`…`2025-11-25`: publishing a reference
  shared-store implementation (likely a PSR-6 or PSR-16 adapter, so users can
  pick Redis/Memcached/APCu without the SDK taking a hard dependency) and keeping
  the seam stable so the additive stateless paths above do not force API churn.
- **Pre-connection discovery: Server Cards (SEP-2127) vs. `server/discover`
  (SEP-2575).** OAuth `.well-known` endpoints are already served by the HTTP
  runner; a discovery surface for capabilities is not. Two mechanisms now
  overlap here: the SEP-2127 Server Card `.well-known` document (a static,
  pre-connection descriptor) and the `2026-07-28` `server/discover` RPC method
  (an on-demand, cacheable capability fetch that replaces what the handshake
  used to provide). `server/discover` is the priority because it is part of
  day-one revision support (tracked above); a Server Card endpoint remains a
  complementary, lower-priority addition. Both are thin endpoints on
  `HttpServerRunner` and natural fits for shared hosting, shipped behind a config
  flag once the paths and schemas stop moving.
- **Close remaining OAuth-spec alignment gaps.** The client-side already
  has `ClientIdMetadataDocument` (CIMD / SEP-991), a PKCE implementation,
  Protected-Resource and Authorization-Server metadata discovery, and
  dynamic client registration. The remaining alignment work tracks items
  still landing on the spec side: baseline default scopes (SEP-835), the
  `client_credentials` grant (also tracked in the near-term conformance
  bullet), and follow-through on any smaller auth extensions that reach
  Accepted status. These drop into the existing `Client/Auth/` and
  `Server/Auth/` framework rather than requiring a new abstraction.

### Long-term / conditional

Items here are further out, either because the relevant SEP is not yet stable,
because the feature is an optional extension rather than a core SDK
requirement, or because adoption depends on demand from this SDK's users
(primarily PHP developers deploying on shared hosting). We would rather wait
than put users on a breaking-API treadmill.

- **Advanced OAuth profiles: DPoP and Workload Identity Federation.**
  SEP-1932 (DPoP, sender-constrained tokens per RFC 9449) and SEP-1933
  (Workload Identity Federation) are both in review and aimed primarily at
  enterprise deployments. Neither has any implementation in the tree today,
  and both require real cryptographic care (DPoP in particular introduces
  per-request JWT proofs). We plan to revisit once the profiles stabilise
  and at least one major SDK has a reference implementation worth
  comparing against.
- **Enterprise operability surfaces.** The 2026 MCP roadmap names audit
  trails and SIEM/APM integration, SSO-aware auth, gateway and
  session-affinity patterns, and configuration portability as priorities.
  Most of this is downstream integration work rather than core SDK surface,
  so our role is primarily to not block it: keeping `HttpIoInterface`,
  `SessionStoreInterface`, the PSR-3 logger seam, and the auth validator
  interfaces clean enough that a framework or gateway can plug in without
  forking.
- **Horizon items** — triggers, event-driven updates, streamed and
  reference-based result types, and other early-exploration work flagged in
  the [2026 MCP Roadmap](https://blog.modelcontextprotocol.io/posts/2026-mcp-roadmap/).
  Too early to commit to; we will pick them up when the corresponding SEPs
  are close to stable.

## What it would take to become Tier 1 in practice

This is the honest answer to "what would have to be true?":

- **At least two additional trusted contributors** with commit rights, covering
  triage when the core maintainer is unavailable.
- **A documented on-call expectation** that covers SEP-1730's P0 seven-day
  window across any single week of the year.
- **Sustained time-to-first-label under two business days** over a rolling
  three-month window, measurable from GitHub's API.
- **A clean conformance run** on every new spec revision within the
  RC-to-final validation window (ten weeks for `2026-07-28`).

None of these are out of reach. They are the conditions the project needs to
grow into — and the point of publishing them is so anyone reading can see
what's in the way.

## How to help

- Land a focused PR. See [CONTRIBUTING.md](CONTRIBUTING.md).
- Review other people's PRs. This is how the trusted-contributor pool grows.
- Open issues for spec gaps you find, especially with minimal reproductions.
- Run the SDK against the official MCP Inspector and real-world AI clients
  (Claude Code, OpenAI API) and report behaviour that drifts from other SDKs —
  guidance in [`docs/testing.md`](docs/testing.md).

## Revision history

Roadmap direction changes of any significance are announced in
[CHANGELOG.md](CHANGELOG.md). Small wording fixes go in quietly.
