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
   green a conformance test. If a test fails honestly, it goes in
   [`conformance/conformance-baseline.yml`](conformance/conformance-baseline.yml)
   with a root cause and a plan — or no plan, if it is genuinely an optional
   extension we are not pursuing yet.
3. **cPanel/Apache compatibility is mandatory for core MCP features.**
   Features that cannot be compatible with shared hosting still ship (for spec
   alignment) but must fail gracefully instead of crashing the SDK. See
   [`docs/compatibility.md`](docs/compatibility.md).
4. **Avoid breaking changes where we can.** When breaking the public API or
   documented flows is genuinely necessary, we bump the minor version
   (`v1.X`), not the patch, and we document the change in
   [CHANGELOG.md](CHANGELOG.md).

## Current tier position (self-assessment)

Measured against the SEP-1730 criteria, this is where we stand. This is a
self-assessment, not an official tier assignment — only the MCP SDK Working
Group assigns tiers.

| SEP-1730 criterion          | Target (Tier 1)          | Current state                                                                                                                          |
| --------------------------- | ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| Conformance pass rate       | 100%                     | **100%** of applicable required tests on suite `v0.1.16`. Three known failures, all in optional MCP Extensions (documented in baseline). |
| New protocol features       | Before spec release      | `2025-11-25` supported; back-compat for `2024-11-05`, `2025-03-26`, `2025-06-18`.                                                      |
| Issue triage                | 2 business days          | Best-effort; see response-time section below.                                                                         |
| Critical bug resolution     | 7 days                   | Best-effort, typically weeks not days for non-trivial fixes.                                                          |
| Stable release              | Required, clear versioning | Met. Currently `v1.7.0`, semver-tagged since `v1.0.0`.                                                                                 |
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

### Near-term (next release cycle)

- **Close optional conformance gaps, where we can do so spec-faithfully.**
  The three expected failures in
  [`conformance/conformance-baseline.yml`](conformance/conformance-baseline.yml)
  are:
  - `auth/client-credentials-jwt` — add `client_credentials` grant with JWT
    assertions (`client_assertion`, `client_assertion_type`).
  - `auth/client-credentials-basic` — add `client_credentials` grant with
    HTTP Basic client authentication.
  - `auth/cross-app-access-complete-flow` — the RFC 8707 `resource` parameter
    is already wired; the remaining two of ten assertions need investigation.
- **Continue tracking the spec.** Any mid-cycle SEP that reaches "Accepted"
  status is a candidate for inclusion in the next `v1.X` release.
- **Inspector and real-world AI-app smoke tests as part of the contributor
  workflow** — already described in [`docs/testing.md`](docs/testing.md);
  continuing to refine what we check.
- **Expand `conformance/everything-server.php` and `everything-client.php`**
  to cover new tools, prompts, and resources as the official suite grows.

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
- **Graduate the Tasks primitive (SEP-1686).** An experimental implementation
  is already in the tree — `Mcp\Server\TaskManager` with file-based storage
  (chosen for cPanel/Apache compatibility), the `McpServer::enableTasks()`
  wiring for `tasks/get` / `tasks/list` / `tasks/cancel` / `tasks/result`,
  client-side `getTask()` / `listTasks()` / `cancelTask()` methods, and a
  full state-transition validator. The remaining work tracks the pieces that
  are still moving upstream: richer retry semantics, configurable
  result-expiry policies beyond the current TTL, and closing the
  task-augmented-request gap explicitly marked in
  `Server/Elicitation/ElicitationContext::url()` (and the equivalent
  sampling path). Graduation out of "experimental" waits for spec stability
  in the official SDKs.
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
  file-based and in-memory stores ship in-box. Horizontal-scaling
  friendliness is primarily about publishing a reference shared-store
  implementation (likely a PSR-6 or PSR-16 adapter, so users can pick
  Redis/Memcached/APCu without the SDK taking a hard dependency) and
  documenting the seam so that the forthcoming stateless transport work
  from the Transports WG does not force API churn.
- **MCP Server Cards (SEP-2127).** OAuth `.well-known` endpoints are already
  served by the HTTP runner; the MCP Server Card `.well-known` endpoint is not.
  SEP-2127 is the current active draft for pre-connection discovery, replacing
  the earlier SEP-1649 discussion and aligning with the 2026 roadmap's
  transport-scalability priority. The SDK's role is a thin endpoint on
  `HttpServerRunner` plus helpers for generating the document; this is a natural
  fit for shared hosting, and we plan to ship it behind a config flag once the
  path and schema stop moving.
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

- **MCP Apps extension (`ui://` resources).** The
  [ext-apps](https://github.com/modelcontextprotocol/ext-apps) repository
  shipped its first stable spec revision in early 2026. Nothing in the
  current `McpServer::resource()` API prevents serving a `ui://` resource
  today — the question is whether a first-class helper (e.g. `->ui(...)`
  that bundles the MIME type, size-bound checks, and tool-metadata
  conventions) is worth adding. We will evaluate once the extension sees
  traction in the MCP hosts PHP developers actually target.
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
- **A clean conformance run** on every new spec revision within the two-week
  window between Release Candidate and final.

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
