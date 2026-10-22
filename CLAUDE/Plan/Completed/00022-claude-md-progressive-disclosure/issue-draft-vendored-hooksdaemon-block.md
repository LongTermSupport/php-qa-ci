# Issue draft: the vendored `CLAUDE.md` carries php-qa-ci's `<hooksdaemon>` block into consumer sessions

Draft for the php-qa-ci tracker, not filed. Found on `php8.5`.

## Problem

php-qa-ci's root `CLAUDE.md` ships in the package. A consumer agent that reads any file under
`vendor/lts/php-qa-ci/` loads it lazily, as the `CLAUDE.md` of an ancestor directory of that file.
Since Plan 00022 the hand-written part is about 1.5k tokens, but the `<hooksdaemon>` block that the
hooks daemon injects in this repository is about 22.6k tokens (85% of the file). That block
describes this repository's handler configuration, not the consumer's, so in a consumer session
it is both a cost and a source of wrong guidance (handlers the consumer may not run, rules the
consumer may have configured differently).

## Options to weigh

- Exclude `CLAUDE.md` from the dist archive and the source install, and ship a consumer-facing
  file without the block. Weigh this against dogfooding, where the vendored checkout is a real git
  checkout whose `CLAUDE.md` is the contributor's.
- Ask upstream for a way to keep the injected block out of a file that ships (for example, an
  injection target other than root `CLAUDE.md` for library repositories).
- Accept the cost until the upstream compact-rendering request (the plan's upstream report draft)
  lands, which would cut the block to under half its size.

## Owner decision needed

Which option, given that the dogfooding workflow depends on the vendored checkout being complete.
