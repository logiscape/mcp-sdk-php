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
