# Dependency Update Policy

This document describes how dependencies are chosen, how they are updated,
and when version-floor bumps are acceptable. It exists in part to satisfy the
[SEP-1730](https://modelcontextprotocol.io/community/seps/1730-sdks-tiering-system)
"published dependency update policy" requirement, but it's also useful
context for anyone integrating the SDK.

The guiding principle is: the fewer moving parts, the better. This SDK keeps
its runtime dependencies deliberately small so it runs on vanilla shared
hosting without fuss.

## Runtime dependencies

Declared in [`composer.json`](../composer.json).

| Dependency       | Constraint              | Why                                                |
| ---------------- | ----------------------- | -------------------------------------------------- |
| `php`            | `>=8.1`                 | MCP types rely on readonly properties, enums, intersection types, and other 8.1+ features. |
| `ext-json`       | `*`                     | MCP is JSON-RPC. Non-negotiable.                   |
| `ext-curl`       | `*`                     | Used by the HTTP client transport.                 |
| `psr/log`        | `^2.0 || ^3.0`          | PSR-3 `LoggerInterface`. Always wrapped in `NullLogger` by default. |

No other runtime dependencies. Specifically: no HTTP client framework, no
event loop, no web framework, no JSON schema validator, no JWT library. Each
of those would make installation harder on shared hosting and is avoided on
purpose.

## Optional runtime dependencies

Declared under `suggest` in `composer.json`.

| Dependency         | Purpose                                                           |
| ------------------ | ----------------------------------------------------------------- |
| `ext-pcntl`        | Improves stdio server lifecycle (signal handling). Degrades cleanly. |
| `monolog/monolog`  | Convenient PSR-3 logger for examples and the web client. The SDK itself does not depend on it. |

Optional dependencies must always have a working fallback when absent.

## Development dependencies

| Dependency           | Constraint  | Why                         |
| -------------------- | ----------- | --------------------------- |
| `phpunit/phpunit`    | `^10.0`     | Unit test framework.        |
| `phpstan/phpstan`    | `^1.10`     | Static analysis.            |

Bumping these within their current major is fine. Changing the major (e.g.,
PHPUnit 11, PHPStan 2) is treated as a meaningful change — it would go in
a dedicated PR with a note in [CHANGELOG.md](../CHANGELOG.md) under
`[Unreleased]`.

## Pinned external tools

Tooling that is not a Composer dependency but still affects test outcomes:

| Tool                             | Pin location                                     | Why pinned                                                                 |
| -------------------------------- | ------------------------------------------------ | -------------------------------------------------------------------------- |
| `@modelcontextprotocol/conformance` | [`package.json`](../package.json) + [`.github/workflows/conformance.yml`](../.github/workflows/conformance.yml) | The conformance baseline is bound to a specific tool version. Bumping the tool may surface new tests that require a baseline refresh. |

Bumping the conformance tool version is its own PR with:

1. A refreshed [`conformance/conformance-baseline.yml`](../conformance/conformance-baseline.yml),
   with any new entries explained.
2. A note in [CHANGELOG.md](../CHANGELOG.md) mentioning the new tool version.
3. An updated tool version string in
   [`.github/workflows/conformance.yml`](../.github/workflows/conformance.yml).

## When dependencies are updated

- **Patch updates** (e.g., `^1.10` → `1.10.67` resolving via Composer): done
  transparently. No PR; Composer picks them up.
- **Minor updates within the declared range**: the same. The Composer range
  covers them.
- **Widening a declared range** (e.g., adding `^3.0` to a `^2.0`-only
  dependency): a normal PR, with CI proving both ranges still build. Not a
  breaking change in itself unless it forces consumers to upgrade.
- **Narrowing or replacing a dependency**: treated as potentially breaking.
  Covered by the semver policy in [CONTRIBUTING.md](../CONTRIBUTING.md) —
  at minimum a minor version bump and a note in [CHANGELOG.md](../CHANGELOG.md).

## When the PHP version floor rises

The SDK currently targets PHP 8.1, which is the minimum feature set needed
for the types and patterns used in `src/Types/`.

The PHP version floor is raised only when:

1. A lower version has reached the end of security support per the
   [PHP project's supported-versions policy](https://www.php.net/supported-versions.php), **and**
2. A meaningful feature or ergonomic improvement becomes available in the
   newer version that the maintainers actively want to use, **or**
3. A dependency we rely on drops support for the current floor.

Floor bumps are a minor-version bump (`v1.X`) at minimum, are announced in
`[Unreleased]` in [CHANGELOG.md](../CHANGELOG.md) well before the release
goes out, and are reflected in
[`composer.json`](../composer.json) and
[`.github/workflows/conformance.yml`](../.github/workflows/conformance.yml).
We would not raise the floor inside a patch release.

## When a major (v2) bump becomes necessary

Per [CONTRIBUTING.md](../CONTRIBUTING.md), `v2` is aligned with the wider
MCP ecosystem — we cut a `v2` when the official MCP SDKs cut their own
`v2`, to signal feature parity. Dependency-only changes (floor bumps,
breaking dependency changes) are not sufficient on their own to justify
`v2`; those land in a minor release (`v1.X`).

## Security updates to dependencies

If a declared dependency discloses a vulnerability, we:

1. Assess whether the SDK actually exercises the vulnerable code path.
2. If yes, widen or tighten the version constraint to force users onto a
   fixed release, and call it out in [CHANGELOG.md](../CHANGELOG.md) and
   (where appropriate) in a GitHub Security Advisory on this repository.
3. If no, note the assessment in `[Unreleased]` for transparency but do not
   force a bump that would break downstream users unnecessarily.

See [SECURITY.md](../SECURITY.md) for reporting inbound vulnerabilities.

## What downstream users should do

- Pin to a minor version (`^1.7`) and read the
  [CHANGELOG](../CHANGELOG.md) before bumping.
- Don't pin to an exact patch (`=1.7.0`) without a specific reason — you
  would miss security fixes.
- Run the SDK's tests and your own integration tests after a bump. If
  something breaks that you believe shouldn't have, open a bug report — we
  take breakage of advertised behaviour seriously.
