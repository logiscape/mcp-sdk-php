# Conformance Testing

This directory holds everything the SDK needs to run against the official
[MCP Conformance Suite](https://github.com/modelcontextprotocol/conformance).
The suite validates both server and client behaviour against the published
MCP specification; integrating it here means regressions surface in CI rather
than in user applications.

The no-shortcuts principle applies here more strictly than anywhere else in
the repository — see the section of that name below.

## Files

| File                            | Purpose                                                                 |
| ------------------------------- | ----------------------------------------------------------------------- |
| `everything-server.php`         | Example MCP server exposing the full primitive set the suite exercises. The conformance tool is run against it. |
| `everything-client.php`         | Example MCP client driven by the conformance tool via env vars and command-line args. |
| `run-conformance.php`           | PHP driver that starts `everything-server.php` under PHP's built-in web server, invokes the official conformance tool, and cleans up. Also handles the client-mode path. |
| `conformance-baseline.yml`      | Expected-failure baseline. Tests listed here are *known* to fail today, each with a root cause. Regressions outside this list fail CI. |

The external conformance tool version is pinned in
[`../package.json`](../package.json) and referenced from
[`../.github/workflows/conformance.yml`](../.github/workflows/conformance.yml).

## Running locally

From the repository root:

```bash
npm install                    # installs the pinned conformance tool
composer conformance           # runs both server and client suites
composer conformance-server    # server suite only
composer conformance-client    # client suite only

# a single scenario:
php conformance/run-conformance.php server tools-list
```

For the full test-stack context (PHPUnit, PHPStan, Inspector, real-world AI
clients), see [`../docs/testing.md`](../docs/testing.md).

## Interpreting results

The conformance tool uses the baseline to distinguish real regressions from
known limitations:

- **A previously-passing test starts failing** → regression. CI fails. Fix
  the SDK, or — in the rare case the failure is genuinely legitimate — add a
  documented entry to the baseline (see the no-shortcut rule below).
- **A test listed in the baseline starts passing** → the baseline is stale.
  Remove the entry.
- **Any other pass/fail** matches the baseline expectations and CI is happy.

Exit codes from `composer conformance` reflect this: a zero exit means "no
change" relative to the baseline, non-zero means either a new regression or a
stale entry.

## The baseline

[`conformance-baseline.yml`](conformance-baseline.yml) is the only file in
the repository where "this test is expected to fail" is encoded. Every entry
carries a reason comment.

At the time of writing (suite `v0.1.16`) the baseline contains:

- `auth/client-credentials-jwt` — the SDK does not implement the
  `client_credentials` grant with JWT assertions yet.
- `auth/client-credentials-basic` — the SDK does not implement the
  `client_credentials` grant with HTTP Basic client authentication yet.
- `auth/cross-app-access-complete-flow` — partial: 8 of 10 assertions pass,
  two remain.

All three are optional MCP Extensions. Closing them is tracked in
[`../ROADMAP.md`](../ROADMAP.md).

## The no-shortcut rule

The baseline is a safety net, not a hiding place.

Do not add a test to the baseline just to make CI green. The baseline is
acceptable when, and only when, *all* of the following are true:

1. The failure is in an optional or extension feature the SDK does not yet
   implement, or is partially implementing in good faith.
2. The SDK's code paths are *actually exercised* by the test — we have not
   shimmed around the SDK or returned a hard-coded response to look compliant.
3. The root cause is documented in the baseline entry in one or two
   sentences.
4. Fixing it is tracked somewhere (a roadmap item, an issue, or a comment
   that says explicitly "not pursuing").

If a test is failing because the SDK is wrong, the fix is in `src/` — not in
this YAML file. A passing score obtained by routing around the SDK is a lie
the project doesn't tell. If you're uncertain whether a particular fix
qualifies, raise it on the PR before committing it.

## Adding new scenarios

When the upstream conformance tool ships new scenarios:

1. Bump the pinned version in [`../package.json`](../package.json).
2. Update the workflow reference in
   [`../.github/workflows/conformance.yml`](../.github/workflows/conformance.yml).
3. Re-run the suite. Any brand-new failures either get fixed in the SDK or
   — meeting the four conditions above — added to the baseline with a
   reason.
4. Note the tool-version bump in [`../CHANGELOG.md`](../CHANGELOG.md) under
   `[Unreleased]`.

New scenarios that exercise features we already support but reveal genuine
bugs should result in an SDK fix, not a baseline entry — that's what the
suite is for.

## Troubleshooting

- **Server scenario hangs**: the built-in PHP server under
  `php -S 127.0.0.1:3000 conformance/everything-server.php` may not be
  ready by the time the conformance tool connects. The CI workflow
  (`../.github/workflows/conformance.yml`) waits for a valid HTTP status
  before starting the suite; if you're running locally, re-run after a
  second of delay if the first attempt fails.
- **Client scenarios require env vars**: the conformance tool supplies
  `MCP_CONFORMANCE_SCENARIO`, `MCP_CONFORMANCE_CONTEXT`, and the test server
  URL. If you're driving `everything-client.php` by hand, you must supply
  these yourself.
- **A scenario fails only on your machine**: check PHP extensions
  (`ext-curl`, `ext-json`, `ext-mbstring`), and check `output_buffering` is
  off. A quick `composer check` should also pass — if it doesn't, fix that
  first.

## See also

- [MCP Conformance repo](https://github.com/modelcontextprotocol/conformance)
- [SDK Integration guide](https://github.com/modelcontextprotocol/conformance/blob/main/SDK_INTEGRATION.md)
- [`../CONTRIBUTING.md`](../CONTRIBUTING.md)
- [`../docs/testing.md`](../docs/testing.md)
- [`../ROADMAP.md`](../ROADMAP.md)
