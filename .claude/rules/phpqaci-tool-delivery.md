---
paths:
  - "phive.xml"
  - "composer.json"
  - ".github/workflows/update-deps.yml"
description: Bundled tools stay on their latest release that passes QA
---

# Tool delivery and dependencies

You are touching how php-qa-ci's tools are pinned or updated: the daily update keeps every bundled tool on its latest release that passes QA here.

A new or tightened `composer.json` requirement is recorded under `Changed — breaking`.

See [tool-currency.md](../../CLAUDE/tool-currency.md) for the update process and doing it by hand.
