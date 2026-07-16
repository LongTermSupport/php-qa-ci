# Research — Distributing a self-built Rector PHAR (vendor-phar vs PHIVE/GitHub, this-repo vs sister-repo)

**Date**: 2026-07-16
**Author**: research (web + local verification)
**Status**: Findings + option matrix + recommendation

## The decision

Once we can *build* a `rector.phar` (see research-box-phar-build.md), how do we
*store and ship* it so php-qa-ci consumers get it the same way they get
phpstan/php-cs-fixer/etc.? Two axes:

- **Axis A — where the phar lives at rest**: committed to `vendor-phar/` in THIS
  repo, vs published as a GitHub *release asset* (this repo or a sister repo)
  and pulled by PHIVE.
- **Axis B — where it is BUILT**: in this repo's CI, vs a dedicated sister repo
  (e.g. `lts/rector-phar`) that only builds/signs/releases the phar.

## How php-qa-ci ships PHARs today (baseline)

`phive.xml` lists 5 phars, each with `location="./vendor-phar/<tool>.phar"` and
`copy="true"`. The phars are **committed to git** under `vendor-phar/`.
`scripts/tool-install.bash` in default `install` mode only *verifies* the
committed phars exist; PHIVE is invoked **only** in maintainer `update`/`--force`
mode to re-fetch. So today PHIVE is a *maintainer-side* fetch tool and the
committed phar is the source of truth consumers actually run.

Two of the current phars use phar.io **aliases** (`infection`, `phpstan`,
`php-cs-fixer`, `composer-require-checker`) and one uses **org/repo path**
(`phparkitect/arkitect` with an explicit GPG key). Both PHIVE resolution styles
are already in use here.

## PHIVE-from-GitHub requirements (if we go the release-asset route)

To have PHIVE install a phar from GitHub releases (`phive install org/repo` or a
registered alias), each release must attach:

- `rector.phar` (the archive), and
- `rector.phar.asc` (a **detached GPG signature**):
  `gpg -u <key> --detach-sign --output rector.phar.asc rector.phar`

PHIVE downloads the phar, verifies its SHA hash, and verifies the OpenPGP
signature against a trusted key (first use prompts to trust the fingerprint;
CI pins it via `--trust-gpg-keys`, exactly as `tool-install.bash` already does
for arkitect's key `47CD54B6398FE21B3709D0A4D9C905CED1932CA2`). A phar.io
**alias** additionally requires a PR to `phar-io/phar.io`; the **org/repo** form
needs no registration. Since we control the key and repo, **org/repo + pinned
key** is the low-friction choice — no third-party registration.

## Option matrix

| Option | Build where | Store at rest | Consumer fetch | Pros | Cons |
|---|---|---|---|---|---|
| **1. Commit to `vendor-phar/` (this repo)** | this repo CI or maintainer `update` | git blob in `vendor-phar/rector.phar` | already present in vendored copy (no fetch) | **Zero new consumer mechanics** — identical to the other 5 phars; offline installs keep working; no GPG infra needed | grows this repo ~20–30 MB; phar rebuilt/committed by a maintainer step; binary churn in history |
| **2. PHIVE from THIS repo's GitHub releases** | this repo CI | release asset + `.asc` | PHIVE `lts/php-qa-ci:rector` on update; still `copy` into `vendor-phar/` | keeps big binary out of source tree (release assets, not git blobs) | needs GPG signing infra; release-tag coupling; consumers offline need the committed copy anyway |
| **3. Sister repo `lts/rector-phar` + PHIVE** | dedicated sister repo CI (scheduled) | sister-repo release asset + `.asc` | `phive.xml` entry `rector/rector`→org/repo path with pinned key | **cleanest separation** — rector build/version churn lives outside php-qa-ci; auto-tracks new Rector releases on a schedule; php-qa-ci just bumps a version | most moving parts (2 repos, CI, signing key custody); another repo to maintain |

## Recommendation (to be pressure-tested by the fable review)

**Phase the rollout — do NOT over-engineer up front:**

> **SUPERSEDED (fable-plan-review-1 §5 / review-2)**: the "add a `rector` entry to
> `phive.xml`" part below is WRONG and is overridden by PLAN.md. A self-built phar
> has no PHIVE-fetchable source, and `tool-install.bash` update mode `rm`s every
> `vendor-phar/*.phar` before `phive install` — an unresolvable phive.xml entry
> would delete `rector.phar` unrecoverably. **Rector stays OUT of `phive.xml`**;
> it is committed to `vendor-phar/` and verified/built separately. The
> commit-to-`vendor-phar/` recommendation stands; ignore the phive.xml wording.

1. **Ship first via Option 1** (commit `rector.phar` to `vendor-phar/` and add a
   `rector` entry to `phive.xml` pointing at `./vendor-phar/rector.phar`). This
   is a *drop-in peer* of the existing 5 phars: consumers get Rector with **zero
   new mechanics**, offline installs keep working, and it lets us delete the
   `tools/rector/` composer project + the `tool-install.bash` Phase-2 block +
   the bespoke logic in `rector.inc.bash` immediately. This alone kills the
   "dirty vendored copy" pain (no more tracked `tools/rector/composer.lock`
   rewritten by `composer update --working-dir`).

2. **Add a maintainer build script** (`scripts/build-rector-phar.bash`) wired
   into the existing `update` mode, so refreshing Rector is `box compile` +
   commit — mirroring how `update` mode already re-fetches the other phars.

3. **Defer Option 3 (sister repo)** until/unless repo-size or release cadence
   justifies it. If we later want the big binary out of the source tree and
   automatic new-Rector tracking, promote the build script into a sister
   `lts/rector-phar` repo and switch the `phive.xml` entry to the org/repo form
   with a pinned GPG key. The Phase-1 design does not block this — it is the
   same `phive.xml`-entry swap the other tools already demonstrate.

**Why phased**: the user's core pain is the embedded composer project dirtying
client checkouts. Option 1 removes that on day one with the least risk and no
new trust/signing infrastructure. The sister repo is a *distribution
optimisation*, not a prerequisite, and can be added later behind the same PHIVE
abstraction consumers already use.

## Open questions for the plan / review

- **Repo size**: is committing a ~20–30 MB `rector.phar` to `vendor-phar/`
  acceptable, given ~34 MB of phars already live there? (Quantify in spike.)
- **Signing**: if we ever do Option 2/3, whose GPG key signs it and where is it
  custodied? (arkitect precedent: a project-owned key pinned in `tool-install.bash`.)
- **Version pinning**: `phive.xml` records `installed="x.y.z"`; the Rector phar
  needs the same explicit version discipline as the others.

## Sources

- [PHAR.IO — Distribute your own](https://phar.io/distribute-your-own.html)
- [PHAR.IO — How to make a PHAR release on GitHub PHIVE-compatible](https://phar.io/howto/sign-and-upload-to-github.html)
- [PHAR.IO — How it works](https://phar.io/how-it-works.html)
- [phar-io/phive (GitHub)](https://github.com/phar-io/phive)
- [Phive: Secure, Easy, and Contained Phar Manager • PHP.Watch](https://php.watch/articles/phive)
- [Managing development tools elegantly — International PHP Conference](https://phpconference.com/blog/managing-development-tools-elegantly/)
- Local: `phive.xml`, `scripts/tool-install.bash`, `vendor-phar/`
