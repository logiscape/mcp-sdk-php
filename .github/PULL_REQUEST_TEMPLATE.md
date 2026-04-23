<!--
Thanks for contributing. Please fill in the sections below — they help
reviewers land your PR faster. See CONTRIBUTING.md for the guiding principles
and testing expectations.
-->

## Summary

<!-- What does this PR do, in one or two sentences? -->

## Motivation

<!-- Why is this change needed? Link any relevant issue(s) with "Fixes #123" or "Refs #456". -->

## Type of change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] Minor feature or improvement (non-breaking, additive)
- [ ] Major feature (non-breaking but substantial — requires a minor version bump)
- [ ] Expanded MCP protocol version support (adopting a new spec revision — requires a minor version bump)
- [ ] Breaking change (public API or documented flow — requires a minor version bump)
- [ ] Documentation only
- [ ] Tooling / CI only

## Versioning impact

<!-- See the semver policy in CONTRIBUTING.md. -->

- [ ] Patch (`v1.7.X`) — non-breaking bug fix or minor feature/improvement
- [ ] Minor (`v1.X`) — breaking change, major new feature, or expanded MCP protocol version support
- [ ] Major (`v2`) — aligned with a wider MCP ecosystem `v2` (not chosen unilaterally)

If this is a breaking change, describe the break and the migration path here:

<!-- What used to work? What works now? How do users update their code? -->

## Test plan

Which layers did you run? (See [docs/testing.md](https://github.com/logiscape/mcp-sdk-php/blob/main/docs/testing.md).)

- [ ] `composer check` passes locally
- [ ] `composer conformance` passes locally (or documented baseline change included)
- [ ] Relevant unit tests added or updated
- [ ] Spot-checked with the [MCP Inspector](https://github.com/modelcontextprotocol/inspector) (for protocol-touching changes)
- [ ] Smoke-tested against a real MCP client (Claude Code, OpenAI API, or similar) — encouraged for user-visible changes

If you updated `conformance/conformance-baseline.yml`, explain why each entry
added or changed:

<!-- One or two sentences per entry. Remember the no-shortcut rule in conformance/README.md. -->

## Compatibility

- [ ] Change preserves core-feature compatibility under cPanel / Apache / PHP-FPM (see [docs/compatibility.md](https://github.com/logiscape/mcp-sdk-php/blob/main/docs/compatibility.md))
- [ ] If this introduces a feature that cannot work under shared hosting, it fails gracefully (typed exception, not a fatal error)
- [ ] No new required runtime dependencies (or new dependency justified in the PR)

## Documentation

- [ ] Updated `README.md` if the public API or usage changed
- [ ] Added an entry to `CHANGELOG.md` under `[Unreleased]`
- [ ] Updated anything under `docs/` that this PR contradicts
- [ ] Updated `AGENTS.md` / `CLAUDE.md` if the contributor-facing guidance changed

## Additional notes

<!-- Anything reviewers should pay special attention to, or decisions you'd like confirmed. -->
