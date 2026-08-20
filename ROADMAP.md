# Roadmap

This roadmap describes where `logiscape/mcp-sdk-php` stands today, and what we
are working on next.

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

## Current tier position: Tier 1 (MCP SDK Tier Audit)

See [MCP SDK Tier Audit](docs/2026-08-18-mcp-sdk-php-assessment.md) for the latest tier assessment,
generated in Claude Code with the Fable 5 model using the [MCP SDK Tier Audit](https://github.com/modelcontextprotocol/conformance/blob/main/.claude/skills/mcp-sdk-tier-audit/README.md) skill.

## Tier self-assessment

Measured against the SEP-1730 criteria, this is where we stand. This is a
self-assessment, see the above section for the latest assessment report.

| SEP-1730 criterion          | Target (Tier 1)          | Current state                                                                                                                          |
| --------------------------- | ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| Conformance pass rate       | 100%                     | **100%** of stable-track scenarios — client and server baseline lists are empty. The `2026-07-28` draft track additionally runs in CI with a small, documented baseline. |
| New protocol features       | Before spec release      | Met. `2026-07-28` supported on `main` ahead of the final spec, alongside `2024-11-05` … `2025-11-25` via built-in negotiation.        |
| Issue triage                | 2 business days          | Currently met on all open issues.                                                                         |
| Critical bug resolution     | 7 days                   | Currently met on all open issues.                                                          |
| Stable release              | Required, clear versioning | Met. Latest stable is `v1.7.5` on the [`1.x` branch](https://github.com/logiscape/mcp-sdk-php/tree/1.x); `main` carries the `v2` release. Semver-tagged since `v1.0.0`.       |
| Documentation               | Comprehensive w/ examples | Met. Two-audience documentation set indexed in [docs/README.md](docs/README.md): [server-dev](docs/server-dev.md) and [client-dev](docs/client-dev.md) guides, a [v1 → v2 migration guide](docs/migration-v2.md), [Tasks](docs/tasks.md) and [Apps](docs/apps.md) extension guides, [testing](docs/testing.md) and [compatibility](docs/compatibility.md), plus a runnable [example per major feature](examples/README.md) and an example web client. |
| Dependency update policy    | Published                | Met ([`docs/dependency-policy.md`](docs/dependency-policy.md)).                                                                        |
| Roadmap                     | Published                | Met — this document.                                                                                                                   |

On **technical** criteria we are comfortably at Tier 1 shape. On **maintenance
response-time** criteria, we are currently meeting Tier 1 requirements on all
current issues. We will make a best effort to continue meeting this goal, but
the project currently has a single maintainer and we want to be transparent
about that rather than setting expectations that might be difficult to meet.

## Now: v2 release

**v2 development is complete.** The `main` branch
carries the `v2` release; the legacy `v1` line lives on the
[`1.x` branch](https://github.com/logiscape/mcp-sdk-php/tree/1.x) and
continues to receive bug fixes and low-risk backports. Every workstream of
v2 development — the stateless
`2026-07-28` core, dual-era client/server negotiation, the transport and
authorization changes, the Tasks (SEP-2663) and MCP Apps (SEP-1865)
extensions, backward compatibility across all five supported revisions,
conformance, shared-hosting validation on a live cPanel host, examples, and
documentation — is implemented, reviewed, and verified. The working plan
that ordered those workstreams (`docs/v2-development-plan.md`) was retired
at release preparation and is preserved in the repository's git history as
the v2 development record.

We are currently watching upstream baselined issues in the draft conformance
track, with the cause and status documented in the [draft baseline file](conformance/conformance-draft-baseline.yml).
We also expect the stable and draft conformance tools to merge upstream,
and will reassess conformance testing in the SDK when that happens.

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

Planned for the `v2.x` minor line, now that `v2.0.0` has shipped:

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
- **PSR-7 conversion at the HTTP boundary.** The entry point above (and
  `HttpServerRunner::handleRequest()` beneath it) speaks `HttpMessage`,
  but framework developers think in PSR-7 (or HttpFoundation). The
  framework-embedding guide below documents the conversion by hand, but a
  small `Psr7Converter` turns "read the recipe and write glue" into one
  line — the difference between a guide being read and a package being
  adopted. The planned shape ships it in the core, guarded by
  `interface_exists(ServerRequestInterface::class)` so no dependency is
  required, and — since interfaces alone cannot construct objects —
  building PSR-7 responses through caller-supplied PSR-17 factories.
  Only if that guarded approach proves awkward at implementation time
  does this move out to the PSR-7/PSR-17 bridge package sketched under
  framework bridges below, in which case it leaves this `v2.x` list and
  becomes that section's first demand-driven package. Either way the
  dependency policy survives intact: the core still requires nothing.
- **Attribute- and docblock-driven tool schema generation.** The single
  biggest remaining ergonomic gap when this SDK is evaluated side by side
  with the reference SDKs: `buildSchemaFromCallback()` reflects bare
  `string`/`number`/`boolean` parameter types with placeholder
  descriptions. Fixing it is purely additive and dependency-free — PHP
  8.1 attributes are native language surface — so a
  `#[McpTool]` / `#[Param(description:, enum:, format:)]` layer plus
  docblock-`@param` inference, feeding the existing `ToolInputSchema`
  type, closes most of that distance in one minor release. The current
  reflection path stays as the unchanged fallback for un-annotated
  callables.
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
- **A client-side HTTP exchange seam.** The server side is embeddable end
  to end (`HttpIoInterface`: an `HttpMessage` in, an `HttpMessage` out),
  but the client transport drives cURL directly — so an embedded server
  cannot be exercised in-process by the SDK's *own* client in a test
  suite (integrators end up hand-writing wire-format fixture clients,
  reintroducing exactly the `_meta`-envelope and header-rule bugs the
  SDK's client already avoids), and hosts with cURL disabled or an
  existing instrumented HTTP layer cannot substitute one. The seam: a
  narrow injectable exchange — `function (HttpMessage $request):
  HttpMessage` — with the cURL implementation remaining the
  zero-configuration default; streaming/SSE can stay cURL-only initially,
  since the non-streaming JSON-RPC exchange covers the in-process testing
  case (the auth components' direct cURL calls are part of the same
  design surface). To be designed *together with* the outbound
  endpoint/redirect policy seam above — both intercept the same outbound
  requests, and one well-placed seam should serve both.
- **Request-scoped context for server handler callbacks.** Tool, prompt,
  and resource callbacks currently receive their typed arguments but no
  first-class view of the request around them — the authenticated
  principal and OAuth claims the transport already validates internally,
  the request's `_meta`, HTTP headers. An optional, framework-neutral
  request context injectable into callbacks would let embedded servers
  make per-caller decisions without reaching into session internals or
  leaning on framework globals.
- **A handler call-interceptor seam on `McpServer`.** Dispatch reflects
  the registered callable's own named parameters, so a host cannot wrap a
  handler in a generic decorator (a closure, an invokable middleware
  object) without destroying parameter matching — production embedders
  report resorting to per-tool wrapper code generation for cross-cutting
  behavior at the call boundary (translating domain exceptions into typed
  JSON-RPC errors, normalizing returns, metering, audit). The seam: an
  optional per-registration or server-wide interceptor —
  `function (callable $handler, array $orderedArgs): mixed` — invoked
  around the already-matched call, leaving reflection-driven parameter
  matching untouched. Composes with the request-scoped context above:
  context injects *information* into the call, the interceptor enables
  *decoration* around it.
- **Optional PSR-11 container resolution for handler references.**
  Registration accepts callables today; letting it also accept
  `[ClassName::class, 'method']` handler references, resolved through an
  *optional* PSR-11 container supplied by the consumer, is what makes
  framework DI users feel at home — and it is the seam a future Laravel
  or Symfony bridge would want anyway. With no container configured,
  nothing changes, and the core gains no required dependency. One design
  constraint recorded up front: the registration methods type-hint
  `callable` (which an unresolved instance-method reference is not), and
  since they are overridable, widening those signatures would break
  existing subclass overrides — so the wire-in needs a shape that leaves
  them untouched: a parallel entry point, or a resolver that wraps the
  reference in an invokable (itself a valid `callable`) before it
  reaches the existing methods. That is one reason this item is
  sequenced after the callback-binder extraction in the refactoring
  section below.
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

## Ongoing: gradual internal refactoring

Alongside — never ahead of — the spec-tracking priority in guiding
principle #1, we are committing to a gradual refactoring of the SDK's
largest files, aimed at code quality and maintainability rather than new
behavior. The work proceeds in chunks sized to fit around whatever
higher-priority roadmap items are in flight; each chunk is planned and
tested on its own and gated the same way as feature work (`composer
check`, plus a regression-free conformance run for anything touching
protocol handling, transports, sessions, or `McpServer`), so that it
introduces no unexpected or preventable breaking changes.

The shape of the work: the SDK's large "god class" files are mostly
*wide facades over cohesive clusters* — the boundaries already exist,
they just live in one file. The two main targets:

- **`HttpServerTransport.php`** (~2,500 lines), whose methods fall into
  six nearly non-overlapping clusters: HTTP routing and validation,
  JSON-RPC wire-message factories, SSE emission/streaming,
  resume/event-log logic, session lifecycle, and auth. This
  decomposition is already visibly in progress — `Transport/Http/Sse/`
  (`SseEmitter`, `SseFormatter`, `StreamEventLog`, `StreamRegistry`,
  `SseSessionState`) was extracted during the v2 cycle — so the refactor
  is finishing a migration, not setting a new architectural direction.
  The wire-message factories are pure functions of their input and make
  the lowest-risk first chunk.
- **`McpServer.php`** (~2,500 lines), where three self-contained
  collaborators are waiting to be extracted without touching a single
  public signature: the UI/Apps builders (`buildUiCsp`,
  `buildUiPermissions`, `validateUiHints`), the Tasks orchestration
  (`runToolAsTask`, `runTaskRound`, `applyTaskInputResponses`), and the
  reflection/callback binding (`buildSchemaFromCallback`,
  `matchNamedParameters`, the `callbackNeeds*` probes).

Rules of engagement:

- **The `protected` methods are API.** `McpServer` alone exposes roughly
  a dozen protected extension points, and because the class is
  non-final, existing subclasses may override or call any of them — so
  moving logic into collaborators is a BC-sensitive change even when the
  public surface is untouched. Every existing protected member therefore
  survives each extraction as a thin delegating shim. What we decide
  explicitly, per extraction, is which of those members are *supported*
  extension points (documented and kept) and which were incidental —
  and an incidental shim is only ever retired through the normal
  deprecation-then-minor-version path of guiding principle #4, never by
  reclassification alone.
- **Ratchet static analysis as we go.** The codebase passes PHPStan
  level 6 across the board; every *newly extracted* class must
  additionally pass level 8–9. PHPStan applies one level per analysis
  run, so the mechanism is a second, stricter configuration scoped to
  the extracted paths and wired into `composer check` alongside the
  repository-wide run — an automated gate, not a convention. That gives
  a monotonically improving codebase without a big-bang analysis
  cleanup, and prevents extracted classes from inheriting the old
  file's looseness.
- **The synchronous blocking model is architecture, not debt.** It is
  the SDK's design center (see
  [docs/compatibility.md](docs/compatibility.md)) and explicitly out of
  scope for this refactor. The correct posture toward async remains the
  one stated under enterprise operability below: keep the seams clean
  enough that a gateway can plug in without forking.

Because the refactor and the embedding batteries touch the same files,
they are sequenced together: extract the JSON-RPC wire factories and the
SSE/streaming cluster from `HttpServerTransport` *before* building
`McpServer::handleHttpRequest()`, so the new entry point lands on the
decomposed transport rather than adding another caller to the monolith;
then the `McpServer` Tasks and callback-binding extractions; then the
attribute-driven schema layer, which naturally lives beside the
extracted binder; then PSR-7 conversion and the framework-embedding
guide. Each step is independently shippable and conformance-gated.

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
