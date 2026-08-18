# MCP SDK Tier Audit: logiscape/mcp-sdk-php

**Date**: 2026-08-17
**Branch**: main
**Auditor**: mcp-sdk-tier-audit skill (automated + subagent evaluation)

## Tier Assessment: Tier 1

The PHP SDK meets every Tier 1 requirement: 100% server conformance (31/31), 100% scored client conformance (21/21), all 12 required labels, no P0 backlog, a stable v2.0.0 release that tracked the 2026-07-28 spec release, comprehensive documentation with examples, and substantive dependency, roadmap, and versioning policies. The single documentation gap (the deprecated legacy HTTP+SSE transport, deliberately not implemented and documented as such) is treated as out of scope via an explicit judgment call detailed below.

**Requirements mode**: no `--requirements` flag was used; scoring used today's suite of date-versioned scenarios (`requirements_revisions: null`).

### Requirements Summary

| #   | Requirement             | Tier 1 Standard                   | Tier 2 Standard              | Current Value                          | T1?  | T2?  | Gap                                             |
| --- | ----------------------- | --------------------------------- | ---------------------------- | -------------------------------------- | ---- | ---- | ----------------------------------------------- |
| 1a  | Server Conformance      | 100% pass rate                    | >= 80% pass rate             | 100% (31/31)                           | PASS | PASS | None                                            |
| 1b  | Client Conformance      | 100% pass rate                    | >= 80% pass rate             | 100% (21/21 scored)                    | PASS | PASS | None (4 extension failures not tier-scored)     |
| 2   | Issue Triage            | >= 90% within 2 biz days          | >= 80% within 1 month        | 100% (0/0)                             | PASS | PASS | None (trivially — 0 issues in window)           |
| 2b  | Labels                  | 12 required labels                | 12 required labels           | 12/12                                  | PASS | PASS | None                                            |
| 3   | Critical Bug Resolution | All P0s within 7 days             | All P0s within 2 weeks       | 0 open, 0 closed in window             | PASS | PASS | None                                            |
| 4   | Stable Release          | Required + clear versioning       | At least one stable release  | v2.0.0 (2026-07-28, no pre-release)    | PASS | PASS | None                                            |
| 4b  | Spec Tracking           | Timeline agreed per release       | Within 6 months              | -1d gap (PASS)                         | PASS | PASS | None                                            |
| 5   | Documentation           | Comprehensive w/ examples         | Basic docs for core features | 46/48 features (core 36/36)            | PASS | PASS | None (2 FAILs out of scope — judgment call)     |
| 6   | Dependency Policy       | Published update policy           | Published update policy      | Found (docs/dependency-policy.md)      | PASS | PASS | None                                            |
| 7   | Roadmap                 | Published roadmap                 | Plan toward Tier 1           | Found (ROADMAP.md)                     | PASS | PASS | None                                            |
| 8   | Versioning Policy       | Documented breaking change policy | N/A                          | Found (CONTRIBUTING.md)                | PASS | N/A  | None                                            |

**Judgment call (Requirement 5, Documentation)**: The raw documentation evaluation marks 2 of 48 non-experimental features as FAIL — #39/#40, the legacy 2024-11-05 HTTP+SSE transport (client and server). The SDK deliberately does NOT implement this transport, and it documents the exclusion with rationale (docs/client-dev.md:776-782, docs/server-dev.md:1116-1122). Because the legacy HTTP+SSE transport is deprecated by the spec (replaced by Streamable HTTP in 2025-03-26) and is not exercised by any current conformance requirement, its deliberate, documented exclusion is treated as out of scope and does NOT block Tier 1 documentation compliance. Every implemented feature (46/46) is documented with prose and at least one example.

### Tier Determination

- Tier 1: PASS -- 8/8 requirements met (failing: none; documentation passes via the judgment call noted above)
- Tier 2: PASS -- 7/7 requirements met (failing: none)
- **Final Tier: 1**

---

## Server Conformance Details

Pass rate: 100% (31/31 scenarios, 0 not-scored). All scenarios are Core (no auth scenarios on the server side).

| Scenario                       | Status | Checks |
| ------------------------------ | ------ | ------ |
| tools-list                     | PASS   | 3/3    |
| tools-call-with-progress       | PASS   | 2/2    |
| tools-call-with-logging        | PASS   | 2/2    |
| tools-call-simple-text         | PASS   | 2/2    |
| tools-call-sampling            | PASS   | 2/2    |
| tools-call-mixed-content       | PASS   | 2/2    |
| tools-call-image               | PASS   | 2/2    |
| tools-call-error               | PASS   | 2/2    |
| tools-call-embedded-resource   | PASS   | 2/2    |
| tools-call-elicitation         | PASS   | 2/2    |
| tools-call-audio               | PASS   | 2/2    |
| server-sse-multiple-streams    | PASS   | 2/2    |
| server-session-lifecycle       | PASS   | 3/3    |
| server-initialize              | PASS   | 3/3    |
| resources-unsubscribe          | PASS   | 2/2    |
| resources-templates-read       | PASS   | 2/2    |
| resources-subscribe            | PASS   | 2/2    |
| resources-read-text            | PASS   | 2/2    |
| resources-read-binary          | PASS   | 2/2    |
| resources-list                 | PASS   | 2/2    |
| prompts-list                   | PASS   | 2/2    |
| prompts-get-with-image         | PASS   | 2/2    |
| prompts-get-with-args          | PASS   | 2/2    |
| prompts-get-simple             | PASS   | 2/2    |
| prompts-get-embedded-resource  | PASS   | 2/2    |
| ping                           | PASS   | 2/2    |
| logging-set-level              | PASS   | 2/2    |
| elicitation-sep1330-enums      | PASS   | 6/6    |
| elicitation-sep1034-defaults   | PASS   | 6/6    |
| dns-rebinding-protection       | PASS   | 2/2    |
| completion-complete            | PASS   | 2/2    |

---

## Client Conformance Details

Scored pass rate: 100% (21/21 date-versioned scenarios).

> **Suite breakdown (scored)**: Core: 5/5 (100%), Auth: 16/16 (100%). Category rule: `auth/` prefix = Auth, everything else = Core.
> **Baseline**: No baseline/expected-failures file exists in the SDK repo.
> **Not tier-scored**: 23 additional scenarios ran but were not tier-scored — 17 scenarios tagged only `2026-07-28` (all 17 PASSED) and 6 `extension` scenarios of which 4 FAILED and 2 passed. Extension failures do not affect tier.

### Conformance Matrix

Scored scenarios, per spec-version column; a scenario counts in every column it is tagged with.

|              | 2025-03-26 | 2025-06-18 | 2025-11-25 | 2026-07-28 | All\*        |
| ------------ | ---------- | ---------- | ---------- | ---------- | ------------ |
| Server       | 1/1        | 27/27      | 31/31      | 20/20      | 31/31 (100%) |
| Client: Core | —          | 2/2        | 5/5        | 2/2        | 5/5 (100%)   |
| Client: Auth | 2/2        | 3/3        | 14/14      | 14/14      | 16/16 (100%) |

\* unique scenarios — a scenario may apply to multiple spec versions

Informational (not scored for tier):

|              | 2026-07-28 (not scored) | extension |
| ------------ | ----------------------- | --------- |
| Client: Core | 6/6                     | —         |
| Client: Auth | 11/11                   | 2/6       |

### Core Scenarios (scored)

| Scenario                            | Status | Checks |
| ----------------------------------- | ------ | ------ |
| tools_call                          | PASS   | 2/2    |
| sse-retry                           | PASS   | 3/3    |
| json-schema-2020-12-preservation    | PASS   | 9/9    |
| initialize                          | PASS   | 1/1    |
| elicitation-sep1034-client-defaults | PASS   | 5/5    |

### Auth Scenarios (scored)

| Scenario                                | Status | Checks | Notes |
| --------------------------------------- | ------ | ------ | ----- |
| auth/token-endpoint-auth-post           | PASS   | 19/19  | —     |
| auth/token-endpoint-auth-none           | PASS   | 19/19  | —     |
| auth/token-endpoint-auth-basic          | PASS   | 19/19  | —     |
| auth/scope-step-up                      | PASS   | 23/23  | —     |
| auth/scope-retry-limit                  | PASS   | 26/26  | —     |
| auth/scope-omitted-when-undefined       | PASS   | 15/15  | —     |
| auth/scope-from-www-authenticate        | PASS   | 15/15  | —     |
| auth/scope-from-scopes-supported        | PASS   | 15/15  | —     |
| auth/pre-registration                   | PASS   | 14/14  | —     |
| auth/metadata-var3                      | PASS   | 14/14  | —     |
| auth/metadata-var2                      | PASS   | 14/14  | —     |
| auth/metadata-var1                      | PASS   | 14/14  | —     |
| auth/metadata-default                   | PASS   | 14/14  | —     |
| auth/basic-cimd                         | PASS   | 14/14  | —     |
| auth/2025-03-26-oauth-metadata-backcompat | PASS | 13/13  | —     |
| auth/2025-03-26-oauth-endpoint-fallback | PASS   | 8/8    | —     |

### Informational: 2026-07-28-only Scenarios (not tier-scored)

These 17 scenarios are tagged only with the `2026-07-28` spec version and were not tier-scored under today's requirements mode. All 17 passed.

| Scenario                          | Category | Status | Checks |
| --------------------------------- | -------- | ------ | ------ |
| sep-2322-client-request-state     | Core     | PASS   | 5/5    |
| request-metadata                  | Core     | PASS   | 5/5    |
| json-schema-ref-no-deref          | Core     | PASS   | 1/1    |
| http-standard-headers             | Core     | PASS   | 9/9    |
| http-invalid-tool-headers         | Core     | PASS   | 11/11  |
| http-custom-headers               | Core     | PASS   | 18/18  |
| auth/resource-mismatch            | Auth     | PASS   | 3/3    |
| auth/offline-access-scope         | Auth     | PASS   | 11/11  |
| auth/offline-access-not-supported | Auth     | PASS   | 14/14  |
| auth/metadata-issuer-mismatch     | Auth     | PASS   | 3/3    |
| auth/iss-wrong-issuer             | Auth     | PASS   | 8/8    |
| auth/iss-unexpected               | Auth     | PASS   | 8/8    |
| auth/iss-supported-missing        | Auth     | PASS   | 8/8    |
| auth/iss-supported                | Auth     | PASS   | 14/14  |
| auth/iss-not-advertised           | Auth     | PASS   | 14/14  |
| auth/iss-normalized               | Auth     | PASS   | 8/8    |
| auth/authorization-server-migration | Auth   | PASS   | 27/27  |

### Informational: Extension Scenarios (not tier-scored)

Extension scenarios cover optional protocol extensions and are never tier-scored. 2/6 passed; the 4 failures do not affect the tier determination.

| Scenario                              | Status | Checks | Notes                        |
| ------------------------------------- | ------ | ------ | ---------------------------- |
| auth/client-credentials-jwt           | PASS   | 9/9    | Not tier-scored              |
| auth/client-credentials-basic         | PASS   | 9/9    | Not tier-scored              |
| auth/dpop                             | FAIL   | 9/12   | Extension — not tier-scored  |
| auth/dpop-nonce                       | FAIL   | 9/14   | Extension — not tier-scored  |
| auth/wif-jwt-bearer                   | FAIL   | 8/9    | Extension — not tier-scored  |
| auth/enterprise-managed-authorization | FAIL   | 8/10   | Extension — not tier-scored  |

---

## Issue Triage Details

Analysis period: repository issue history within the analysis window.
Labels: 12/12 required SEP-1730 labels present (bug, enhancement, question, needs confirmation, needs repro, ready for work, good first issue, help wanted, P0, P1, P2, P3); none missing.

| Metric          | Value | T1 Req | T2 Req | Verdict |
| --------------- | ----- | ------ | ------ | ------- |
| Compliance rate | 100%  | >= 90% | >= 80% | PASS    |
| Exceeding SLA   | 0     | --     | --     | --      |
| Open P0s        | 0     | 0      | 0      | PASS    |

**Caveat**: the 100% triage compliance is trivially satisfied — the repository has 0 open issues and 0 issues in the analysis window, so there was nothing to triage. This is a pass by the letter of the requirement, but it provides no evidence of triage practice under load. P0 resolution likewise passes trivially for both tiers (0 open P0s, 0 closed P0s in window).

---

## Documentation Coverage

### Documentation Coverage Assessment

**SDK path**: C:\projects\mcp-sdk-php
**Documentation locations found**:

- C:\projects\mcp-sdk-php\README.md: Overview, installation, quick-start server/client examples, documentation index
- C:\projects\mcp-sdk-php\docs\server-dev.md: 3,027-line comprehensive server development guide (12 parts + appendices), with embedded runnable code examples for every server-side feature
- C:\projects\mcp-sdk-php\docs\client-dev.md: 1,962-line comprehensive client development guide (10 parts + appendices), with embedded runnable code examples for every client-side feature
- C:\projects\mcp-sdk-php\docs\README.md: Annotated documentation index
- C:\projects\mcp-sdk-php\docs\tasks.md: Tasks extension guide (SEP-2663, experimental)
- C:\projects\mcp-sdk-php\docs\apps.md: MCP Apps extension guide (SEP-1865)
- C:\projects\mcp-sdk-php\docs\migration-v2.md, compatibility.md, shared-hosting-validation.md, testing.md: supporting guides
- C:\projects\mcp-sdk-php\examples\README.md: Feature-mapped index of 12 runnable examples (stateless server, negotiation client, elicitation pair, tasks pair, apps server, HTTP client, OAuth server, simple servers)
- C:\projects\mcp-sdk-php\webclient\README.md: Reference web client implementation

#### Feature Documentation Table

(S = docs/server-dev.md, C = docs/client-dev.md, E = examples/)

| #   | Feature                             | Documented? | Where                                                         | Has Examples?                       | Verdict |
| --- | ----------------------------------- | ----------- | ------------------------------------------------------------- | ----------------------------------- | ------- |
| 1   | Tools - listing                     | Yes         | S:172-218 (How Tools Work); C:299-335                         | Yes (many, incl. E/simple_server.php) | PASS  |
| 2   | Tools - calling                     | Yes         | S:178-218; C:376-411; README.md:107-141                       | Yes (many)                          | PASS    |
| 3   | Tools - text results                | Yes         | S:183-218, S:2961-2967 (return-type table); C:388-397         | Yes (many)                          | PASS    |
| 4   | Tools - image results               | Yes         | S:1626-1680 (Returning Images)                                | Yes (1 full example)                | PASS    |
| 5   | Tools - audio results               | Yes         | S:1726-1762 (Returning Audio)                                 | Yes (1 full example)                | PASS    |
| 6   | Tools - embedded resources          | Yes         | S:1764-1806 (Returning an Embedded Resource)                  | Yes (1 full example)                | PASS    |
| 7   | Tools - error handling              | Yes         | S:266-300 (Tool with Error Handling); C:399-411 (isError)     | Yes (2 examples)                    | PASS    |
| 8   | Tools - change notifications        | Yes         | S:2661-2728 (legacy pair), S:2606-2648 (subscriptions/listen); C:1344-1371 | Yes (3 examples)       | PASS    |
| 9   | Resources - listing                 | Yes         | S:663-708, 710-788; C:555-579                                 | Yes (many)                          | PASS    |
| 10  | Resources - reading text            | Yes         | S:674-708; C:581-615                                          | Yes (many)                          | PASS    |
| 11  | Resources - reading binary          | Yes         | S:790-834 (Binary Resource); C:602-615 (BlobResourceContents) | Yes (2 examples)                    | PASS    |
| 12  | Resources - templates               | Yes         | S:879-941 (Resource Templates); C:1854                        | Yes (2 examples + E/stateless_server.php) | PASS |
| 13  | Resources - template reading        | Yes         | S:879-941 (URI matching, `{var}`/`{+var}`, variable injection) | Yes (2 examples)                   | PASS    |
| 14  | Resources - subscribing             | Yes         | C:617-653 (legacy only); S:2606-2659 (modern `resourceSubscriptions` via subscriptions/listen) | Yes (1 example) | PASS |
| 15  | Resources - unsubscribing           | Yes         | C:617-653 (`unsubscribeResource`, legacy only)                | Yes (1 example)                     | PASS    |
| 16  | Resources - change notifications    | Yes         | S:2661-2728 (`sendResourceListChanged`), S:2644-2648 (`publishResourceUpdated`); C:1349-1371 | Yes (2 examples) | PASS |
| 17  | Prompts - listing                   | Yes         | S:434-479; C:449-476                                          | Yes (many)                          | PASS    |
| 18  | Prompts - getting simple            | Yes         | S:445-509; C:479-511                                          | Yes (many)                          | PASS    |
| 19  | Prompts - getting with arguments    | Yes         | S:458-477 (args auto-generated); C:491-495                    | Yes (2+ examples)                   | PASS    |
| 20  | Prompts - embedded resources        | Yes         | S:610-659 (EmbeddedResource in PromptMessage)                 | Yes (1 full example)                | PASS    |
| 21  | Prompts - image content             | Yes         | S:560-608 (Prompts with Images)                               | Yes (1 full example)                | PASS    |
| 22  | Prompts - change notifications      | Yes         | S:2661-2728 (`sendPromptListChanged`), S:2644 (`publishPromptsListChanged`); C:1370 | Yes (1 example) | PASS |
| 23  | Sampling - creating messages        | Yes         | S:2141-2368 (Part 9, both `prompt()` and `createMessage()`); C:1549-1594 (`onSampling`) | Yes (4 examples) | PASS |
| 24  | Elicitation - form mode             | Yes         | S:1810-2009 (Part 8); C:1100-1227                             | Yes (4+ examples + E/elicitation_server.php, elicitation_client.php) | PASS |
| 25  | Elicitation - URL mode              | Yes         | S:2011-2077 (`throwUrlRequired`); C:1251-1288 (`supportsUrlMode`) | Yes (2 full examples)           | PASS    |
| 26  | Elicitation - schema validation     | Yes         | S:1845 (schema restrictions), S:1869-1948 (constrained schemas); C:1223-1227, 1249 | Yes (schemas with minLength/maxLength/required in examples) | PASS |
| 27  | Elicitation - default values        | Yes         | C:1229-1249 (SEP-1034 `applyDefaults`); S:1927-1945 (`default` in schemas); E/elicitation_server.php:52 | Yes (2 examples) | PASS |
| 28  | Elicitation - enum values           | Yes         | S:1940-1945, 1990-1998 (enum fields); C:1191-1194, 1225       | Yes (3 examples)                    | PASS    |
| 29  | Elicitation - complete notification | Yes         | S:2064-2077 (`notifyUrlComplete()` + stateless-hosting caveat); C:1253-1259, 1276-1277 | Yes (snippet embedded in prose) | PASS |
| 30  | Roots - listing                     | Yes         | C:1596-1647 (Publishing Roots, `onListRoots`)                 | Yes (1 full example)                | PASS    |
| 31  | Roots - change notifications        | Yes         | C:1598-1646 (`sendRootsListChanged` in roots.php example)     | Yes (1 example)                     | PASS    |
| 32  | Logging - sending log messages      | Yes         | S:2551-2604 (`sendLogMessage`); C:1326-1332 (receiving)       | Yes (2 examples)                    | PASS    |
| 33  | Logging - setting level             | Yes         | C:1374-1392 (`setLoggingLevel`, legacy-only + modern `_meta` path); S:2570-2572 (server handler) | Yes (2 examples) | PASS |
| 34  | Completions - resource argument     | Yes         | S:2414-2449 (`completionForResourceTemplate`); C:546 (`ResourceReference`) | Yes (1 full example)   | PASS    |
| 35  | Completions - prompt argument       | Yes         | S:2376-2412, 2451-2473 (context-aware); C:514-544             | Yes (3 examples)                    | PASS    |
| 36  | Ping                                | Yes         | C:1400-1428 (`sendPing()`, era notes); S:1230-1232 (auto-registered handler) | Yes (1 full example) | PASS    |
| 37  | Streamable HTTP transport (client)  | Yes         | C:657-783 (Part 5); E/client_http.php                         | Yes (6+ examples)                   | PASS    |
| 38  | Streamable HTTP transport (server)  | Yes         | S:968-1226 (Part 4: deployment, SSE modes, DNS rebinding); E/simple_server_http.php | Yes (many)    | PASS    |
| 39  | SSE transport - legacy (client)     | No          | Not implemented (documented as intentional: C:776-782)        | No                                  | FAIL    |
| 40  | SSE transport - legacy (server)     | No          | Not implemented (documented as intentional: S:1116-1122)      | No                                  | FAIL    |
| 41  | stdio transport (client)            | Yes         | C:114-140, 1886-1893; E/elicitation_client.php etc.           | Yes (many)                          | PASS    |
| 42  | stdio transport (server)            | Yes         | S:83-104 (`run()`/`runStdio()`); E/simple_server_stdio.php    | Yes (many)                          | PASS    |
| 43  | Progress notifications              | Yes         | S:2488-2549 (`ProgressContext`, explicit totals); C:1334-1341, 1394-1398 | Yes (3 examples)         | PASS    |
| 44  | Cancellation                        | Yes         | S:1228-1277 (server handler + limits); C:1430-1547 (sending + reacting) | Yes (4 examples)          | PASS    |
| 45  | Pagination                          | Yes         | C:337-373 (Paginated Listings, cursor loop for all four list methods) | Yes (1 full example)        | PASS    |
| 46  | Capability negotiation              | Yes         | C:219-291 (capabilities inspection, `supportsFeature`); S:2374, 2669 (auto-advertising) | Yes (2 examples) | PASS |
| 47  | Protocol version negotiation        | Yes         | C:169-217 (Negotiating Protocol Eras, `protocolMode`); S:116-168; E/client_negotiation.php | Yes (2+ examples) | PASS |
| 48  | JSON Schema 2020-12 support         | Yes         | S:220-228, 302-356 (Custom Input Schemas: `$defs`, `$ref`, `oneOf` etc.), 2978-2989 | Yes (2 full examples) | PASS |
| —   | Tasks - get (experimental)          | Yes         | docs/tasks.md; C:413-422, 1850; E/tasks_client.php            | Yes                                 | INFO    |
| —   | Tasks - result (experimental)       | Yes         | docs/tasks.md (result inlined in final `tasks/get`)           | Yes                                 | INFO    |
| —   | Tasks - cancel (experimental)       | Yes         | docs/tasks.md; C:1850                                         | Yes                                 | INFO    |
| —   | Tasks - list (experimental)         | Partial     | docs/tasks.md (SDK is file-store based)                       | Yes (E/tasks_client.php)            | INFO    |
| —   | Tasks - status notifications (exp.) | Partial     | docs/tasks.md (polling model documented)                      | Yes                                 | INFO    |

#### Summary

**Total non-experimental features**: 48
**PASS (documented with examples)**: 46/48
**PARTIAL (documented, no examples)**: 0/48
**FAIL (not documented)**: 2/48

**Core features documented**: 36/36 (100%)
**All features documented with examples**: 46/48 (95.8%)

#### Tier Verdicts

**Tier 1** (all non-experimental features documented with examples): **FAIL** (marginal)

- #39 SSE transport - legacy (client): not implemented. The SDK deliberately implements only Streamable HTTP; the omission itself is documented with rationale (client-dev.md:776-782), but the feature has no implementation, docs, or examples.
- #40 SSE transport - legacy (server): not implemented, same deliberate omission (server-dev.md:1116-1122).
- Note: every **implemented** feature (46/46) is documented with prose AND at least one runnable or near-runnable example. The only gaps are the deprecated 2024-11-05 HTTP+SSE dual-endpoint transport, which the SDK intentionally excludes (the spec deprecated it in favor of Streamable HTTP). If the audit treats deliberately-unimplemented deprecated transports as out of scope, this SDK's documentation coverage is effectively complete.

**Tier 2** (basic docs covering core features): **PASS**

- All 36 core features are documented in docs/server-dev.md and docs/client-dev.md with embedded code examples; the two comprehensive guides (~5,000 lines combined) are exceptional for depth — per-feature prose, runnable examples, era/transport caveats, configuration reference tables, and cross-links to a feature-mapped examples/ directory.

**Auditor note on the raw Tier 1 verdict above**: the "FAIL (marginal)" recorded by the documentation evaluation is superseded for the final tier determination by the judgment call stated in the Requirements Summary — the two failing features are the deprecated legacy HTTP+SSE transport, deliberately not implemented, documented as an intentional exclusion, and not exercised by any current conformance requirement. Documentation is therefore treated as PASS for Tier 1.

---

## Policy Evaluation

### Policy Evaluation Assessment

**SDK path**: C:\projects\mcp-sdk-php
**Repository**: logiscape/mcp-sdk-php

---

#### 1. Dependency Update Policy: PASS

| File                      | Exists (CLI) | Content Verdict |
| ------------------------- | ------------ | --------------- |
| DEPENDENCY_POLICY.md      | No           | N/A             |
| docs/dependency-policy.md | Yes          | Substantive     |
| .github/dependabot.yml    | No           | N/A             |
| .github/renovate.json     | No           | N/A             |

**Evidence** (`C:\projects\mcp-sdk-php\docs\dependency-policy.md`, ~130 lines): The document explicitly describes how and when dependencies are updated, covering runtime, optional, dev, and pinned external tooling dependencies, plus PHP version-floor policy and security updates:

> "This document describes how dependencies are chosen, how they are updated, and when version-floor bumps are acceptable."

> "**Patch updates** ... done transparently. No PR; Composer picks them up. ... **Widening a declared range** ... a normal PR, with CI proving both ranges still build. ... **Narrowing or replacing a dependency**: treated as potentially breaking."

It also has a dedicated "Security updates to dependencies" section (assess exposure, force fixed release via constraint change, CHANGELOG + GitHub Security Advisory) and a "When the PHP version floor rises" section with concrete conditions.

**Verdict**: **PASS** — Substantive, dedicated dependency update policy describing update cadence, review process, floor bumps, and security handling.

---

#### 2. Roadmap: PASS

| File            | Exists (CLI) | Content Verdict |
| --------------- | ------------ | --------------- |
| ROADMAP.md      | Yes          | Substantive     |
| docs/roadmap.md | No           | N/A             |

**Evidence** (`C:\projects\mcp-sdk-php\ROADMAP.md`, ~300 lines): This is a forward-looking roadmap (not a changelog) with concrete work items tracking MCP spec components:

> "**Day-one support for each MCP specification release is the standing priority.** ... When a new revision enters its release-candidate window, implementing it (with full conformance and sensible back-compat for prior revisions) preempts whatever else is in flight."

It contains a "Current tier position (self-assessment)" table mapping each SEP-1730 criterion (conformance pass rate, new protocol features, triage, stable release, docs, dependency policy, roadmap) to current state; a "Now: v2 release" section; a detailed "Post-v2" section with ~9 concrete planned items for the `v2.x` line (e.g., `PdoSessionStore`, `PdoTokenStorage`, OAuth redirect-flow coordinator, SSRF policy seam); and spec-tracking items tied to specific SEPs: "**Server Cards (SEP-2127)**", "SEP-1932 (DPoP...) and SEP-1933 (Workload Identity Federation)", Tasks (SEP-2663) follow-ups, and 2026 MCP Roadmap horizon items.

**Verdict**:
- **Tier 1**: **PASS** — Published roadmap with concrete, sequenced work items explicitly tracking MCP spec revisions and SEPs.
- **Tier 2**: **PASS** — Includes an explicit SEP-1730 self-assessment against Tier 1 criteria and a transparent note on maintainer capacity ("the project currently has a single maintainer").

---

#### 3. Versioning Policy: PASS

| File                                 | Exists (CLI) | Content Verdict |
| ------------------------------------ | ------------ | --------------- |
| VERSIONING.md                        | No           | N/A             |
| docs/versioning.md                   | No           | N/A             |
| BREAKING_CHANGES.md                  | No           | N/A             |
| CONTRIBUTING.md (versioning section) | Yes          | Found           |

**Evidence** (`C:\projects\mcp-sdk-php\CONTRIBUTING.md`): Contains a clearly labeled "## Versioning policy" section covering all three required elements:

- **Versioning scheme**: "We follow [Semantic Versioning](https://semver.org/), interpreted for this SDK as follows: **Patch (`v1.7.X`)** — non-breaking bug fixes... **Minor (`v1.X`)** — breaking changes to the public API or documented flows... **Major (`v2`)** — aligned with the wider MCP ecosystem."
- **What constitutes a breaking change**: Ground rule #3 — "breaking change to the public API or a documented flow" — with a note that ambiguous cases are resolved on the PR.
- **How breaking changes are communicated**: "Breaking changes are called out in [CHANGELOG.md](CHANGELOG.md) and in the release notes for the tag."

Note the scheme is unconventional (breaking changes land in *minor* releases, with major reserved for ecosystem-wide v2 alignment), but it is explicitly documented, which is what the criterion requires.

**Verdict**:
- **Tier 1**: **PASS** — CONTRIBUTING.md has a labeled "Versioning policy" section documenting the scheme, breaking-change definition, and communication channel.
- **Tier 2**: **N/A** — only requires stable release.

---

#### Overall Policy Summary

| Policy Area              | Tier 1 | Tier 2 |
| ------------------------ | ------ | ------ |
| Dependency Update Policy | PASS   | PASS   |
| Roadmap                  | PASS   | PASS   |
| Versioning Policy        | PASS   | N/A    |

All three policy areas pass at Tier 1 level. Every file the CLI reported present is substantive — no placeholders. No automated dependency tooling (dependabot/renovate) is configured, but the written policy in `docs/dependency-policy.md` satisfies the requirement on its own.
