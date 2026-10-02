# Releases and the changelog

php-qa-ci releases itself. Nobody picks a version number and nobody tags by hand: the
changelog decides the version, and CI cuts the tag once a push to `php8.5` is green. This file is
the single source of truth for how that works and what a change has to carry for it to work.
The rules of the changelog format and the lane that enforces them are in
[docs/tools/changelog.md](../docs/tools/changelog.md); the version scheme consumers see is under
[Branches and versions](../README.md#branches-and-versions).

## Every change to a shipped path carries a changelog entry

`qaConfig/qa.php` switches the `changelog` lane on (`phpqaci.changelog`) and lists the watched
paths: everything a consumer receives or has deployed into their project (`src/`, `bin/`,
`configDefaults/`, `templates/`, `scripts/`, `git-hooks/`, the PHARs and binaries, `build/`,
`phive.xml`, `composer.json`, `rules-*.neon`, and the deployed `.claude/` agents, hooks and
skills). `tests/`, `docs/`, `CLAUDE/`, `qaConfig/`, `.github/` and `CHANGELOG.md` itself are not
watched.

When a branch changes a watched path, the same branch adds an entry under `## Unreleased` in
`CHANGELOG.md`:

```bash
bin/changelog-release add-entry fixed '**The lock is released on SIGTERM.** <what a consumer sees>'
```

Pick the heading by what a consuming project experiences, because the heading decides the
release:

- `Changed — breaking`: they must act (a new requirement, a lane that now fails what it passed,
  a changed exit code or config contract). Minor release, flagged BREAKING in the tag.
- `Removed`: something they could rely on is gone. Minor, flagged BREAKING.
- `Added`, `Changed`, `Deprecated`: new or different behaviour they need not act on. Minor.
- `Fixed`, `Security`: a defect corrected. Patch, when nothing else is in the section.

Write entries the way the existing ones are written: a bold one-sentence statement of the
change, then what a consumer sees and does, never how the code was restructured. A
`composer.json` requirement that is added or tightened must be under `Changed — breaking`; the
lane checks that one mechanically.

### When there is nothing to record

A change no consuming project could notice (an internal refactor, a comment, a rename inside a
`@internal` class) gives one commit in the branch the trailer instead of an entry:

```bash
git commit --trailer 'Changelog: none — internal refactor, no behaviour change'
```

The reason is mandatory and must say why in at least two words: a bare `Changelog: none`, or
`none — wip`, is refused. Do not use the trailer to skip an entry that is merely tedious: if a
consumer's build, output or install can differ, it needs an entry.

## How a release happens

1. A branch lands on `php8.5` (in this estate: merged locally by the lead, pushed by the owner,
   per the consuming project's `lts/*` workflow).
2. CI's `qa` job runs the full pipeline. On `php8.5` the changelog lane judges everything since
   the latest `85.N.N` tag, so a merge that slipped through without an entry fails here.
3. When `qa` is green, the `release` job in `.github/workflows/ci.yml` runs
   `bin/changelog-release next-version`. Empty output means `## Unreleased` has no entries and
   nothing is released.
4. Otherwise it runs `apply <version> <today>` and `notes <version>`, commits `CHANGELOG.md`
   alone as `github-actions[bot]` with the message `Release <version> [skip ci]`, tags that commit
   with an annotated tag whose message is the notes, and pushes branch and tag with
   `git push --atomic`, over the `RELEASE_DEPLOY_KEY` deploy key.

The job releases only the commit its own run verified. When `php8.5` has moved on in the
meantime, it stops with a notice and the run for the newer push releases everything, including
this push's entries. Runs are serialised by the `release-php8.5` concurrency group and never
cancelled. Because the push is atomic, a rejected push leaves neither a stray tag nor a stray
commit on the remote; re-running the job is safe.

The version is the PHP line from `composer.json` (`^8.5` is `85`), then a minor or patch bump
over the newest `85.N.N` tag. Bumping the PHP requirement on this branch would therefore move to a
new line; that is a new branch (`php8.6`), not a release of this one.

## One-time owner setup

The release job cannot push until the owner has done this once. Nothing here is done by an
agent: each step grants write access to the repository.

1. Generate a key pair, with no passphrase (CI cannot type one):

   ```bash
   ssh-keygen -t ed25519 -N '' -C 'php-qa-ci release job' -f php-qa-ci-release
   ```

2. Add the public key as a deploy key **with write access**. In the UI: repository
   **Settings → Deploy keys → Add deploy key**, title `release job`, paste
   `php-qa-ci-release.pub`, tick **Allow write access**. Or:

   ```bash
   gh repo deploy-key add php-qa-ci-release.pub --repo LongTermSupport/php-qa-ci \
     --title 'release job' --allow-write
   ```

3. Store the private key as the Actions secret `RELEASE_DEPLOY_KEY`. In the UI: **Settings →
   Secrets and variables → Actions → New repository secret**. Or:

   ```bash
   gh secret set RELEASE_DEPLOY_KEY --repo LongTermSupport/php-qa-ci < php-qa-ci-release
   ```

4. Let deploy keys bypass the `protect` ruleset (id `18472656`: deletion, non-fast-forward and
   the pull-request rule on the default branch), which would otherwise reject the bot's direct
   push. In the UI: **Settings → Rules → Rulesets → protect → Bypass list → Add bypass → Deploy
   keys**, bypass mode **Always allow**, then **Save changes**. Or, keeping every other field of
   the ruleset as it is:

   ```bash
   gh api repos/LongTermSupport/php-qa-ci/rulesets/18472656 \
     | jq '{name, target, enforcement, conditions, rules,
            bypass_actors: ((.bypass_actors // []) + [{actor_id: null, actor_type: "DeployKey", bypass_mode: "always"}])}' \
     | gh api repos/LongTermSupport/php-qa-ci/rulesets/18472656 --method PUT --input -
   gh api repos/LongTermSupport/php-qa-ci/rulesets/18472656 --jq '.bypass_actors'
   ```

   The other ruleset, `protect-default-branch` (id `23328934`), has no pull-request rule, and
   the bot's push is a fast-forward, so it needs no change. If it ever gains a pull-request or
   required-status-check rule, give it the same bypass.

5. Delete both key files from the machine they were generated on. The deploy key and the secret
   are the only copies needed; a lost key is replaced by repeating steps 1 to 3.

Until this is done, the `release` job fails on its first step with a message naming this file,
and `qa` is unaffected.
