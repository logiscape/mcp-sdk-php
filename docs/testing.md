# Testing Guide

This document describes the full test stack for `logiscape/mcp-sdk-php` and
how to run each layer. The overlap between layers is intentional: unit tests
catch regressions in isolated components, PHPStan catches type and control-flow
mistakes, conformance tests catch spec deviations, the MCP Inspector catches
subtle protocol behaviour drifts, and real-world AI applications catch
integration surprises that none of the above would.

Before opening a pull request that touches protocol or transport code, please
run at least the first four layers. See [CONTRIBUTING.md](../CONTRIBUTING.md)
for when each is expected.

## 1. Unit tests (PHPUnit)

```bash
composer test
# narrower examples:
./vendor/bin/phpunit tests/Server/ServerSessionTest.php
./vendor/bin/phpunit --filter testInitializeHandshake tests/Server/ServerSessionTest.php
```

Conventions:

- Test classes are `final` and extend `PHPUnit\Framework\TestCase`.
- Test layout mirrors `src/` —
  `tests/Server/ServerSessionTest.php` tests `src/Server/ServerSession.php`.
- `Mcp\Shared\MemoryStream` and `InMemoryTransport` let tests exercise sessions
  without real I/O.

## 2. Static analysis (PHPStan)

```bash
composer analyse
```

Configuration lives in `phpstan.neon`. If you intentionally introduce a
baseline violation, explain why in your PR description.

## 3. Combined regression check

```bash
composer check
```

Runs PHPUnit followed by PHPStan. This should pass on every PR.

## 4. MCP Conformance suite

Not part of `composer check` because it requires Node.js (the official
conformance tool version is pinned in [`package.json`](../package.json)).
Install once:

```bash
npm install
```

Then:

```bash
composer conformance            # both server and client suites
composer conformance-server
composer conformance-client
# single scenario:
php conformance/run-conformance.php server tools-list
```

How it works and how to interpret results is in
[`conformance/README.md`](../conformance/README.md). **Do not** edit
`conformance/conformance-baseline.yml` to paper over a regression — that is
explicitly against the project's no-shortcut rule.

Run this when your change touches:

- `src/Shared/BaseSession.php` or anything in `src/Shared/`
- `src/Server/` session, handler, or transport code
- `src/Client/ClientSession.php` or transports
- `src/Types/` protocol types
- `src/Server/McpServer.php`
- authorization code under `src/Client/Auth/` or `src/Server/Auth/`

## 5. Official MCP Inspector

The [Model Context Protocol Inspector](https://github.com/modelcontextprotocol/inspector)
is a reference tool from the MCP project itself. It speaks the protocol
honestly and is invaluable for catching differences between what the spec
says a server should do and what our server actually does.

### Against a local server

```bash
npx @modelcontextprotocol/inspector php conformance/everything-server.php
```

Then open the Inspector UI in the browser. Try:

- **Tools tab**: list tools and call each one; check argument validation,
  structured output, and any icons or rich metadata render correctly.
- **Prompts tab**: list and fetch each prompt; confirm completion suggestions
  fire on prompt-argument inputs.
- **Resources tab**: list and read resources and resource templates.
- **Elicitation and sampling** (if the example exercises them): confirm the
  Inspector's elicitation card renders and responses flow back through the
  SDK correctly.

For OAuth-protected HTTP servers, follow
[`examples/server_auth/README.md`](../examples/server_auth/README.md) to set up
tokens, then supply the token in the Inspector's Authorization header field.

### What to look for

- Is the negotiated protocol version what you expect?
- Do advertised capabilities match what handlers you actually registered?
- Do notifications (progress, resource updates, elicitation) round-trip
  cleanly in the Inspector's event log?
- Does the server fail cleanly when the client does something unexpected
  (e.g., cancels a request mid-flight)?

Inspector smoke-tests are encouraged for any protocol-touching PR. A one-line
note in the PR description ("Verified tools + prompts in Inspector against
`conformance/everything-server.php`") is enough.

## 6. Real-world AI applications

Real clients find bugs automated suites can't, because they exercise the SDK
the way end users will. Contributors are **encouraged, not required** to run
at least one of the following before submitting user-visible changes.

### Claude Code

Claude Code can connect to local and remote MCP servers.

Then start a Claude Code session and ask the model to use one of your tools.
Watch for:

- Tool registration showing up (usually via `/mcp` or similar diagnostic).
- Tool calls succeeding with realistic argument shapes the LLM produces.
- Structured output rendering sensibly in the conversation.

Consult the official Claude Code documentation for the latest MCP details.

### OpenAI API

The OpenAI API Platform playground allows users to test remote HTTP MCP
servers, both public and OAuth-protected with an access token. Consult the
OpenAI documentation for the latest details.

### What to report

If either Claude Code or the OpenAI API behaves unexpectedly (but the
Inspector and conformance suite look fine), that usually indicates either a
bug in the SDK or a spec ambiguity. Open a bug report and include:

- The client you used and its version.
- The request/response sequence (from the SDK's logger if you can).
- What you expected vs. what happened.

## Troubleshooting

- **PHPUnit cannot find tests**: run `composer dump-autoload -o` and retry.
- **PHPStan reports a type error you disagree with**: please don't add a
  baseline entry silently — open an issue or discuss it on the PR.
- **Conformance shows a new failure**: first confirm against a clean
  `main`. If the failure reproduces on `main`, it's an existing regression
  and worth its own issue. If it's caused by your branch, fix it or
  investigate whether it's a fair failure of an optional extension (in which
  case it may belong in the baseline with a documented reason, *not*
  silently suppressed).
- **Inspector can't connect**: check stdio vs HTTP transport mismatch, then
  check that `ext-curl` and `ext-json` are enabled on the CLI PHP.

## See also

- [CONTRIBUTING.md](../CONTRIBUTING.md)
- [`conformance/README.md`](../conformance/README.md)
- [`docs/compatibility.md`](compatibility.md)
- [Server Development Guide](server-dev.md)
