# Getting Support

This document describes where to get help with `logiscape/mcp-sdk-php`. Please
read it before opening a thread so your question lands in the right place.

## Where to go

**Bugs in the SDK itself**
→ Open a [bug report](https://github.com/logiscape/mcp-sdk-php/issues/new?template=bug_report.yml).
Include SDK version, PHP version, transport, and a minimal reproduction.

**Feature requests or spec tracking**
→ Open a [feature request](https://github.com/logiscape/mcp-sdk-php/issues/new?template=feature_request.yml).
If the request corresponds to a published MCP Specification Enhancement
Proposal (SEP), please link it.

**Questions on how to use the SDK**
→ Open a [question](https://github.com/logiscape/mcp-sdk-php/issues/new?template=question.yml).
Please search existing issues first — the answer may already be there.

**Security vulnerabilities**
→ Do **not** open a public issue. Use
[GitHub Security Advisories](https://github.com/logiscape/mcp-sdk-php/security/advisories/new)
or follow the fallback channel in [SECURITY.md](SECURITY.md).

**General discussion about MCP itself (not the PHP SDK)**
→ The upstream Model Context Protocol community is at
[modelcontextprotocol.io](https://modelcontextprotocol.io) and on its
[GitHub org](https://github.com/modelcontextprotocol). Questions about the
protocol specification, other SDKs, or the conformance suite itself are best
asked there.

## Before you ask

A little homework usually gets you a faster and better answer:

1. **Check the documentation.** The main entry points are the
   [README](README.md), the
   [server development guide](docs/server-dev.md),
   [`docs/testing.md`](docs/testing.md),
   [`docs/compatibility.md`](docs/compatibility.md), and
   [`examples/`](examples/).
2. **Search existing issues**, including closed ones. Many common questions
   already have answers.
3. **Try to narrow the problem.** If your application is complex, reducing
   the failure to a short reproduction (even a few dozen lines) is the single
   most helpful thing you can do.

## What we cannot help with

The maintainer's time is limited, so please don't expect:

- Debugging of your business logic that happens to call into the SDK.
- Hosting-environment specific troubleshooting beyond what's already in
  [`examples/server_auth/README.md`](examples/server_auth/README.md) and
  [`docs/compatibility.md`](docs/compatibility.md) — your hosting provider is
  better placed to help with their Apache/cPanel quirks.
- Support for the official PHP SDK
  ([`modelcontextprotocol/php-sdk`](https://github.com/modelcontextprotocol/php-sdk)),
  which is a separate project with its own maintainers.

## Response expectations

This is a volunteer project. Issue responses are best-effort. We try to
acknowledge new issues within a couple of weeks and keep P0 (critical) issues
moving faster than that. The specific response-time targets we are working
toward are documented in [ROADMAP.md](ROADMAP.md).

If an issue has gone quiet, a polite follow-up comment after two weeks is
welcome — we may simply have missed the notification.
