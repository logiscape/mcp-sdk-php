# MCP SDK Tier Audit: logiscape/mcp-sdk-php

**Date**: 2026-08-18
**Branch**: main
**Auditor**: mcp-sdk-tier-audit skill (automated + subagent evaluation)
**SDK version**: 2.0.0
**Requirements revisions scored**: 2025-11-25 and 2026-07-28 (frozen per-revision requirement sets, each run at its own wire version)

## Tier Assessment: Tier 1

The PHP SDK passes every scored conformance scenario in both frozen requirements revisions (server 100% — 67/67 across 2025-11-25 and 2026-07-28; client 100% — 50/50 across the same two revisions), has clean triage/label/P0 posture, a stable 2.0.0 release with same-day spec tracking, comprehensive documentation with examples, and published dependency, roadmap, and versioning policies. All 8 Tier 1 requirements are met.

### Requirements Summary

| #   | Requirement             | Tier 1 Standard                   | Tier 2 Standard              | Current Value                                        | T1?  | T2?  | Gap  |
| --- | ----------------------- | --------------------------------- | ---------------------------- | ---------------------------------------------------- | ---- | ---- | ---- |
| 1a  | Server Conformance      | 100% pass rate                    | >= 80% pass rate             | 100% (67/67 across revisions 2025-11-25 + 2026-07-28) | PASS | PASS | None |
| 1b  | Client Conformance      | 100% pass rate                    | >= 80% pass rate             | 100% (50/50 across revisions 2025-11-25 + 2026-07-28) | PASS | PASS | None |
| 2   | Issue Triage            | >= 90% within 2 biz days          | >= 80% within 1 month        | 100% (0/0 issues in analysis window)                 | PASS | PASS | None |
| 2b  | Labels                  | 12 required labels                | 12 required labels           | 12/12                                                | PASS | PASS | None |
| 3   | Critical Bug Resolution | All P0s within 7 days             | All P0s within 2 weeks       | 0 open; all historical P0s resolved within 7 days    | PASS | PASS | None |
| 4   | Stable Release          | Required + clear versioning       | At least one stable release  | 2.0.0 (stable, no pre-release suffix)                | PASS | PASS | None |
| 4b  | Spec Tracking           | Timeline agreed per release       | Within 6 months              | -1d gap (same-day tracking, 24h-window rule)         | PASS | PASS | None |
| 5   | Documentation           | Comprehensive w/ examples         | Basic docs for core features | 46/48 features (core 36/36)                          | PASS | PASS | None (judgment call on legacy SSE, see Documentation Coverage) |
| 6   | Dependency Policy       | Published update policy           | Published update policy      | Found (docs/dependency-policy.md)                    | PASS | PASS | None |
| 7   | Roadmap                 | Published roadmap                 | Plan toward Tier 1           | Found (ROADMAP.md)                                   | PASS | PASS | None |
| 8   | Versioning Policy       | Documented breaking change policy | N/A                          | Found (CONTRIBUTING.md "Versioning policy")          | PASS | N/A  | None |

### Tier Determination

- Tier 1: PASS -- 8/8 requirements met (failing: none)
- Tier 2: PASS -- 7/7 requirements met (failing: none)
- **Final Tier: 1**

---

## Server Conformance Details

Pass rate: 100% (67/67 scored scenario runs across both requirements revisions; per the tier-check JSON: 30/30 at revision 2025-11-25, 37/37 at revision 2026-07-28).

Not scored: 16 runs (9 failed) — 10 `tasks-*` extension scenarios (9 failed, 1 passed: tasks-required-task-error), plus passing `pending`/`added-after-release` runs (server-sse-polling, server-session-lifecycle, json-schema-2020-12 in both revisions, http-header-validation, http-custom-header-server-validation). Not-scored runs do not count toward the pass rate.

There is no baseline.yml / expected-failures file in the SDK repo.

### Scored scenarios — revision 2025-11-25 (30/30)

| Scenario | Status | Checks |
| -------- | ------ | ------ |
| tools-list | PASS | 3/3 |
| tools-call-with-progress | PASS | 2/2 |
| tools-call-with-logging | PASS | 2/2 |
| tools-call-simple-text | PASS | 2/2 |
| tools-call-sampling | PASS | 2/2 |
| tools-call-mixed-content | PASS | 2/2 |
| tools-call-image | PASS | 2/2 |
| tools-call-error | PASS | 2/2 |
| tools-call-embedded-resource | PASS | 2/2 |
| tools-call-elicitation | PASS | 2/2 |
| tools-call-audio | PASS | 2/2 |
| server-sse-multiple-streams | PASS | 2/2 |
| server-initialize | PASS | 3/3 |
| resources-unsubscribe | PASS | 2/2 |
| resources-templates-read | PASS | 2/2 |
| resources-subscribe | PASS | 2/2 |
| resources-read-text | PASS | 2/2 |
| resources-read-binary | PASS | 2/2 |
| resources-list | PASS | 2/2 |
| prompts-list | PASS | 2/2 |
| prompts-get-with-image | PASS | 2/2 |
| prompts-get-with-args | PASS | 2/2 |
| prompts-get-simple | PASS | 2/2 |
| prompts-get-embedded-resource | PASS | 2/2 |
| ping | PASS | 2/2 |
| logging-set-level | PASS | 2/2 |
| elicitation-sep1330-enums | PASS | 6/6 |
| elicitation-sep1034-defaults | PASS | 6/6 |
| dns-rebinding-protection | PASS | 2/2 |
| completion-complete | PASS | 2/2 |

### Scored scenarios — revision 2026-07-28 (37/37)

| Scenario | Status | Checks |
| -------- | ------ | ------ |
| tools-list | PASS | 3/3 |
| tools-call-with-progress | PASS | 2/2 |
| tools-call-simple-text | PASS | 2/2 |
| tools-call-mixed-content | PASS | 2/2 |
| tools-call-image | PASS | 2/2 |
| tools-call-error | PASS | 2/2 |
| tools-call-embedded-resource | PASS | 2/2 |
| tools-call-audio | PASS | 2/2 |
| server-stateless | PASS | 28/28 |
| server-sse-multiple-streams | PASS | 1/1 |
| sep-2164-resource-not-found | PASS | 4/4 |
| resources-templates-read | PASS | 2/2 |
| resources-read-text | PASS | 2/2 |
| resources-read-binary | PASS | 2/2 |
| resources-list | PASS | 2/2 |
| prompts-list | PASS | 2/2 |
| prompts-get-with-image | PASS | 2/2 |
| prompts-get-with-args | PASS | 2/2 |
| prompts-get-simple | PASS | 2/2 |
| prompts-get-embedded-resource | PASS | 2/2 |
| input-required-result-validate-input | PASS | 3/3 |
| input-required-result-unsupported-methods | PASS | 2/2 |
| input-required-result-tampered-state | PASS | 2/2 |
| input-required-result-result-type | PASS | 2/2 |
| input-required-result-request-state | PASS | 3/3 |
| input-required-result-non-tool-request | PASS | 3/3 |
| input-required-result-multiple-input-requests | PASS | 3/3 |
| input-required-result-multi-round | PASS | 4/4 |
| input-required-result-missing-input-response | PASS | 2/2 |
| input-required-result-ignore-extra-params | PASS | 2/2 |
| input-required-result-capability-check | PASS | 2/2 |
| input-required-result-basic-sampling | PASS | 3/3 |
| input-required-result-basic-list-roots | PASS | 3/3 |
| input-required-result-basic-elicitation | PASS | 3/3 |
| dns-rebinding-protection | PASS | 2/2 |
| completion-complete | PASS | 2/2 |
| caching | PASS | 8/8 |

### Not scored (informational) — 16 runs, 9 failed

These runs are excluded from the conformance pass rate (extension scenarios, or scenarios pending / added after the SDK release).

| Scenario | Revision | Reason | Status | Checks |
| -------- | -------- | ------ | ------ | ------ |
| server-sse-polling | 2025-11-25 | pending | PASS | 2/2 |
| server-session-lifecycle | 2025-11-25 | added-after-release | PASS | 3/3 |
| json-schema-2020-12 | 2025-11-25 | pending | PASS | 8/8 |
| json-schema-2020-12 | 2026-07-28 | pending | PASS | 8/8 |
| http-header-validation | 2026-07-28 | pending | PASS | 14/14 |
| http-custom-header-server-validation | 2026-07-28 | pending | PASS | 10/10 |
| tasks-wire-fields | 2026-07-28 | extension | FAIL | 3/4 |
| tasks-status-notifications | 2026-07-28 | extension | FAIL | 0/0 |
| tasks-required-task-error | 2026-07-28 | extension | PASS | 3/3 |
| tasks-request-state-removal | 2026-07-28 | extension | FAIL | 2/3 |
| tasks-request-headers | 2026-07-28 | extension | FAIL | 4/5 |
| tasks-mrtr-input | 2026-07-28 | extension | FAIL | 3/4 |
| tasks-mrtr-composition | 2026-07-28 | extension | FAIL | 1/2 |
| tasks-lifecycle | 2026-07-28 | extension | FAIL | 8/9 |
| tasks-dispatch-and-envelope | 2026-07-28 | extension | FAIL | 8/9 |
| tasks-capability-negotiation | 2026-07-28 | extension | FAIL | 4/5 |

---

## Client Conformance Details

Full suite pass rate: 100% (50/50 scored scenario runs across both requirements revisions; 18/18 at revision 2025-11-25, 32/32 at revision 2026-07-28).

> **Suite breakdown**: revision 2025-11-25 — Core: 4/4 (100%), Auth: 14/14 (100%); revision 2026-07-28 — Core: 7/7 (100%), Auth: 25/25 (100%). (Category rule: scenario name starting with `auth/` = Auth, else Core.)
> **Baseline**: No baseline.yml / expected-failures file exists in the SDK repo — 0 documented expected failures.

Not scored: 14 runs (8 failed) — extension auth scenarios (auth/dpop, auth/dpop-nonce, auth/enterprise-managed-authorization, auth/wif-jwt-bearer failing in both revisions; auth/client-credentials-jwt and auth/client-credentials-basic passing in both), plus json-schema-2020-12-preservation (added-after-release, passing in both revisions).

### Core Scenarios — revision 2025-11-25 (4/4)

| Scenario | Status | Checks |
| -------- | ------ | ------ |
| tools_call | PASS | 2/2 |
| sse-retry | PASS | 3/3 |
| initialize | PASS | 1/1 |
| elicitation-sep1034-client-defaults | PASS | 5/5 |

### Auth Scenarios — revision 2025-11-25 (14/14)

| Scenario | Status | Checks | Notes |
| -------- | ------ | ------ | ----- |
| auth/token-endpoint-auth-post | PASS | 19/19 | -- |
| auth/token-endpoint-auth-none | PASS | 19/19 | -- |
| auth/token-endpoint-auth-basic | PASS | 19/19 | -- |
| auth/scope-step-up | PASS | 23/23 | -- |
| auth/scope-retry-limit | PASS | 26/26 | -- |
| auth/scope-omitted-when-undefined | PASS | 15/15 | -- |
| auth/scope-from-www-authenticate | PASS | 15/15 | -- |
| auth/scope-from-scopes-supported | PASS | 15/15 | -- |
| auth/pre-registration | PASS | 14/14 | -- |
| auth/metadata-var3 | PASS | 14/14 | -- |
| auth/metadata-var2 | PASS | 14/14 | -- |
| auth/metadata-var1 | PASS | 14/14 | -- |
| auth/metadata-default | PASS | 14/14 | -- |
| auth/basic-cimd | PASS | 14/14 | -- |

### Core Scenarios — revision 2026-07-28 (7/7)

| Scenario | Status | Checks |
| -------- | ------ | ------ |
| tools_call | PASS | 2/2 |
| sep-2322-client-request-state | PASS | 5/5 |
| request-metadata | PASS | 5/5 |
| json-schema-ref-no-deref | PASS | 1/1 |
| http-standard-headers | PASS | 9/9 |
| http-invalid-tool-headers | PASS | 11/11 |
| http-custom-headers | PASS | 18/18 |

### Auth Scenarios — revision 2026-07-28 (25/25)

| Scenario | Status | Checks | Notes |
| -------- | ------ | ------ | ----- |
| auth/token-endpoint-auth-post | PASS | 18/18 | -- |
| auth/token-endpoint-auth-none | PASS | 18/18 | -- |
| auth/token-endpoint-auth-basic | PASS | 18/18 | -- |
| auth/scope-step-up | PASS | 25/25 | -- |
| auth/scope-retry-limit | PASS | 19/19 | -- |
| auth/scope-omitted-when-undefined | PASS | 14/14 | -- |
| auth/scope-from-www-authenticate | PASS | 14/14 | -- |
| auth/scope-from-scopes-supported | PASS | 14/14 | -- |
| auth/resource-mismatch | PASS | 3/3 | -- |
| auth/pre-registration | PASS | 12/12 | -- |
| auth/offline-access-scope | PASS | 11/11 | -- |
| auth/offline-access-not-supported | PASS | 14/14 | -- |
| auth/metadata-var3 | PASS | 13/13 | -- |
| auth/metadata-var2 | PASS | 13/13 | -- |
| auth/metadata-var1 | PASS | 13/13 | -- |
| auth/metadata-issuer-mismatch | PASS | 3/3 | -- |
| auth/metadata-default | PASS | 13/13 | -- |
| auth/iss-wrong-issuer | PASS | 8/8 | -- |
| auth/iss-unexpected | PASS | 8/8 | -- |
| auth/iss-supported-missing | PASS | 8/8 | -- |
| auth/iss-supported | PASS | 14/14 | -- |
| auth/iss-not-advertised | PASS | 14/14 | -- |
| auth/iss-normalized | PASS | 8/8 | -- |
| auth/basic-cimd | PASS | 12/12 | -- |
| auth/authorization-server-migration | PASS | 27/27 | -- |

### Not scored (informational) — 14 runs, 8 failed

Extension scenarios and scenarios added after the SDK release; excluded from the pass rate. No baseline file exists to document expected failures.

| Scenario | Revision | Reason | Status | Checks |
| -------- | -------- | ------ | ------ | ------ |
| json-schema-2020-12-preservation | 2025-11-25 | added-after-release | PASS | 9/9 |
| json-schema-2020-12-preservation | 2026-07-28 | added-after-release | PASS | 9/9 |
| auth/client-credentials-jwt | 2025-11-25 | extension | PASS | 9/9 |
| auth/client-credentials-jwt | 2026-07-28 | extension | PASS | 7/7 |
| auth/client-credentials-basic | 2025-11-25 | extension | PASS | 9/9 |
| auth/client-credentials-basic | 2026-07-28 | extension | PASS | 7/7 |
| auth/wif-jwt-bearer | 2025-11-25 | extension | FAIL | 8/9 |
| auth/wif-jwt-bearer | 2026-07-28 | extension | FAIL | 8/9 |
| auth/enterprise-managed-authorization | 2025-11-25 | extension | FAIL | 8/10 |
| auth/enterprise-managed-authorization | 2026-07-28 | extension | FAIL | 8/10 |
| auth/dpop | 2025-11-25 | extension | FAIL | 9/12 |
| auth/dpop | 2026-07-28 | extension | FAIL | 10/13 |
| auth/dpop-nonce | 2025-11-25 | extension | FAIL | 9/14 |
| auth/dpop-nonce | 2026-07-28 | extension | FAIL | 10/15 |

---

## Issue Triage Details

Analysis period: analysis window (0 issues)
Labels: all 12 required labels present (bug, enhancement, question, needs confirmation, needs repro, ready for work, good first issue, help wanted, P0, P1, P2, P3); no missing labels. uses_issue_types: false.

| Metric          | Value | T1 Req | T2 Req | Verdict |
| --------------- | ----- | ------ | ------ | ------- |
| Compliance rate | 100%  | >= 90% | >= 80% | PASS    |
| Exceeding SLA   | 0     | --     | --     | --      |
| Open P0s        | 0     | 0      | 0      | PASS    |

No open P0s. All historical P0s were resolved within 7 days.

---

## Documentation Coverage

Documentation locations found:

- README.md: overview, install, quick-start server/client examples, doc index
- docs/server-dev.md (3,026 lines): comprehensive server guide — tools, prompts, resources, transports, OAuth, structured output, elicitation, sampling, completions, notifications/logging/progress, cancellation, config reference
- docs/client-dev.md (1,961 lines): comprehensive client guide — connecting, era negotiation, tools/prompts/resources, pagination, HTTP transport config, OAuth, elicitation handling, notifications, roots, sampling servicing, ping, cancellation, session resume
- docs/tasks.md (428 lines): Tasks extension guide (SEP-2663, experimental)
- docs/apps.md: MCP Apps extension guide (SEP-1865)
- docs/migration-v2.md, compatibility.md, shared-hosting-validation.md, testing.md, docs/README.md (index)
- examples/README.md + 12 runnable examples; webclient/README.md; conformance/README.md (not counted as feature docs)

Feature table (48 non-experimental + experimental INFO rows):

| # | Feature | Documented? | Where | Has Examples? | Verdict |
|---|---------|-------------|-------|---------------|---------|
| 1 | Tools - listing | Yes | docs/server-dev.md:172-231; docs/client-dev.md:299-335 | Yes (many) | PASS |
| 2 | Tools - calling | Yes | docs/server-dev.md:172-431; docs/client-dev.md:376-411 | Yes (many) | PASS |
| 3 | Tools - text results | Yes | docs/server-dev.md:185-218, 2961-2976; docs/client-dev.md:388-397 | Yes (many) | PASS |
| 4 | Tools - image results | Yes | docs/server-dev.md:1625-1680 | Yes (1) | PASS |
| 5 | Tools - audio results | Yes | docs/server-dev.md:1726-1762 | Yes (1) | PASS |
| 6 | Tools - embedded resources | Yes | docs/server-dev.md:1764-1806 | Yes (1) | PASS |
| 7 | Tools - error handling | Yes | docs/server-dev.md:267-300; docs/client-dev.md:399-411 | Yes (2) | PASS |
| 8 | Tools - change notifications | Yes | docs/server-dev.md:2606-2728; docs/client-dev.md:1344-1371 | Yes (3) | PASS |
| 9 | Resources - listing | Yes | docs/server-dev.md:663-787; docs/client-dev.md:556-579 | Yes (many) | PASS |
| 10 | Resources - reading text | Yes | docs/server-dev.md:674-707; docs/client-dev.md:583-615 | Yes (many) | PASS |
| 11 | Resources - reading binary | Yes | docs/server-dev.md:790-834; docs/client-dev.md:604-608 | Yes (2) | PASS |
| 12 | Resources - templates | Yes | docs/server-dev.md:879-941; docs/client-dev.md:1854 | Yes (2) | PASS |
| 13 | Resources - template reading | Yes | docs/server-dev.md:881-939 (routing, variable extraction, {+var}) | Yes (2) | PASS |
| 14 | Resources - subscribing | Yes | docs/client-dev.md:617-653; docs/server-dev.md:2606-2659 | Yes (1) | PASS |
| 15 | Resources - unsubscribing | Yes | docs/client-dev.md:617-653, 1857 | Yes (1) | PASS |
| 16 | Resources - change notifications | Yes | docs/server-dev.md:2644-2728; docs/client-dev.md:1368-1369 | Yes (2) | PASS |
| 17 | Prompts - listing | Yes | docs/server-dev.md:434-443; docs/client-dev.md:451-476 | Yes (many) | PASS |
| 18 | Prompts - getting simple | Yes | docs/server-dev.md:445-482; docs/client-dev.md:480-511 | Yes (many) | PASS |
| 19 | Prompts - getting with arguments | Yes | docs/server-dev.md:458-509; docs/client-dev.md:491-495 | Yes (many) | PASS |
| 20 | Prompts - embedded resources | Yes | docs/server-dev.md:610-659 | Yes (1) | PASS |
| 21 | Prompts - image content | Yes | docs/server-dev.md:560-608 | Yes (1) | PASS |
| 22 | Prompts - change notifications | Yes | docs/server-dev.md:2644-2728; docs/client-dev.md:1370 | Yes (1) | PASS |
| 23 | Sampling - creating messages | Yes | docs/server-dev.md:2141-2368; docs/client-dev.md:1549-1594 | Yes (4) | PASS |
| 24 | Elicitation - form mode | Yes | docs/server-dev.md:1841-2009; docs/client-dev.md:1157-1227; examples/elicitation_server.php + elicitation_client.php | Yes (5+) | PASS |
| 25 | Elicitation - URL mode | Yes | docs/server-dev.md:2011-2077; docs/client-dev.md:1251-1288 | Yes (2) | PASS |
| 26 | Elicitation - schema validation | Yes | docs/server-dev.md:1845; docs/client-dev.md:1223-1227, 1249 | Yes | PASS |
| 27 | Elicitation - default values | Yes | docs/client-dev.md:1229-1249 (SEP-1034 applyDefaults); docs/server-dev.md:1927-1945 | Yes (2) | PASS |
| 28 | Elicitation - enum values | Yes | docs/server-dev.md:1940-1945, 1845; docs/client-dev.md:1191-1194 | Yes (3) | PASS |
| 29 | Elicitation - complete notification | Yes | docs/server-dev.md:2064-2077 (notifyUrlComplete); docs/client-dev.md:1259 | Yes (1) | PASS |
| 30 | Roots - listing | Yes | docs/client-dev.md:1596-1640 | Yes (1) | PASS |
| 31 | Roots - change notifications | Yes | docs/client-dev.md:1603, 1635-1637, 1646 | Yes (1) | PASS |
| 32 | Logging - sending log messages | Yes | docs/server-dev.md:2552-2604; docs/client-dev.md:1326-1331 | Yes (2) | PASS |
| 33 | Logging - setting level | Yes | docs/server-dev.md:2570-2572; docs/client-dev.md:1374-1392 | Yes (2) | PASS |
| 34 | Completions - resource argument | Yes | docs/server-dev.md:2414-2449; docs/client-dev.md:546 | Yes (1) | PASS |
| 35 | Completions - prompt argument | Yes | docs/server-dev.md:2378-2412, 2451-2473; docs/client-dev.md:514-544 | Yes (3) | PASS |
| 36 | Ping | Yes | docs/server-dev.md:1232; docs/client-dev.md:1400-1428 | Yes (1) | PASS |
| 37 | Streamable HTTP transport (client) | Yes | docs/client-dev.md:657-783; examples/client_http.php | Yes (many) | PASS |
| 38 | Streamable HTTP transport (server) | Yes | docs/server-dev.md:968-1226 (Part 4); examples/simple_server_http.php | Yes (many) | PASS |
| 39 | SSE transport - legacy (client) | No | Not implemented (intentional, documented: docs/client-dev.md:776-782) | No | FAIL |
| 40 | SSE transport - legacy (server) | No | Not implemented (intentional, documented: docs/server-dev.md:1116-1122) | No | FAIL |
| 41 | stdio transport (client) | Yes | docs/client-dev.md:114-140; examples/client_negotiation.php | Yes (many) | PASS |
| 42 | stdio transport (server) | Yes | docs/server-dev.md:66-104; examples/simple_server_stdio.php | Yes (many) | PASS |
| 43 | Progress notifications | Yes | docs/server-dev.md:2488-2549; docs/client-dev.md:1334-1341, 1394-1398 | Yes (3) | PASS |
| 44 | Cancellation | Yes | docs/server-dev.md:1234-1276; docs/client-dev.md:1430-1547 | Yes (4) | PASS |
| 45 | Pagination | Yes | docs/client-dev.md:337-373 | Yes (1) | PASS |
| 46 | Capability negotiation | Yes | docs/client-dev.md:219-291; docs/server-dev.md:2350-2357 | Yes (2) | PASS |
| 47 | Protocol version negotiation | Yes | docs/client-dev.md:169-217, 260-291; docs/server-dev.md:116-168; examples/client_negotiation.php | Yes (2) | PASS |
| 48 | JSON Schema 2020-12 support | Yes | docs/server-dev.md:220-228, 302-356, 2978-2989 | Yes (2) | PASS |
| — | Tasks - get (experimental) | Yes | docs/tasks.md:28, 96-257; examples/tasks_client.php | Yes | INFO |
| — | Tasks - result (experimental) | Yes (removed by SEP-1686; result inlined in tasks/get) | docs/tasks.md:32-34 | N/A | INFO |
| — | Tasks - cancel (experimental) | Yes | docs/tasks.md:30, 299-303; examples/tasks_client.php | Yes | INFO |
| — | Tasks - list (experimental) | Yes (removed by SEP-1686) | docs/tasks.md:32 | N/A | INFO |
| — | Tasks - status notifications (experimental) | Yes (documented as not implemented; polling model) | docs/tasks.md:424-425 | N/A | INFO |

Summary: 46/48 PASS with examples, 0 PARTIAL, 2 FAIL (both = legacy SSE, not implemented). Core features 36/36 (100%). Subagent Tier 1 verdict: FAIL on strict reading (2 not-implemented rows); Tier 2 PASS.

**Auditor judgment call**: The two failing rows are the deprecated 2024-11-05 HTTP+SSE transport, deliberately not implemented and explicitly documented as an intentional omission with rationale (docs/client-dev.md:776-782; docs/server-dev.md:1116-1122). Neither audited requirements revision (2025-11-25, 2026-07-28) requires the legacy transport. Every implemented non-experimental feature is documented with prose and examples. The Documentation requirement is therefore scored PASS for Tier 1 at the audit level, with this rationale recorded.

---

## Policy Evaluation

Policy signal files present: CHANGELOG.md, SECURITY.md, CONTRIBUTING.md, docs/dependency-policy.md, ROADMAP.md. Absent: DEPENDENCY_POLICY.md, dependabot/renovate configs, docs/roadmap.md, VERSIONING.md, docs/versioning.md, BREAKING_CHANGES.md.

1. **Dependency Update Policy: PASS (Tier 1 and 2)** — docs/dependency-policy.md is a 130-line substantive policy ("This document describes how dependencies are chosen, how they are updated, and when version-floor bumps are acceptable") with explicit per-change-type update rules (widening range = normal PR with CI proving both ranges build; narrowing/replacing = potentially breaking), a PHP version-floor policy, pinned-tooling rules for the conformance tool, and a security-update procedure (force users onto fixed release, call out in CHANGELOG.md and a GitHub Security Advisory). No dependabot/renovate configs (not required given the policy doc).
2. **Roadmap: PASS (Tier 1 and Tier 2)** — ROADMAP.md (296 lines) with concrete work items tracking MCP spec components: "Day-one support for each MCP specification release is the standing priority"; named items: Tasks extension follow-ups (optional notifications/tasks status push), Server Cards (SEP-2127), baseline default scopes (SEP-835), DPoP (SEP-1932), Workload Identity Federation (SEP-1933), a v2.x battery list, and a SEP-1730 self-assessment table ("On technical criteria we are comfortably at Tier 1 shape", transparent about single-maintainer response-time risk).
3. **Versioning Policy: PASS (Tier 1)** — CONTRIBUTING.md has a labeled "## Versioning policy" section (lines 126-143): SemVer interpreted for this SDK (patch/minor/major roles defined, major aligned with wider MCP ecosystem); breaking changes defined (land in minor, "Avoid breaking changes when a non-breaking alternative exists"); communicated via CHANGELOG.md and release notes. Note: this interpretation places breaking changes in minor releases (majors reserved for ecosystem-wide alignment) — documented and clearly communicated, though it deviates from strict SemVer.
