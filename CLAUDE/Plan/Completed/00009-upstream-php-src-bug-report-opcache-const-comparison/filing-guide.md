# How php-src wants this filed

Research output for Plan 00009. Everything below was read from php-src itself
(`CONTRIBUTING.md`, `SECURITY.md`, `AGENTS.md`, the issue templates) or from the
tracker, at the time of writing. Where a claim came from a third party it says so.

## 1. Public issue, not a security advisory

`SECURITY.md` lists what is **not** a security issue, and the relevant entry is:

> Invocation of specially crafted, malicious code intended to cause memory
> violations. […] PHP does not offer sandboxing, and the execution of untrusted
> code is always considered unsafe. Such issues are bugs, but not security issues.

Our crash is reached from ordinary, trusted source that the *compiler* miscompiles.
It crosses no security boundary — an attacker who can supply the PHP source already
has code execution. So it is a normal bug: file it publicly at
<https://github.com/php/php-src/issues/new/choose>, choosing **Bug report**.

The private route (<https://github.com/php/php-src/security/advisories/new>,
or `security@php.net`) is the fallback if a triager reclassifies it, not the
starting point. The full policy is
[security-classification.rst](https://github.com/php/policies/blob/main/security-classification.rst).

Blank issues are disabled (`.github/ISSUE_TEMPLATE/config.yml`); the template must
be used. Documentation issues go to `php/doc-en` instead — not applicable here.

## 2. The template's required fields

`.github/ISSUE_TEMPLATE/bug_report.yml` has three fields. It auto-applies the
labels `Bug` and `Status: Needs Triage`.

| Field                | Required | What it asks for                                                                                     |
| -------------------- | -------- | ---------------------------------------------------------------------------------------------------- |
| **Description**      | yes      | A minimal reproduction, the output it produced, and the output expected. A 3v4l.org link if possible |
| **PHP Version**      | yes      | The *full* output of `php -v`, rendered plain — not a version string                                 |
| **Operating System** | no       | Free text, "if relevant"                                                                             |

The Description field is pre-filled with a three-block skeleton
(`The following code:` / `Resulted in this output:` / `But I expected this output instead:`). Filling that skeleton is the house style; the extra detail (dump,
backtrace, optimizer bisect) goes after it.

**On the 3v4l.org link.** The template asks for one "if possible", and for an OPcache
optimizer bug it is not possible: `opcache.enable_cli` is `INI_SYSTEM`
([runtime configuration](https://www.php.net/manual/en/opcache.configuration.php)), so a
snippet cannot enable the optimizer from inside itself and 3v4l offers no per-run ini.

A `php -n -d …` command line is the equivalent proof and a stronger one: `-d` sets an
`INI_SYSTEM` value at startup, which is exactly the mechanism a snippet lacks, and `-n`
demonstrates a stock build with no php.ini and no other extension loaded. Give the
command line, and say in one clause why there is no link — do not leave the field
silently empty, which reads as an omission rather than a finding.

## 3. House style: short, and no impact essay

`SECURITY.md` is blunt about it, and the same taste governs the public tracker:

> When creating reports, please **skip** the theatrics. Drop the impact essay,
> send a short reproducer with the few lines that matter, and make each point once.

`CONTRIBUTING.md` adds, under *Filing bugs*:

> Where possible, please include a self-contained reproduction case!

Corroborated on the tracker: a report of an OPcache segfault with no isolated case and
no backtrace ([GH-22190](https://github.com/php/php-src/issues/22190)) drew exactly one
reply from a maintainer, asking for both. A report with a self-contained reproducer, a
version/ini matrix and a named root cause
([GH-21691](https://github.com/php/php-src/issues/21691)) was triaged into
`Category: Optimizer` and fixed.

## 4. The AI question

There is no anti-AI policy in php-src, and the one written rule is mild.
`CONTRIBUTING.md`, final section:

> ## LLM usage in GitHub comments
>
> When using LLMs to generate comments to maintainers for any purpose other than
> direct translation, we would highly appreciate it if you disclosed the relevant
> paragraphs as such via markdown quote.

So: **disclose LLM-written prose by quoting it**, and prefer to write the report so
that little of it needs quoting. Note the scope — "comments to maintainers". A
reproducer, a dump, a backtrace and a bisect table are measurements, not generated
prose, and are not what the rule is aimed at.

`AGENTS.md` exists at the repo root and says one thing only: when scanning php-src
for vulnerabilities, respect `SECURITY.md`. `.claude/CLAUDE.md` just redirects to it.
There is no disclosure requirement for issues, no ban, and no "AI-assisted" checkbox.

Wider context, and the reason to still be careful: an
[internals thread](http://www.mail-archive.com/internals@lists.php.net/msg124390.html)
has discussed adopting a formal policy, with maintainers weighing the review burden;
one contributor argued explicitly against "an anti AI policy or similar bad ideas".
Nothing was concluded. Meanwhile projects around PHP have hardened against
low-quality generated reports — [curl ended its bug bounty over the
triage cost](https://www.theregister.com/2026/04/06/ai_coding_tools_more_work/) —
so the practical risk is not that a report is rejected for being AI-assisted, it is
that a report *shaped* like generated slop (long summary, impact essay, confident
root-cause claim, no reproducer) gets ignored. The defence is the reproducer and the
brevity, both of which we have.

## 5. If a fix PR follows the issue

Not required — filing the issue is a complete contribution, and engine/optimizer
bugs are normally fixed by the maintainers who own that code. But if one is
attempted, `CONTRIBUTING.md` and the merged PRs agree on the shape:

- **Base branch**: the lowest *actively* supported branch the bug affects. As of
  filing that is `PHP-8.4` (8.2/8.3 are security-fix-only, 8.4 and 8.5 are active).
  Never a `PHP-x.y.z` branch. This is why "is 8.4 affected?" stops being academic
  the moment a PR is on the table — see Task 1.4.
- **Title**: `Fix GH-<issue>: <what the fix does>`; body references `Fixes GH-<issue>`.
- **Test**: a `.phpt`. Optimizer tests live in `ext/opcache/tests/opt/` and assert
  the *dump*, using an `--INI--` block that pins `opcache.optimization_level` and
  `opcache.opt_debug_level=0x20000` plus `--EXTENSIONS-- opcache` (see
  `ext/opcache/tests/opt/sccp_001.phpt`). A crash of this kind can equally be tested
  by asserting the runtime output. Do not add a `--CREDITS--` section — the docs say
  authorship is tracked by git.
- **NEWS**: an entry under the target branch's section,
  `. Fixed bug GH-{number} ({description}). ({contributor})`, wrapped at 80 columns.
- **Expect the test to be argued down.** In
  [GH-21412](https://github.com/php/php-src/pull/21412) a maintainer's review said
  "We probably don't need this complex PHPT test"; in
  [GH-22897](https://github.com/php/php-src/pull/22897) a reviewer rejected a
  200-iteration regression test in favour of a deterministic assertion. Send the
  smallest test that fails without the fix.
