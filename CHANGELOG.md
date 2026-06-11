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

### Changed

- Roadmap: `v2` confirmed as the release vehicle for `2026-07-28` day-one
  support (aligned with the official TypeScript and Python SDK v2 timelines),
  and the MCP Apps extension (SEP-1865) promoted from long-term/conditional
  to a committed v2 release feature.

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
