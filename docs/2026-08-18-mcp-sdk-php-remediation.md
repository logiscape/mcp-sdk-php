# Remediation Guide: logiscape/mcp-sdk-php

**Date**: 2026-08-18
**Current Tier**: 1

## Path to Tier 1

All 8 Tier 1 requirements are met — there are no remediation items required to reach or hold Tier 1. The audit was scored against the frozen requirement sets for spec revisions 2025-11-25 and 2026-07-28; server conformance is 100% (67/67 scored) and client conformance is 100% (50/50 scored) on both revisions.

| #   | Action | Requirement | Effort | Where |
| --- | ------ | ----------- | ------ | ----- |
| --  | None — all Tier 1 requirements met | -- | -- | -- |

Notes on judgment calls and non-blocking observations from the assessment:

- **Documentation (Req 5)**: 46/48 non-experimental features PASS with examples. The 2 FAILs are the deprecated 2024-11-05 legacy HTTP+SSE transport (client and server), which is deliberately not implemented and documented as an intentional decision (`docs/client-dev.md:776-782`, `docs/server-dev.md:1116-1122`). Ruled out-of-scope for the audited revisions; recorded here as a judgment call.
- **Versioning policy (Req 8)**: CONTRIBUTING.md "Versioning policy" (lines 126-143) is documented and PASSES, but its SemVer interpretation places breaking changes in minor releases. This deviates from strict SemVer — worth monitoring for alignment if ecosystem guidance tightens.
- **Not-scored conformance failures** (informational, do not affect tier): 9 of 10 server `tasks-*` extension scenarios (SEP-2663) and 4 client auth extension scenarios (DPoP, DPoP nonce, enterprise-managed authorization, WIF JWT bearer) fail; all are extensions already tracked in ROADMAP.md.
- **Pending / not-yet-scored scenarios all pass**: server-sse-polling, json-schema-2020-12, http-header-validation, http-custom-header-server-validation (pending); server-session-lifecycle and client json-schema-2020-12-preservation (added after release); client auth/client-credentials-* (extension).

## Maintaining Tier 1 / Recommended Next Steps

None of these block Tier 1; they are ordered by impact.

| #   | Action | Rationale | Effort | Where |
| --- | ------ | --------- | ------ | ----- |
| 1   | Implement remaining Tasks extension (SEP-2663) checks | 9 `tasks-*` scenarios failing (tasks-wire-fields, tasks-status-notifications, tasks-request-state-removal, tasks-request-headers, tasks-mrtr-input, tasks-mrtr-composition, tasks-lifecycle, tasks-dispatch-and-envelope, tasks-capability-negotiation), typically 1 failing check each; tasks-required-task-error already passes. ROADMAP.md already tracks Tasks follow-ups. | Medium | SDK server transport/tasks handling; ROADMAP.md |
| 2   | Implement DPoP (SEP-1932) including nonce handling | auth/dpop (3 failing checks) and auth/dpop-nonce (5 failing checks); already named as planned in ROADMAP.md. | Medium | SDK client auth layer; ROADMAP.md |
| 3   | Implement WIF JWT bearer (SEP-1933) and enterprise-managed authorization support | auth/wif-jwt-bearer (1 failing check) and auth/enterprise-managed-authorization (2 failing checks); WIF is already on the roadmap. | Medium | SDK client auth layer; ROADMAP.md |
| 4   | Add a conformance baseline/expected-failures file documenting the known extension failures | No baseline.yml exists in the SDK repo today; since extension scenarios are expected to fail until implemented, a baseline makes CI signal cleaner and flags regressions vs. known gaps. | Small | New baseline file in SDK repo (e.g. `baseline.yml`) |
| 5   | Add dependabot or renovate configuration | The published dependency policy (`docs/dependency-policy.md`) satisfies the requirement, but no automated update tooling backs it; automation is a small hardening win. | Small | `.github/dependabot.yml` or `renovate.json` |
| 6   | Sustain the issue-triage SLA given single-maintainer risk | Triage is currently 100% (0 issues in window) with 0 open P0s, but ROADMAP.md itself flags single-maintainer response-time risk. Consider a co-maintainer or triage automation to protect the >= 90% / 2-business-day Tier 1 SLA. | Process note | ROADMAP.md; repo maintainership |

1. **Tasks extension (SEP-2663)** — the largest cluster of failing (non-scored) scenarios; closing it keeps the SDK ahead of the curve if tasks checks graduate into scored requirement sets for a future revision.
2. **DPoP (SEP-1932)** — highest-value auth extension already committed on the roadmap.
3. **WIF JWT bearer (SEP-1933) + enterprise-managed authorization** — completes the auth extension surface.
4. **Conformance baseline file** — cheap, immediate CI-signal improvement while items 1-3 are in flight.
5. **Dependabot/renovate config** — automates the dependency policy already published.
6. **Triage sustainability** — process safeguard for the only structural risk the roadmap self-identifies.
