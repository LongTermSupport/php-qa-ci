# Infection Config Source Directories Check

**Identifier**: `phpqaci.infectionConfigSourceDirectoriesMustExist`

An always-on check that infection.json's declared `source.directories` entries resolve, relative
to infection.json's own directory, to real directories on disk.

## What it is about

Infection resolves `source.directories` relative to the directory containing infection.json
itself, not the project root and not the process's current working directory. A project-level
`qaConfig/infection.json` override that copies a path from the shipped
`configDefaults/generic/infection.json` default, without re-deriving the relative depth for its
own (typically shallower) location, produces a path that does not exist. Infection's own Finder
then throws before generating a single mutant, discarding a fully-covered test suite's worth of
work with no coverage or configuration problem otherwise indicated.

## How it runs

- In the full pipeline, in the linting phase, immediately after the config template ignore-list
  audit.
- Standalone: `vendor/bin/qa -t infectionConfigSourceDirs`, alias `-t icsd`.
- Binary: `bin/infection-config-source-dirs-check`, delegating to
  `LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesCheck::main()`.

It is handed the same resolved infection.json path Infection itself is about to read (the
project's own `qaConfig/infection.json` override when one exists, or the shipped
`configDefaults/generic/infection.json` otherwise).

## How to fix a failure

Adjust the offending entry in `source.directories` so it resolves, relative to the directory
containing that infection.json file, to a real directory — usually by correcting the number of
`../` segments for how deep the override file actually sits.

## Known gap

The check covers `source.directories` only. Other write-target keys in infection.json — `logs.*`
and `tmpDir` — are not checked for the same kind of resolution problem. Whether to add a
containment check for those keys is left to the project owner.

## Implementation

- Decision: [`InfectionConfigSourceDirectoriesDetector`](../../src/InfectionConfig/InfectionConfigSourceDirectoriesDetector.php),
  which resolves each declared directory and checks it exists, and never executes Infection.
- Runner: [`InfectionConfigSourceDirectoriesCheck`](../../src/InfectionConfig/InfectionConfigSourceDirectoriesCheck.php),
  which reads and decodes the config file, maps the verdict to output and exit code, and prints
  the identifier.
