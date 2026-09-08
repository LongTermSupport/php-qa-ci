# PHPQA Infection

[Infection](https://infection.github.io/) is a Mutation Testing Framework that runs PHPUnit tests and then makes small modifications to the code and sees if these cause the unit tests to fail.

Infection runs as a **PHAR** from `vendor-phar/infection.phar` (not as a Composer dependency). It requires Xdebug to be available for code coverage.

In PHPQA we run this after the normal PHPUnit run and pass in the coverage generated with PHPUnit. This means that it will only run this tool if you have the `phpUnitCoverage` environment variable set to 1.

## Configuration

You may need to tell infection where the configuration directory for PHPUnit is. The shipped
[./configDefaults/generic/infection.json](./../../configDefaults/generic/infection.json) already
contains `"phpUnit": {"configDir": "./"}`; to point it elsewhere, override that file in your
`qaConfig/` and change the existing value:

```json
"phpUnit": {
    "configDir": "path/to/directory/with/phpunit.xml"
}
```

Here are the environment variables that you might decide to override:

- **Use Infection** `useInfection`: Set this to 0 to disable Infection
- **Minimum MSI Percentage** `mutationScoreIndicator`: The minimum [MSI](https://infection.github.io/guide/#Mutation-Score-Indicator-MSI) required for PHPQA to pass
- **Minimum Covered MSI Percentage** `coveredCodeMSI`: The minimum [covered MSI](https://infection.github.io/guide/#Covered-Code-Mutation-Score-Indicator) level required for PHPQA to pass

#### Minimum Mutation Score Indicators

Infection has been configured to require both a minimum MSI and covered MSI to be achieved for the test to pass.

By default these are set to 60% for MSI, and 80% for covered MSI. These values can be overwritten by using environment
variables. To do this, simply export the following before running qa:

 * `mutationScoreIndicator` to set the MSI level
 * `coveredCodeMSI` to set the covered MSI level

See the following page for more information on MSIs being used in CI [https://infection.github.io/guide/using-with-ci.html](https://infection.github.io/guide/using-with-ci.html)

##### Setting Minimum Score Indicators

The easiest way to override the default minimum score indicators permanently for your project is to include these in a `qaConfig.inc.bash` file in your projects `qaConfig` folder.

You can see that this is being done in the phpqa project itself in its own [qaConfig](./../../qaConfig) folder.

#### Disabling Infection


If you would like to disable infection, simply export the environment variable `useInfection` with the value `0`:

```bash
export useInfection=0
vendor/bin/qa
```

## How the lane runs

The lane is `LTS\PHPQA\Pipeline\Lane\InfectionTool` (identifier `phpqaci.infection`). Its pure parts are split out under `Lane/Infection/`: `InfectionArguments` (the argv for the full and diff lanes) and `InfectionDiffFilter` (the changed-file list from `git diff`), both unit-tested without a process.

1. Without Xdebug there is no coverage, so the lane skips.
2. Diff mode (`infectionDiffBase` set) first refuses a dirty tree under `src/` or `tests/` (`git status --porcelain`): the verdict must be reproducible from committed history alone.
3. Coverage is reused when the PHPUnit lane produced it this run (a full pipeline run with a non-empty `var/qa/phpunit_logs/coverage-xml`); otherwise (`-t infection`, or nothing on disk) one Xdebug coverage run generates it. A failing coverage run fails the lane.
4. Diff mode scopes mutation to the PHP files from `git diff <base>...HEAD --diff-filter=AM --name-only --relative -- src`, passed to Infection as positional absolute paths. An empty list skips; a failing `git diff` fails.
5. A 100% floor in force prints the "lower it honestly to 95" advisory.
6. `var/qa/infection/` is emptied and `vendor-phar/infection.phar` runs without Xdebug at low CPU priority with `--skip-initial-tests`, `--coverage`, `--threads`, `--configuration`, then either `--min-msi --min-covered-msi --log-verbosity=all` (full) or `--min-covered-msi=<infectionDiffCoveredMsi>` and the paths (diff). Any non-zero exit fails.
