# Issue and Pull Request Labels

This project follows the
[MCP SDK Working Group's label conventions](https://modelcontextprotocol.io/community/sdk-tiers#issue-triage-labels)
(published in [SEP-1730](https://modelcontextprotocol.io/community/seps/1730-sdks-tiering-system)).
The shared vocabulary lets the MCP ecosystem — and anyone running reports
across SDKs — reason consistently about issue state.

Contributors do not need to apply labels when opening an issue or PR; a
maintainer will label it during triage. This document exists so everyone
understands what the labels mean when they see them.

## Type — pick one

| Label         | Meaning                          |
| ------------- | -------------------------------- |
| `bug`         | Something isn't working.         |
| `enhancement` | Request for new or expanded functionality. |
| `question`    | Asking for information or clarification. |

When GitHub's native issue types are enabled on the repo, those satisfy the
SEP-1730 requirement and these labels are optional.

## Status — pick one

| Label                | Meaning                                                         |
| -------------------- | --------------------------------------------------------------- |
| `needs confirmation` | Unclear whether the issue is still relevant. Often applied to new bug reports until a maintainer can reproduce. |
| `needs repro`        | There isn't enough information to reproduce the problem. Reporter follow-up required. |
| `ready for work`     | Triaged; enough information to start implementing.              |
| `good first issue`   | Scoped small enough that a newcomer can reasonably take it on.  |
| `help wanted`        | Contributions welcome from anyone familiar with the codebase.   |

## Priority — only when actionable

These labels are applied to actionable work — confirmed bugs or
well-scoped enhancements — not to questions or `needs repro` items.

| Label | Meaning                                                              |
| ----- | -------------------------------------------------------------------- |
| `P0`  | **Critical.** Core-functionality failure preventing basic MCP operations (connect, message exchange, tools / resources / prompts). Or a security vulnerability with CVSS ≥ 7.0. |
| `P1`  | Significant bug affecting many users, or a high-value feature gap.   |
| `P2`  | Moderate issue or worthwhile feature request.                        |
| `P3`  | Nice-to-have. Rare edge case or minor polish.                        |

Note: P0 response expectations are discussed in [SECURITY.md](../SECURITY.md)
and in the roadmap's tier self-assessment
([ROADMAP.md](../ROADMAP.md)). The SEP-1730 Tier 1 target is seven days to
resolution; we are working toward that but do not commit to it yet — see the
roadmap for the full context.

## Area labels

Beyond the SEP-1730 scheme, maintainers may apply area labels to help with
routing. These are advisory only.

| Example label       | Rough area                                     |
| ------------------- | ---------------------------------------------- |
| `area:transport`    | `src/Client/Transport/` or `src/Server/Transport/` |
| `area:auth`         | OAuth on either side of the wire               |
| `area:conformance`  | The conformance harness and baseline           |
| `area:webclient`    | The `webclient/` reference implementation      |
| `area:docs`         | Anything under `docs/` or repo-root Markdown   |

New area labels may be added as the project grows.

## A note on relabeling

Triagers may change a label at any time — for example, reclassifying a
`question` as a `bug` once it turns out the SDK is genuinely
misbehaving, or lowering a `P1` to `P2` as scope becomes clearer. That is
normal and does not reflect on the reporter.

## See also

- [CONTRIBUTING.md](../CONTRIBUTING.md)
- [SUPPORT.md](../SUPPORT.md)
- [SECURITY.md](../SECURITY.md)
- [SEP-1730: SDKs Tiering System](https://modelcontextprotocol.io/community/seps/1730-sdks-tiering-system)
