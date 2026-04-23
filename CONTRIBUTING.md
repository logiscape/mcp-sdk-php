# Contributing to `logiscape/mcp-sdk-php`

Thank you for wanting to contribute. This SDK is small, community-maintained,
and focused on tracking the MCP specification faithfully in pure PHP, so it
runs comfortably on typical shared hosting (cPanel / Apache / PHP-FPM) in
addition to CLI environments.

Before submitting code, please skim this document — it is short, and it will
save everyone time.

## Ways to help

- Report a bug with a minimal reproduction
  ([new bug report](https://github.com/logiscape/mcp-sdk-php/issues/new?template=bug_report.yml)).
- Propose a feature that closes a gap against the MCP spec
  ([new feature request](https://github.com/logiscape/mcp-sdk-php/issues/new?template=feature_request.yml)).
- Submit a pull request that fixes a bug, closes a conformance gap, improves
  documentation, or strengthens tests.
- Review other people's pull requests. Good review is scarce and valuable.

## Ground rules

A handful of principles apply to every change:

1. **Spec-faithful, no shortcuts.** Implementations must match the intent of
   the MCP specification. We do not bypass SDK code paths or hand-craft
   responses purely to green a conformance test. A test we cannot honestly
   pass goes in
   [`conformance/conformance-baseline.yml`](conformance/conformance-baseline.yml)
   with a documented root cause. See
   [`conformance/README.md`](conformance/README.md) for the longer version.
2. **cPanel / Apache compatibility is mandatory for core MCP features.** If a
   change breaks a core feature under shared hosting, it's not ready. Features
   that truly cannot be made shared-hosting-compatible (for instance, certain
   long-lived connections) still ship — for spec alignment — but must fail
   gracefully rather than crash the SDK. See
   [`docs/compatibility.md`](docs/compatibility.md).
3. **Avoid breaking changes when a non-breaking alternative exists.** When a
   breaking change to the public API or a documented flow is genuinely
   necessary, it bumps the minor version (`v1.X`), not the patch
   (`v1.7.X`), and it is called out in [CHANGELOG.md](CHANGELOG.md).
4. **Be kind.** The [Code of Conduct](CODE_OF_CONDUCT.md) applies.

## Local development setup

Requirements:

- PHP 8.1 or later with `ext-curl` and `ext-json` (see
  [`docs/dependency-policy.md`](docs/dependency-policy.md) for optional and
  dev dependencies).
- Composer.
- Node.js (required to run the MCP conformance suite — the tool version is
  pinned in [`package.json`](package.json)).

Clone the repo, then:

```bash
composer install
npm install           # for the conformance tests
```

## Test stack

We take protocol behaviour seriously and maintain several overlapping checks.
All of them should pass before a pull request is ready for review.

- **Unit tests (PHPUnit 10+)**

  ```bash
  composer test
  # or narrower:
  ./vendor/bin/phpunit tests/Server/ServerSessionTest.php
  ```

- **Static analysis (PHPStan)**

  ```bash
  composer analyse
  ```

- **Combined regression check** — run this before every PR:

  ```bash
  composer check
  ```

- **MCP Conformance suite** — not part of `composer check` because it needs
  Node.js. Run it when your change touches protocol handling, transports,
  session management, `McpServer`, or anything under `src/Shared/`:

  ```bash
  composer conformance
  ```

  See [`conformance/README.md`](conformance/README.md) for how to interpret
  the baseline and when it is — and is not — acceptable to update it.

- **Official MCP Inspector** — for anything the user sees at the protocol
  level, a quick spot-check with the
  [official Inspector](https://github.com/modelcontextprotocol/inspector)
  against one of the example servers catches surprises no automated suite
  will. Instructions in [`docs/testing.md`](docs/testing.md).

- **Real-world AI applications** — contributors are *encouraged*, not required,
  to smoke-test user-visible changes against a real MCP client such as Claude
  Code or the OpenAI API playground. This is often where rough edges surface.
  Recipes in [`docs/testing.md`](docs/testing.md).

## Coding standards

- **PHP 8.1+.** Every file starts with `declare(strict_types=1);`.
- **PSR-4 autoloading** under the `Mcp\` namespace (see
  [`composer.json`](composer.json)).
- **Type hints are mandatory.** Parameters, return types, and typed handler
  callables. `mixed` is allowed only where the type really is variable.
- **Comments**: sparing. The non-obvious "why" is worth writing down; the
  "what" is usually obvious from the code. No multi-paragraph docblocks
  unless the method really needs them.
- **Tests live in `tests/`** and mirror the source tree
  (`tests/Server/ServerSessionTest.php` tests `src/Server/ServerSession.php`).
  Test classes are `final` and extend `PHPUnit\Framework\TestCase`.
- **Logging**: components take an optional PSR-3 `LoggerInterface` and fall
  back to `NullLogger`. Examples may use Monolog for illustration — the SDK
  itself does not depend on it.
- **Error handling**: protocol errors throw `Mcp\Shared\McpError`, transport
  errors throw `RuntimeException`, invalid parameters throw
  `InvalidArgumentException`. These are converted to JSON-RPC error
  responses automatically.

## Pull request workflow

1. **Search existing issues and PRs** to avoid duplication. If what you want
   to do is non-trivial, open an issue first to agree on the approach.
2. **Work on a branch** in your fork. Keep the branch focused — one logical
   change per PR.
3. **Run `composer check`** and, if your change touches protocol or transport
   code, `composer conformance` as well.
4. **Update documentation** alongside code changes, not in a follow-up PR.
   That includes `docs/server-dev.md` when public API changes, `CHANGELOG.md`
   under `[Unreleased]`, and any relevant file under `docs/`.
5. **Open the PR** using the pull-request template. Fill in every section
   that applies — skipping sections often means a second round of review.
6. **Respond to review.** Force-pushing to a PR branch is fine and sometimes
   expected. Merge commits from `main` into the branch are also fine — we
   squash on merge.

## Versioning policy

We follow [Semantic Versioning](https://semver.org/), interpreted for this
SDK as follows:

- **Patch (`v1.7.X`)** — non-breaking bug fixes and minor new features or
  improvements. Internal refactors, documentation, and tooling changes also
  land here.
- **Minor (`v1.X`)** — breaking changes to the public API or documented
  flows, major new features, and expanded MCP protocol version support
  (adopting a new spec revision). Breaking changes are called out in
  [CHANGELOG.md](CHANGELOG.md) and in the release notes for the tag.
- **Major (`v2`)** — aligned with the wider MCP ecosystem. When the
  official MCP SDKs cut a `v2`, this SDK will cut its own `v2` to signal
  feature parity. We do not bump the major on our own cadence.

When in doubt about whether a change is breaking or "major," ask on the
PR. The conservative answer is usually correct.

## Releases

Releases are cut by the core maintainer. A release PR:

- moves the relevant entries from `[Unreleased]` into a new version heading in
  [CHANGELOG.md](CHANGELOG.md),
- lands any last documentation adjustments, and
- is tagged (`vX.Y.Z`) on merge.

Contributors do not need to update tags themselves — please just leave your
entries under `[Unreleased]` and the maintainer will handle the cut.

## Issue labelling

We try to follow the
[MCP SDK Working Group label conventions](https://modelcontextprotocol.io/community/sdk-tiers#issue-triage-labels).
The labels we use and what they mean are documented in
[`docs/labels.md`](docs/labels.md). If you're opening an issue, you don't need
to label it — a maintainer will.

## Questions

If you're stuck on a contribution, comment on the relevant issue or PR and a
maintainer or another contributor will try to help. General support questions
belong in [SUPPORT.md](SUPPORT.md) rather than in a draft PR.

## Thank you

This project gets materially better every time someone takes the time to open
a thoughtful issue or a clean pull request. Thank you.
