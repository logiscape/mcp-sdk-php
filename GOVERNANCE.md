# Project Governance

This document describes how decisions are made on `logiscape/mcp-sdk-php`, who
makes them today, and how that is expected to evolve.

## Current state

The project is maintained by:

- **One core maintainer**: [Josh Abbott](https://joshabbott.com) (GitHub:
  [@logiscapedev](https://github.com/logiscapedev)), who makes releases, merges
  pull requests, triages issues, and is ultimately accountable for the SDK's
  direction.
- **A small group of volunteer contributors** who have opened issues and pull
  requests on GitHub. Several have landed substantial changes; none currently
  hold commit rights beyond a single merged PR's lifetime.

This is a small open-source project, and the governance model reflects that:
light-weight, informal, and biased toward shipping.

## How decisions are made

- **Day-to-day code changes** follow a lazy-consensus model. If a pull request
  solves a real problem, has tests (or a test plan) that pass, and does not
  clash with the principles in [CONTRIBUTING.md](CONTRIBUTING.md), the core
  maintainer merges it.
- **Disagreements** are resolved by discussion on the relevant issue or PR.
  When consensus cannot be reached, the core maintainer makes the final call
  and explains the reasoning on the thread.
- **Breaking changes** to the public API or documented flows require an explicit
  maintainer sign-off and an explanation in the release notes. See the
  versioning policy in [CONTRIBUTING.md](CONTRIBUTING.md).
- **Roadmap direction** is published in [ROADMAP.md](ROADMAP.md). Larger
  direction shifts will be discussed in an issue before being committed to the
  roadmap, so the community has a place to weigh in.

## Becoming a trusted contributor

We want this project to grow a real contributor community. A realistic path is:

1. **Report a clear issue** or contribute a small, focused pull request.
2. **Land a few PRs over time** that fit the project's style and principles,
   and respond well to review.
3. **Review other people's PRs** where you have context. This is often the
   single most useful thing a prospective co-maintainer can do.
4. **Be invited** to become a trusted contributor. This is not a formal tier
   with a single mechanical threshold — the maintainer makes the judgement
   call based on the quality and consistency of the contributions and the
   person's interest in helping review others' work.

Trusted contributors are expected to apply the same principles the core
maintainer does: spec-faithful implementation, no conformance shortcuts,
cPanel/Apache compatibility for core features, and honest communication about
what is and isn't done.

## SDK Tiering System

- **SEP-1730 tiering**: The official tier assignments
  ([SDK tiers](https://modelcontextprotocol.io/community/sdk-tiers)) apply to
  MCP SDKs. We are not currently an official SDK, but we use the SEP-1730
  criteria as our own quality rubric — see [ROADMAP.md](ROADMAP.md) for our
  self-assessment.
- **SDK Working Group conventions**: We aim to follow the SDK Working Group's
  issue-labelling conventions for transparency. The labels are documented in
  [`docs/labels.md`](docs/labels.md).

## Conduct and conflicts

Contributors and maintainers are expected to follow the
[Code of Conduct](CODE_OF_CONDUCT.md). Reports of conduct issues go to the core
maintainer by the channels listed there, not through the public issue tracker.

## Changes to this document

Meaningful changes to governance — who makes decisions, how tiers are applied,
how trusted contributors are recognised — will be announced in
[CHANGELOG.md](CHANGELOG.md) and discussed in an issue before merging. Small
wording or link fixes can go in without fanfare.
