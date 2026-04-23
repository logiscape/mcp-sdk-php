# Security Policy

Thank you for taking the time to report a security issue. We take every report
seriously, even if it turns out to be a non-issue after investigation.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security reports.

Preferred channel:

- **GitHub Security Advisories** — open a private report at
  [https://github.com/logiscape/mcp-sdk-php/security/advisories/new](https://github.com/logiscape/mcp-sdk-php/security/advisories/new).
  This keeps the discussion private until a fix and coordinated disclosure
  are ready.

Fallback channel:

- **Email** — `josh@logiscape.com`. Please include the word `SECURITY` in the
  subject line.

When reporting, it helps us a lot if you can include:

- The affected SDK version (tag or commit).
- PHP version and operating system.
- Transport in use (stdio, HTTP, HTTP+SSE) and whether OAuth is involved.
- A minimal reproduction or proof-of-concept.
- The impact as you see it, and any suggested mitigation.

## Response expectations

This SDK is currently maintained by a small group of volunteer contributors led
by a single core maintainer (see [GOVERNANCE.md](GOVERNANCE.md)). We aim to:

- Acknowledge new security reports within **7 days**.
- Triage and confirm or reject a report within **14 days** of acknowledgement.
- Ship a fix for confirmed high-severity issues (CVSS ≥ 7.0) as quickly as we
  reasonably can — typically within a few weeks of confirmation, depending on
  complexity.

These are best-effort targets, not guarantees. If the maintainer's availability
means a report will take longer, the reporter will be told directly rather than
left in silence. See the response-time goals in [ROADMAP.md](ROADMAP.md) for
the targets we are working toward as the contributor base grows.

## Coordinated disclosure

For confirmed issues, we will work with the reporter to agree on a disclosure
timeline. The default is public disclosure once a fix is released and users
have had a reasonable window to upgrade. Credit will be given to the reporter
unless they prefer to remain anonymous.

## Supported versions

| Version    | Status                             |
| ---------- | ---------------------------------- |
| `v1.7.x`   | Actively supported. Security fixes are released here. |
| `v1.6.x`   | No longer receiving non-critical fixes. Critical security fixes considered case-by-case. |
| `< v1.6`   | End of life. Please upgrade.       |

Users are expected to track the latest `v1.x` release. Breaking changes
land in minor releases (`v1.X`) and are called out in
[CHANGELOG.md](CHANGELOG.md) and the release notes. A future `v2` will
only be cut in alignment with the wider MCP ecosystem; see the
versioning policy in [CONTRIBUTING.md](CONTRIBUTING.md).

## Scope

The following are **in scope** for security reports:

- SDK source code under `src/`.
- Conformance and test fixtures if they can be turned into a vulnerability in
  consuming code.
- The reference examples under `examples/` and the web client under
  `webclient/` when used as documented.
- Documentation that describes deployment patterns
  (e.g. [`examples/server_auth/README.md`](examples/server_auth/README.md))
  where a documented step introduces a flaw.

The following are **out of scope**:

- Vulnerabilities in the PHP runtime, Apache/Nginx, cPanel, or third-party
  hosting environments themselves. Please report those upstream.
- Vulnerabilities in user-supplied OAuth authorization servers, token
  validators, or token issuers. The SDK provides hooks; the security of the
  issuer stack is the deployer's responsibility.
- Vulnerabilities that require physical access to the server, a compromised
  developer machine, or an attacker who already controls the PHP process.
- Denial of service achievable only by an authenticated client with legitimate
  access, unless it is disproportionate to what a normal request can cost.

Deploying an MCP server — especially one with OAuth — safely in a shared-hosting
environment is covered in [`examples/server_auth/README.md`](examples/server_auth/README.md)
and [`docs/compatibility.md`](docs/compatibility.md). If you believe one of
those documents itself gives unsafe advice, that *is* in scope — please report it.

## Questions that aren't security

General support questions belong in [SUPPORT.md](SUPPORT.md), not here. If you
are unsure whether something counts as a security issue, err on the side of
reporting it privately — we would rather decline a report than have one missed.
