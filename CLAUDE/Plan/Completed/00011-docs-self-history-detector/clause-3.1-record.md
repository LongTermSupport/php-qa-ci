# Clause 3.1 record: the class, before any rule exists

Method specification 1.0.1, clause 3.1 requires this written down **before** 3.2 starts:
the class and its hazard, two independent searches, the bounds both ways, and whether a
runner check pins the behaviour. Written before the detector was designed, so the rule was
drawn to the class rather than the class drawn to the rule.

## Class

**A documentation file describing itself.** Prose in a `.md` file whose subject is *the
document or its revision history* rather than the thing the document exists to explain.

The originating instances were both written in the same sitting, in `README.md`, while
replacing hand-maintained lists with pointers:

> …the previous version of this section listed seventeen always-on rules while
> `rules-default.neon` wired sixteen.

> A numbered copy here was maintained by hand and had already lost four lanes that run on
> every project.

Both are true, and both are about the paperwork.

## Hazard

A reader opens the README to find out what the tool does now. A sentence about what the
document used to say costs them attention and returns nothing: they cannot see the old
version, cannot act on it, and have no reason to care. It also rots in a way ordinary prose
does not — it is a claim about a state that recedes further from reality with every commit,
and nothing will ever flag it as stale, because it was never true of the present.

Git already records the change losslessly, and a commit message is where a reader who
actually wants that goes. So the content is not lost by removing it; it is moved to the one
place that is designed for it and kept accurate for free.

There is a second, sharper edge for this repository specifically. The instances above were
*defensive* — they justified the edit by describing what it replaced. In a quality tool's
README that reads as the author arguing with a predecessor, which is neither documentation
nor a good look.

This is a hazard in the method's sense without being a failure: clause 3.1 admits "merely
sloppy" and error hiding alike.

## The boundary that makes this rule possible

The class is **not** "documentation mentions the past". That would fire on
`docs/upgrading-to-8.5.md`, whose entire job is "previously X, now Y", and on every
deprecation notice — a rule that matches code without the hazard, which clause 3.1 says
MUST be narrowed.

The distinction is **self-reference**:

| Prose                                                       | About    | Verdict |
| ----------------------------------------------------------- | -------- | ------- |
| "Previously the pipeline was configured in Bash, now PHP."  | the tool | fine    |
| "`useArkitect=0` was the old spelling of `withArkitect()`." | the tool | fine    |
| "The previous version of this section listed seventeen…"    | the doc  | defect  |
| "A numbered copy here was maintained by hand."              | the doc  | defect  |

A reader needs the first two to use the software. The last two describe an edit.

## Bounding it the other way

A rule matching only "the previous version of this section" would catch the originating
instance and nothing else, which clause 3.1 forbids unless the search found nothing wider.
The class is therefore drawn at the *family* of self-referential constructions — a document
referring to itself (`this section`, `this page`, `this README`, `here`) in the past tense
or with a change verb (`used to`, `was maintained`, `has been moved`, `no longer lists`,
`had already`).

**Next wider rule, deliberately not built**: point-in-time artefacts generally — `✅ Complete`,
`as of this commit`, test-run counts, time estimates, LLM-style `## Summary` headings. These
are a real and adjacent class, the hooks daemon already blocks several of them on
`Write`/`Edit` of instruction files, and folding them in now would widen the rule past what
the search evidenced. Recorded here so the next practitioner does not have to rediscover it;
the readers in technique 2 were asked to report it as a separate category so the count is
known before anyone decides.

## Search technique 1 — text, over normalised paragraphs

A marker-family regex for self-referential constructions, run over every tracked `.md`
outside the plan tree, `remote-docs/` and the daemon's own files.

**The first attempt was wrong in a way worth recording.** Run line-by-line it found *one* of
the two known instances. These documents are hard-wrapped at about 95 characters, so
`the previous version of` and `this section listed…` sit on different lines and no line-based
search can see the phrase. Re-run after flattening each paragraph to a single line, it found
both.

Consequence for the detector, not just the search: **it must normalise whitespace across
line breaks before matching**, or it will be structurally blind to most instances in this
corpus. A line-oriented implementation would have passed its own fixture and missed the real
thing.

Script: `untracked/scratch/dbf-search1.php`. Result: 2 instances, both `README.md`.

## Search technique 2 — reading, independent of technique 1

Four sub-agents read all 116 first-party documentation files in full and judged each
sentence against "is this about the tool, or about the paperwork?", with the marker list
deliberately withheld so the reading could catch constructions the regex has no token for.

These two techniques miss different things, which is what clause 3.1 requires of them: the
regex cannot see a paraphrase it has no marker for; the reading cannot see a file nobody
opens, which is why the corpus was enumerated from `git ls-files` rather than sampled.

Reports: `untracked/agent-reports/260911-dbf-reader-*.md`.

## Scope of the sweep, and what is excluded

Swept: all 129 tracked `.md` files, less the exclusions below — 116 files.

| Excluded                                              | Why the hazard cannot arise there                                                                                                  |
| ----------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `CLAUDE/Plan/**`                                      | Plans and journals are *records of a moment* by design; the plan workflow states archived plans must not be updated to match today |
| `remote-docs/**`                                      | Vendored third-party text, stored verbatim with a provenance hash; editing it would break the capture                              |
| Daemon-owned files (`CLAUDE/core/**`, `*.core.md`, …) | Overwritten wholesale on every daemon upgrade, so a fix there is discarded and is not ours to make                                 |
| `tests/assets/**`                                     | Fixtures; their content is the input to a test, not documentation                                                                  |

Each of these is a **narrowing** in the method's sense — a written sentence saying why the
excluded documents cannot carry the hazard — and not a suppression. None of them is excluded
because the count was inconvenient.

## Runner check (behaviour pinning)

**None, and deliberately.** `CLAUDE/DefenceBeforeFix.md`'s "Does TDD apply?" table puts pure
documentation-content defects in the "static rule IS the fix, nothing to assert" row: there
is no runtime behaviour that differs before and after removing a sentence about the
document's own past. The rule carries fixtures as its own tests; no reproduction test is
owed, and that is recorded here rather than left as a silent omission.
