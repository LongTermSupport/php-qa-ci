# Tool boundaries: when a new tool is justified

A tool (a lane in `src/Pipeline/Lane/`, a row in `ToolRegistry::shipped()`) is not an
implementation detail. Adding one extends the public, human- and agent-facing API of
php-qa-ci, in every one of these places at once:

- **`-t` tokens.** The canonical name and every alias become permanent input to `bin/qa`,
  frozen by `ToolRegistryCharacterisationTest`. Removing or renaming one later is a breaking
  change to every project, script and CI workflow that types it.
- **CLI help.** Every tool adds a line to the usage text that every user of every consuming
  project reads, forever.
- **Pipeline order.** A phase gains a step, so every full run of every consumer gains the
  time it costs and the ways it can fail.
- **A stable identifier.** `phpqaci.<name>`, printed on failure, resolved offline by
  `vendor/bin/rule-doc`, indexed in `docs/phpstan-rules/README.md`, and referenced from
  commit messages and issues from then on.
- **A documentation page** under `docs/tools/`, which the identifier resolves to and which
  must stay true.

That total is the cost of a tool. It is worth paying often — a real check that nothing else
performs belongs in the pipeline, and **we can and should add tools when that is what the job
needs**. What it is never worth paying is one tool per *finding*.

## The test

Add a tool when the answer to all three is yes:

1. **Does it answer a question no existing tool asks?** Not a new instance of a question one
   already asks.
2. **Would a user reasonably type its name?** A tool is a thing someone selects and runs on
   its own (`-t <name>`) while working on that class of problem. If nobody would ever run it
   alone, it is a check inside something else.
3. **Does it stand on its own in the help text?** One line has to describe it without
   referring to a defect, a version or an incident. If the only honest description names a
   specific bug, it is a check, not a tool.

Otherwise **extend an existing tool.** Adding an assertion to a lane costs nothing on any of
the surfaces above, and the lane's existing docs page grows a section.

## Group by the input and the mechanism, not by the finding

A tool owns a *kind of inspection*. Everything that inspection can discover belongs to it.

- `phpstan` owns static analysis, and a new rule is a rule, not a tool.
- `composerChecks` owns "what does Composer itself say", covering diagnose, audit,
  normalisation and autoload dumping in one lane, because they share the input.
- `opcache` owns "what bytecode does this code actually compile to". The first defect it
  covers is a comparison the optimizer left constant-vs-constant; the next OPcache codegen
  defect is another assertion in the same lane, over the same compile, under the same
  identifier. A lane per defect would have meant `-t occ`, `-t osp`, `-t ojit`, each
  compiling the whole codebase again.
- `versionPins` owns "does a pinned version still match the toolchain", across three
  unrelated file formats, because the question is one question.

The counter-example is as important: `phpLint` and `opcache` both compile PHP, and they are
still two tools. Linting answers "does this parse", is fast, runs on broken code, and is the
first thing to run when a file will not load. The OPcache lane answers "what does the
compiler emit", needs a working parse, and is meaningless on a file that does not compile.
Different questions, different failure guidance, different times you would run one alone.

## Before adding one

- Read this file, then the existing lanes in `ShippedTools::all()`, and name which existing
  tool you rejected and why.
- Record the decision in the plan under Technical Decisions, with the three answers above.
- Pick the canonical name from the *inspection*, not the defect: `opcache`, not
  `optimizerConstComparison`.
- Keep aliases short and few. Two is usually right: a short form and the canonical name.
- Register it everywhere at once, or the tests will tell you which one you missed: the
  registry, `ShippedTools`, the characterisation goldens, `PipelineTest`'s order golden,
  `docs/tools/<name>.md`, the identifier index, and the tool list in `CLAUDE.md` and
  `docs/pipeline.md`.

Extending the pipeline from a *consuming project*, rather than shipping a tool here, is a
different question with its own document: [docs/extending-the-pipeline.md](../docs/extending-the-pipeline.md).
