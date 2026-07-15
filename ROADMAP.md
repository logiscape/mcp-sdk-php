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

1. **Day-one support for each MCP specification release is the standing
   priority.** Tracking the spec is not one roadmap item among many — it is
   the continuous objective that outranks every other item in this document.
   When a new revision enters its release-candidate window, implementing it
   (with full conformance and sensible back-compat for prior revisions)
   preempts whatever else is in flight. The `v2` cycle set the working
   pattern: implement against the RC inside the RC-to-final validation
   window, verify against the official conformance suite as it evolves, and
   be ready to release when the spec is published.
2. **No conformance shortcuts.** We do not engineer workarounds purely to
   green a conformance test. If a test fails honestly, it goes in the
   relevant conformance baseline file (see
   [`conformance/README.md`](conformance/README.md) — one per track: stable
   and draft) with a root cause and a plan — or no plan, if it is genuinely
   an optional extension we are not pursuing yet.
3. **cPanel/Apache compatibility is mandatory for core MCP features.**
   Features that cannot be compatible with shared hosting still ship (for spec
   alignment) but must fail gracefully instead of crashing the SDK. See
   [`docs/compatibility.md`](docs/compatibility.md).
4. **Avoid breaking changes where we can.** When breaking the public API or
   documented flows is genuinely necessary on a stable line, we bump the
   minor version and document the change in [CHANGELOG.md](CHANGELOG.md).
   The `v2` major exists precisely to absorb the breaking `2026-07-28`
   protocol revision in one place rather than dribbling breaks into `v1`.
5. **The core stays pure PHP.** The SDK's only runtime dependency is
   `psr/log` (see [`docs/dependency-policy.md`](docs/dependency-policy.md)),
   which is what lets it embed anywhere — a Laravel or Symfony application, a
   legacy codebase, a bare cPanel account — without version conflicts in the
   consumer's dependency tree. Integration needs are met through interfaces
   (`HttpIoInterface`, `SessionStoreInterface`, `TokenStorageInterface`,
   `SubscriptionBusInterface`, the PSR-3 logger seam) and, where an
   implementation is worth shipping in-box, through classes that add **zero**
   Composer dependencies. Framework-specific conveniences belong in separate
   bridge packages that depend on the core — never the other way around.

## Current tier position (self-assessment)

Measured against the SEP-1730 criteria, this is where we stand. This is a
self-assessment, not an official tier assignment — only the MCP SDK Working
Group assigns tiers.

| SEP-1730 criterion          | Target (Tier 1)          | Current state                                                                                                                          |
| --------------------------- | ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| Conformance pass rate       | 100%                     | **100%** of stable-track scenarios — both baseline lists are empty. The `2026-07-28` draft track additionally runs in CI with a small, documented upstream-issue baseline. |
| New protocol features       | Before spec release      | `2026-07-28` (the release candidate) supported on `main` ahead of the final spec, alongside `2024-11-05` … `2025-11-25` via built-in negotiation — the `v2` pre-release (see below).        |
| Issue triage                | 2 business days          | Best-effort; see response-time section below.                                                                         |
| Critical bug resolution     | 7 days                   | Best-effort, typically weeks not days for non-trivial fixes.                                                          |
| Stable release              | Required, clear versioning | Met. Latest stable is `v1.7.4` on the [`1.x` branch](https://github.com/logiscape/mcp-sdk-php/tree/1.x); `main` carries the pre-release `v2`. Semver-tagged since `v1.0.0`.       |
| Documentation               | Comprehensive w/ examples | Met. Two-audience documentation set indexed in [docs/README.md](docs/README.md): [server-dev](docs/server-dev.md) and [client-dev](docs/client-dev.md) guides, a [v1 → v2 migration guide](docs/migration-v2.md), [Tasks](docs/tasks.md) and [Apps](docs/apps.md) extension guides, [testing](docs/testing.md) and [compatibility](docs/compatibility.md), plus a runnable [example per major feature](examples/README.md) and an example web client. |
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

## Now: shipping v2

**v2 development is feature-complete and in testing.** The `main` branch
carries the pre-release `v2`; the stable `v1` line lives on the
[`1.x` branch](https://github.com/logiscape/mcp-sdk-php/tree/1.x) and
continues to receive bug fixes and low-risk backports. Every workstream of
the [v2 development plan](docs/v2-development-plan.md) — the stateless
`2026-07-28` core, dual-era client/server negotiation, the transport and
authorization changes, the Tasks (SEP-2663) and MCP Apps (SEP-1865)
extensions, backward compatibility across all five supported revisions,
conformance, shared-hosting validation on a live cPanel host, examples, and
documentation — is implemented, reviewed, and verified. The plan itself now
doubles as the development record; per-workstream status lines live there.

What remains before a tagged `v2.0.0` is the release process, not feature
work. In line with guiding principle #1, this section outranks everything
below it:

- **Track the RC through to the final `2026-07-28` specification.** Between
  now and spec publication, monitor the spec repository and the official
  conformance suite for changes relative to the release candidate. If the
  final text lands changes, the affected workstreams re-open as new
  milestones (research → implement → review → approve) before release — the
  gates are checkpoints, not a ratchet.
- **Pass the remaining release gates** (gates are human decisions — see the
  [v2 plan's release-gate section](docs/v2-development-plan.md#release-gates)):
  the validated pre-release tag, then **G4** — a clean conformance run
  against the suite version current at spec publication, and the `v2.0.0`
  tag.
- **Burn down the draft-track conformance baseline.** The stable track is at
  100% with empty baselines; the draft track carries a small documented
  baseline of upstream-suite issues. Each entry is re-checked as new suite
  versions ship, and upstream reports/PRs are pursued rather than worked
  around (guiding principle #2).
- **Release mechanics:** changelog, migration-guide final review, Packagist
  release notes, and the `1.x` support statement — human-initiated, per the
  project's process rules.

## Post-v2: embedding and web-integration batteries

The v2 work proved the SDK's integration seams end to end — the
`HttpIoInterface` SAPI adapter for embedding the HTTP runner in any
framework, `SessionStoreInterface` for legacy-era session persistence,
`TokenStorageInterface` on the OAuth client side. What integrators still
have to write themselves are the *implementations* behind those seams that
nearly every web deployment wants — and, in a few places, a seam that
reviewing the SDK against real embedding scenarios showed is still missing.
The next tranche of SDK work ships those batteries in-box, under a strict
inclusion test that keeps guiding principle #5 intact: each item must build
on the SDK's existing public surface (adding at most a narrow new interface
or convenience method of our own), add **zero** Composer dependencies, be
purely additive and opt-in, and serve any web integrator rather than
encoding one consumer's policy.

Planned for the `v2.x` minor line, once `v2.0.0` has shipped:

- **A framework-neutral `McpServer` HTTP entry point.**
  `HttpServerRunner::handleRequest()` is already embeddable — an
  `HttpMessage` in, an `HttpMessage` out — but `McpServer::runHttp()`
  constructs the runner privately and terminates through the SAPI adapter
  (which reads the request from PHP globals), so framework users who want
  the high-level registration API must rebuild the initialization-options,
  session-store, and runner wiring themselves. A
  `McpServer::handleHttpRequest(HttpMessage): HttpMessage` (or an
  equivalent `createHttpRunner()`) closes that gap for every framework at
  once.
- **`PdoSessionStore`** — a database-backed `SessionStoreInterface`
  implementation on bare PDO (bundled with PHP; no new dependency). Web
  deployments that serve legacy-era (`2024-11-05` … `2025-11-25`) clients
  today get file-based sessions by default and write their own DB store if
  they want one; this makes the DB store a one-line constructor argument
  instead. A PSR-6/PSR-16 cache adapter (Redis/Memcached/APCu without a hard
  dependency) remains a candidate companion where PDO isn't the right fit.
- **`PdoTokenStorage`** — the same pattern on the OAuth client side: a
  database-backed `TokenStorageInterface` implementation joining the
  existing `FileTokenStorage` and `MemoryTokenStorage`, for web-hosted MCP
  clients that need token sets shared across PHP processes. Two
  requirements up front: **caller/tenant scoping** — the interface keys
  records by resource URL alone and `clear()` clears everything, so the
  store must be constructor-scoped to a namespace before a shared table is
  safe for multi-user web applications — and **encryption-at-rest parity**
  with `FileTokenStorage`'s optional AES-256-GCM secret.
- **A web OAuth redirect-flow coordinator.** The two-phase web
  authorization flow already exists as public API —
  `OAuthClient::initiateWebAuthorization()` returns an
  `AuthorizationRequest` carrying all flow state, and
  `exchangeCodeForTokens()` completes it after the browser redirect — but
  persisting that in-flight state between the two web requests and wiring
  the redirect and callback endpoints around it is currently left to each
  integrator, with reference code in `webclient/`. The battery is a small
  coordinator with a pluggable flow-state store that packages this
  orchestration. Deliberately *not* shaped as an
  `AuthorizationCallbackInterface` implementation: that interface's
  synchronous authorize-and-wait contract cannot span two web requests,
  and pretending otherwise would misuse the seam.
- **An outbound endpoint and redirect policy seam.** Web applications that
  let operators configure arbitrary MCP server URLs need SSRF-grade
  controls — allowed schemes, hosts, ports, resolved-address checks, and
  redirect policy. Today the HTTP client transport and SSE connection
  follow redirects unconditionally with no policy hook. The SDK's role is
  the enforcement seam: a policy interface consulted on the initial
  endpoint and on every redirect, with safe defaults in-box; the
  allow/deny policy itself stays each consumer's decision.
- **Request-scoped context for server handler callbacks.** Tool, prompt,
  and resource callbacks currently receive their typed arguments but no
  first-class view of the request around them — the authenticated
  principal and OAuth claims the transport already validates internally,
  the request's `_meta`, HTTP headers. An optional, framework-neutral
  request context injectable into callbacks would let embedded servers
  make per-caller decisions without reaching into session internals or
  leaning on framework globals.
- **A framework-embedding guide in `docs/`** — concrete recipes for hosting
  the SDK inside a framework: converting the framework's request/response
  objects to and from `HttpMessage`, the `McpServer` HTTP entry point above
  (or wiring `HttpServerRunner` directly), handling the
  `StreamedHttpMessage` streaming-SSE sentinel, and choosing between the
  buffered and native-IO paths. Target recipes: Laravel, Symfony, and a
  generic PSR-15 middleware — plus the client-side counterpart (persisting
  and resuming client sessions across stateless web requests with
  `Client::resumeHttpSession()` / `detach()`).

These are sequenced deliberately *after* `v2.0.0`: they are additive
convenience, not release blockers, and shipping them in a `v2.x` minor keeps
the release focused on the spec revision (guiding principles #1 and #4).

### Other post-v2 candidates

Carried forward from the v2 cycle, in no committed order:

- **Tasks extension follow-ups** as the extension's own draft line moves:
  the optional `notifications/tasks` status push (this SDK is poll-based
  today) and richer application-driven retry/expiry policies on the
  file-based store.
- **Server Cards (SEP-2127)** — the static `.well-known` pre-connection
  descriptor complementing the `server/discover` RPC that shipped with v2: a
  thin endpoint on `HttpServerRunner`, a natural fit for shared hosting,
  behind a config flag once the SEP's path and schema stop moving.
- **Remaining OAuth-spec alignment** — baseline default scopes (SEP-835) and
  any smaller auth extensions that reach Accepted status; these drop into
  the existing `Client/Auth/` and `Server/Auth/` framework.
- **Raise response-time expectations** in [SECURITY.md](SECURITY.md) and
  [SUPPORT.md](SUPPORT.md) once enough trusted contributors are on board to
  sustain them. See [GOVERNANCE.md](GOVERNANCE.md) for the path to becoming
  one.

## Possible future: framework bridge packages

An idea documented here so it isn't lost, deliberately **not** on any
timeline: first-party bridge packages for popular frameworks, starting with a
hypothetical **`logiscape/mcp-sdk-laravel`** (a service provider, config
publishing, route registration, and container wiring around the core SDK),
with a Symfony bundle and a PSR-7/PSR-17 message-conversion bridge as the
other obvious candidates.

The rules that would govern them, per guiding principle #5:

- Bridges are **separate Composer packages that depend on the core** — the
  core never depends on, suggests, or special-cases any framework. Existing
  users of the pure-PHP SDK see no change whatsoever.
- Bridges are **demand-driven.** A convenience package carries its own
  compatibility promise and maintenance cost (framework major versions, for
  one), so it should exist only when enough real consumers are asking for it
  — one integrator copying a documented recipe does not justify a public
  package. The framework-embedding guide above is the measuring instrument:
  if its recipes turn out to be all anyone needs, no bridge ships; if the
  same glue keeps getting rebuilt, the guide's Laravel recipe becomes the
  bridge package's seed.
- Anything a bridge needs from the core must be expressible through the
  existing public seams. If writing a bridge exposes a missing seam, the
  seam (not the framework coupling) is the core change.

## Long-term / conditional

Items here are further out, either because the relevant SEP is not yet
stable, because the feature is an optional extension rather than a core SDK
requirement, or because adoption depends on demand from this SDK's users
(primarily PHP developers deploying on shared hosting). We would rather wait
than put users on a breaking-API treadmill.

- **Advanced OAuth profiles: DPoP and Workload Identity Federation.**
  SEP-1932 (DPoP, sender-constrained tokens per RFC 9449) and SEP-1933
  (Workload Identity Federation) are aimed primarily at enterprise
  deployments and require real cryptographic care. We plan to revisit once
  the profiles stabilise and at least one major SDK has a reference
  implementation worth comparing against.
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
  are close to stable — at which point guiding principle #1 moves them to
  the top of this document.

## What it would take to become Tier 1 in practice

This is the honest answer to "what would have to be true?":

- **At least two additional trusted contributors** with commit rights, covering
  triage when the core maintainer is unavailable.
- **A documented on-call expectation** that covers SEP-1730's P0 seven-day
  window across any single week of the year.
- **Sustained time-to-first-label under two business days** over a rolling
  three-month window, measurable from GitHub's API.
- **A clean conformance run** on every new spec revision within the
  RC-to-final validation window (ten weeks for `2026-07-28`) — the standing
  practice guiding principle #1 already demands.

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
