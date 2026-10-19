---
paths:
  - "CHANGELOG.md"
  - ".github/workflows/ci.yml"
  - ".github/workflows/qa.yml"
  - ".github/workflows/release.yml"
description: Releases come from the changelog; versions and tags are never chosen by hand
---

# Changelog and release workflows

You are touching the changelog or a workflow the release depends on: never choose a version or cut a tag by hand.

Record a consumer-visible change under `## Unreleased`, or give the commit a `Changelog: none — <reason>` trailer.

See [releases.md](../../CLAUDE/releases.md) for the headings, the trailer and how the release pull request works.
