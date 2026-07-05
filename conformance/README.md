# Conformance Testing

This directory holds everything the SDK needs to run against the official
[MCP Conformance Suite](https://github.com/modelcontextprotocol/conformance).
The suite validates both server and client behaviour against the published
MCP specification; integrating it here means regressions surface in CI rather
than in user applications.

The no-shortcuts principle applies here more strictly than anywhere else in
the repository — see the section of that name below.

## Two tracks: stable and draft

During v2 development the SDK runs the suite on two independently pinned
tool versions (see [`../docs/v2-development-plan.md`](../docs/v2-development-plan.md),
WS7):

- **Stable track** — the pinned stable release (`latest` npm dist-tag),
  carrying the published-spec scenarios. This is the legacy regression
  gate, run by `composer conformance` against `conformance-baseline.yml`.
- **Draft track** — the pinned `0.2.0-alpha` line (the upstream
  RC-validation track for the `2026-07-28` revision), installed under the
  npm alias `conformance-draft` so both pins coexist. Run by
  `composer conformance-draft` with `--suite draft` against its own
  `conformance-draft-baseline.yml`.

The two baselines are tied to different installed tool versions and are
curated independently: alpha-line churn re-curates only the draft baseline,
never the stable one. When the stable `0.2.0` tool ships (around the final
spec), the tracks converge back to a single pin and a single baseline.

The runner also tells `everything-client.php` which track spawned it
(`--track=draft` is appended to the client command on the draft track):
the draft track runs the SDK's spec-aligned defaults, while the stable
track opts into the SDK's explicit published-spec compatibility options
where stable scenarios assume them (currently
`allowUnboundClientCredentials`, because the tool supplies pre-registered
credentials without the issuer context the `2026-07-28` Authorization
Server Binding rule requires — see the `auth/pre-registration` entry in
the draft baseline).

## Files

| File                              | Purpose                                                                 |
| --------------------------------- | ----------------------------------------------------------------------- |
| `everything-server.php`           | Example MCP server exposing the full primitive set the suite exercises. The conformance tool is run against it. |
| `everything-client.php`           | Example MCP client driven by the conformance tool via env vars and command-line args. |
| `run-conformance.php`             | PHP driver that starts `everything-server.php` under PHP's built-in web server, invokes the official conformance tool, and cleans up. Also handles the client-mode path and the draft track. |
| `conformance-baseline.yml`        | Expected-failure baseline for the stable track. Tests listed here are *known* to fail today, each with a root cause. Regressions outside this list fail CI. |
| `conformance-draft-baseline.yml`  | Expected-failure baseline for the draft track (`2026-07-28` scenarios). Entries name the v2 workstream that will make them pass and only shrink as workstreams complete. |

Both external tool versions are pinned in
[`../package.json`](../package.json) (the stable dependency and the
`conformance-draft` alias) and referenced from
[`../.github/workflows/conformance.yml`](../.github/workflows/conformance.yml).

## Running locally

From the repository root:

```bash
npm install                       # installs both pinned conformance tools
composer conformance              # stable track: both server and client suites
composer conformance-server       # stable track: server suite only
composer conformance-client       # stable track: client suite only
composer conformance-draft        # draft track: 2026-07-28 draft suites
composer conformance-draft-server # draft track: server suite only
composer conformance-draft-client # draft track: client suite only

# a single scenario:
php conformance/run-conformance.php server tools-list
php conformance/run-conformance.php server-draft server-stateless
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

As of the WS3 milestone (suite `v0.1.16`) **the stable baseline is empty**:
100% of stable scenarios pass on both the server and client tracks. Its
last three entries — the `client_credentials` grant variants and the
cross-app-access flow, all optional MCP Extensions — were closed by WS3's
authorization hardening.

The draft track's baseline
([`conformance-draft-baseline.yml`](conformance-draft-baseline.yml))
carries the remaining `2026-07-28` entries while v2 is in pre-release, each
annotated with its root cause: bugs/staleness in the pinned alpha tool
itself are documented there (and re-checked at every draft-pin bump)
rather than worked around in the SDK.

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

1. Bump the pinned version in [`../package.json`](../package.json) — the
   stable dependency for stable releases, the `conformance-draft` alias for
   new `0.2.0-alpha` builds — and run `npm install`.
2. For stable bumps, update the workflow reference in
   [`../.github/workflows/conformance.yml`](../.github/workflows/conformance.yml)
   (the draft job reads the pin from `package.json` automatically).
3. Re-run the affected track. Any brand-new failures either get fixed in
   the SDK or — meeting the four conditions above — added to that track's
   baseline with a reason. A bump on one track never touches the other
   track's baseline.
4. Note the tool-version bump in [`../CHANGELOG.md`](../CHANGELOG.md) under
   `[Unreleased]`.

Draft-pin bumps should happen deliberately at v2 milestone boundaries, not
ad hoc — the alpha line moves quickly and each bump means re-curating the
draft baseline (see the development plan, WS7).

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
