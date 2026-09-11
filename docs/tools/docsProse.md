# Documentation Prose

**Identifier**: `phpqaci.docsProse`

An always-on check that `README.md` and everything under `docs/` describes **its subject**
rather than **itself**.

## What is checked

A document may say anything it likes about the past of the thing it documents. It may not
narrate its own past.

The check reports a sentence that refers to the document — `this section`, `this page`,
`this README`, `the list here` — in the past tense or with a change verb, and the currency
hedge `at time of writing`.

## Why

Someone opens a page to find out what the tool does now. A sentence about what the page used
to say gives them nothing they can act on: they cannot see the old version and have no reason
to care about it. Worse, it decays in a way ordinary prose does not — it is a claim about a
state that recedes further from reality with every commit, and nothing will ever flag it as
stale, because it was never true of the present in the first place.

Nothing is lost by removing it. Git holds the change losslessly, and the commit message is
both where a reader who wants it will look and the one place that stays accurate for free.

The currency hedge is the same defect wearing a politer hat. A page saying `17 rules at time of writing` is telling you it has probably drifted and declining to do anything about
it. Either the number is derivable — in which case name the file it comes from — or it is
not, in which case do not state it.

## How to fix a violation

**Delete the sentence, and put it in the commit message if it is worth keeping.** That is the
correct fix in the large majority of cases.

Where the sentence was carrying real information, it is nearly always answering "why does
this page not contain X?" — and the durable form of that answer is a present-tense statement
of where X *is*:

| Instead of                                                | Write                                                                |
| --------------------------------------------------------- | -------------------------------------------------------------------- |
| `The previous version of this section listed every rule.` | `The rules are listed by bin/rules; this page explains the bundles.` |
| `A copy here was maintained by hand and drifted.`         | `rules-default.neon is the list; this page does not duplicate it.`   |
| `17 rules at time of writing.`                            | `The always-on set is whatever rules-default.neon wires.`            |
| `This section has been moved to the configuration guide.` | (delete, and fix the inbound links instead)                          |

The left column is in backticks for the same reason the examples below are fenced: a page
naming the construction it forbids is quoting it, not committing it, and the check skips both.

For a hand-maintained count or list specifically, the fix that makes the rule *and* the
underlying problem go away is to stop maintaining it by hand: point at the file that already
holds the truth, or at the command that derives it.

## What is deliberately not reported

**Prose about the subject's past**, which is frequently the whole point of a document:

```markdown
Previously the pipeline was configured in Bash; it is now PHP.
`useArkitect=0` was the old spelling; prefer `withArkitect(false)`.
Three lanes had grown this way before the tools moved to PHARs.
```

An upgrade guide, a deprecation notice and a rule's motivation section are all made of
sentences like these, and none of them carries the hazard. The check is narrowed to
*self*-reference for exactly this reason: a rule that fired on
[Upgrading to 8.5](../upgrading-to-8.5.md) would be useless.

**Anything inside a fenced code block** is skipped, so a page can quote the construction it
forbids — as this one does above.

## Scope

`README.md` plus every `.md` under `docs/`: the documents a consumer of the project reads.
Plan folders and journals are outside it by design, being records of a moment rather than
documentation, as are vendored documents under `remote-docs/`.

## Running it

```bash
vendor/bin/qa -t dp          # or -t prose, -t docsProse
vendor/bin/qa                # in the linting phase, straight after markdownLinks
```

## Why this is a separate lane from `markdownLinks`

Both read the same files, but a lane prints one identifier, and an identifier must resolve to
documentation about the rule that fired
([method specification](https://defence-before-fix.github.io/SPEC.html) clause 3.6). A prose
finding reported under `phpqaci.markdownLinks` would resolve to
[a page about links](markdownLinks.md), which teaches the reader nothing about what they hit.

## Provenance

Built under Defence Before Fix. The class, its bounds, the two independent searches and the
sweep are recorded in
[Plan 00011](../../CLAUDE/Plan/00011-docs-self-history-detector/clause-3.1-record.md).
