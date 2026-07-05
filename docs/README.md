# Documentation Index

Every document in this repository, labeled by audience. Paths are stable —
link to them freely.

## User guides — building with the SDK

| Document | What it covers |
| --- | --- |
| [server-dev.md](server-dev.md) | Building MCP servers with `McpServer`: tools, prompts, resources, deployment, OAuth protection, structured output, elicitation, sampling, completions, notifications. Teaches the `2026-07-28` stateless model as the default with legacy behavior marked. |
| [client-dev.md](client-dev.md) | Building MCP clients with `Client` / `ClientSession`: dual-era negotiation, tools/prompts/resources, HTTP transport configuration, OAuth (CLI and web hosting), elicitation/sampling servicing, session resume. |
| [migration-v2.md](migration-v2.md) | Upgrading a project from v1 to v2 — every breaking and behavioral change, with runnable before/after code, plus the deprecated-features registry. |
| [tasks.md](tasks.md) | The Tasks extension (SEP-2663): long-running tool calls, polling, in-task input, cancellation — server and client side. |
| [apps.md](apps.md) | The MCP Apps extension (SEP-1865): host-rendered tool UIs via `McpServer::ui()`. |
| [compatibility.md](compatibility.md) | The cPanel/Apache/PHP-FPM compatibility rules: what works everywhere, what degrades gracefully, and required `.htaccess` configuration. |
| [../examples/README.md](../examples/README.md) | Index of runnable examples — one per major SDK feature. |
| [../webclient/README.md](../webclient/README.md) | The bundled web-based MCP test client (reference implementation for web hosting). |

## Contributor guides — working on the SDK

| Document | What it covers |
| --- | --- |
| [testing.md](testing.md) | The canonical test-stack reference: PHPUnit, PHPStan, both conformance tracks, MCP Inspector / Claude Code / OpenAI harnesses. |
| [../conformance/README.md](../conformance/README.md) | How the dual-track conformance harness works, baseline curation, and the no-shortcuts rule. |
| [dependency-policy.md](dependency-policy.md) | How dependencies are declared, bumped, and retired. |
| [labels.md](labels.md) | Issue labels, aligned with the MCP SDK Working Group conventions. |
| [../CONTRIBUTING.md](../CONTRIBUTING.md) | Local setup, coding standards, versioning policy, review flow. |
| [../AGENTS.md](../AGENTS.md) | Orientation for AI coding agents: architecture map, test commands, v2 process rules. |

## Process and governance

| Document | What it covers |
| --- | --- |
| [v2-development-plan.md](v2-development-plan.md) | The main working plan for v2 development — workstreams, milestones, release gates, and the development record. |
| [api-audit-v2.md](api-audit-v2.md) | The v1 → v2 PHP API audit (source material for the migration guide). |
| [../ROADMAP.md](../ROADMAP.md) | Direction, SDK-tier self-assessment, and what is being worked on. |
| [../CHANGELOG.md](../CHANGELOG.md) | Structured release history (Keep a Changelog). |
| [../GOVERNANCE.md](../GOVERNANCE.md) | How decisions are made and how to become a trusted contributor. |
| [../SECURITY.md](../SECURITY.md) | How to report vulnerabilities. |
| [../SUPPORT.md](../SUPPORT.md) | Where to ask questions. |
| [../CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md) | Contributor Covenant 2.1, adopted by reference. |
