# Remediation Guide: logiscape/mcp-sdk-php

**Date**: 2026-08-17
**Current Tier**: 1

## Path to Tier 1

**All Tier 1 requirements are met.** No remediation is required to reach or retain the current tier:

- Server conformance: 100% (31/31 scenarios)
- Client conformance: 100% (21/21 scored date-versioned scenarios)
- Issue triage: 100% compliance (trivially -- 0 issues existed in the analysis window)
- Labels: 12/12 required labels present
- Critical bugs: 0 open P0s
- Stable release: v2.0.0
- Spec tracking: PASS (SDK release within the tracking window of the latest spec release)
- Documentation: 46/48 features documented with examples (core 36/36)
- Dependency policy, roadmap, and versioning policy: all PASS

One judgment call was applied: documentation formally scored 46/48 because the legacy 2024-11-05 HTTP+SSE transport (client + server, features #39/#40) is deliberately not implemented. The exclusion is documented with rationale (client-dev.md:776-782, server-dev.md:1116-1122). Since the spec deprecated that transport in favor of Streamable HTTP and no current conformance requirement exercises it, it was ruled out of scope and did not block Tier 1.

### Maintaining Tier 1

Since there are no gaps, the table below lists watch-items and risks rather than remediation actions.

| #   | Action                                                                                                                                                                                                                                                                                            | Requirement                            | Effort       | Where                                                                                        |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------- | ------------ | -------------------------------------------------------------------------------------------- |
| 1   | Implement the 4 failing extension auth scenarios: `auth/dpop` (3 failed checks), `auth/dpop-nonce` (5 failed checks), `auth/wif-jwt-bearer` (1 failed check), `auth/enterprise-managed-authorization` (2 failed checks). These are informational today, but align with the SDK's own roadmap items SEP-1932 (DPoP) and SEP-1933 (WIF); implementing them ahead of any promotion of these extensions into date-versioned scenarios protects the 100% client pass rate. | 1b Client Conformance (extension, not tier-scored) | Medium-Large | SDK auth/transport layer; ROADMAP.md (SEP-1932, SEP-1933)                                    |
| 2   | Resolve the recurring documentation caveat for the deprecated legacy 2024-11-05 HTTP+SSE transport (features #39/#40): either get the feature formally scoped out upstream in the SEP-1730 tooling/feature checklist, or accept that each audit will re-apply the same 46/48 judgment call.          | 5 Documentation                        | Small        | client-dev.md:776-782, server-dev.md:1116-1122; upstream SEP-1730 feature list               |
| 3   | Keep triage/P0 SLAs achievable as issue volume grows. The 100% triage compliance and 0 open P0s are currently untested in practice (0 open issues, single maintainer per the roadmap); a burst of issues could quickly breach the 2-business-day triage SLA.                                        | 2 Issue Triage / 3 Critical Bug Resolution | Small (ongoing) | GitHub issue tracker; ROADMAP.md (single-maintainer note)                                    |
| 4   | (Optional) Add dependabot or renovate automation to back the written dependency policy. The published `docs/dependency-policy.md` satisfies the requirement on its own, but automation reduces the chance of the policy drifting from practice.                                                     | 6 Dependency Policy                    | Small        | .github/dependabot.yml or renovate.json (currently absent); docs/dependency-policy.md        |

## Recommended Next Steps

1. **Implement DPoP and WIF auth extensions (SEP-1932 / SEP-1933)** -- this is the only area with failing conformance results (4 extension scenarios, 11 failed checks total), it is already on the SDK's own roadmap, and finishing it before those scenarios become tier-scored is the single best protection for the 100% client pass rate.
2. **Close out the legacy SSE transport caveat** -- pursue a formal upstream scope-out of the deprecated 2024-11-05 HTTP+SSE transport in the SEP-1730 tooling so future audits score 46/46 rather than repeating the 46/48 judgment call; small effort, permanently removes the one soft spot in the documentation score.
3. **Prepare triage capacity before it is tested** -- with zero open issues and a single maintainer, the triage and P0 SLAs have never been exercised; establish a lightweight routine (e.g., a scheduled triage pass and optional dependabot/renovate automation) now so Tier 1 SLAs hold when real issue volume arrives.
