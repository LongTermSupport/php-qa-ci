# Upgrading a project to php-qa-ci 8.5

The `php8.5` branch is a major cut-over: the pipeline that runs your QA is PHP, not Bash, and
the project-side configuration under `qaConfig/` is PHP too. Everything that used to be a Bash
file sourced into the pipeline is refused with a message pointing here. This page is that
migration, file by file.

What does **not** change: the `vendor/bin/qa` command and its options (`-t`, `-p`, `--json`,
`-h`), every tool alias, the phase order, the environment variables (`CI`, `QA_READONLY`,
`QA_FAIL_FAST`, `phpqaMemoryLimit`, `phpqaQuickTests`, `phpUnitCoverage`, `useInfection`,
`infectionDiffBase`, `PHP_QA_CI_PHP_EXECUTABLE`, ...), and every tool config file you may have
copied into `qaConfig/` (`phpstan.neon`, `phpunit.xml`, `php_cs.php`, `infection.json`, the
Rector and PHPArkitect configs). Your CI scripts keep working unchanged.

## 1. Require PHP 8.5

```json
"require": { "php": "^8.5" }
```

Then `composer require --dev lts/php-qa-ci:dev-php8.5@dev`.

## 2. `qaConfig/qaConfig.inc.bash` becomes `qaConfig/qa.php`

The Bash file was sourced into the pipeline and could set any variable. The PHP file returns a
closure that receives the pipeline's `QaConfigBuilder` and returns the adjusted one; every
setting is a typed method, so a misspelt setting fails at load time instead of being ignored.

Before:

```bash
export phpqaMemoryLimit=8G
export useInfection=0
mutationScoreIndicator=82
coveredCodeMSI=84
pathsToIgnore+=( "tests/assets" )
arkitectExcludePaths+=("Quote/API")
export useSensitiveParameterCheck=0
```

After (`qaConfig/qa.php`):

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    ->withMemoryLimit('8G')
    ->withInfection(false)
    ->withInfectionFloors(msi: 82, coveredMsi: 84)
    ->withIgnoredPaths('tests/assets')
    ->withArkitectExcludedPaths('Quote/API')
    ->withSensitiveParameterCheck(false);
```

Every method on the builder:

| Bash variable | Builder method |
| --- | --- |
| `phpqaMemoryLimit` | `withMemoryLimit(string)` |
| `pathsToIgnore+=(...)` | `withIgnoredPaths(string ...$paths)` |
| `pathsToCheck+=(...)` | `withCheckedPaths(string ...$paths)` |
| `phpUnitCoverage` | `withPhpUnitCoverage(bool)` |
| `phpUnitIterativeMode` | `withPhpUnitIterativeMode(bool)` |
| `useInfection` | `withInfection(bool)` |
| `mutationScoreIndicator`, `coveredCodeMSI` | `withInfectionFloors(int $msi, int $coveredMsi)` |
| `infectionThreads` | `withInfectionThreads(int)` |
| `infectionDiffBase`, `infectionDiffCoveredMsi` | `withInfectionDiffBase(?string $gitRef, int $coveredMsi = 100)` |
| `useArkitect` | `withArkitect(bool)` |
| `arkitectExcludePaths+=(...)` | `withArkitectExcludedPaths(string ...$paths)` |
| `useSensitiveParameterCheck` | `withSensitiveParameterCheck(bool)` |
| `twigDirectories` (Symfony) | `withTwigDirectories(string ...$dirs)` |
| `yamlDirectories` (Symfony) | `withYamlDirectories(string ...$dirs)` |

Anything the Bash file did beyond setting these (running commands, exporting other variables)
belongs in a hook (section 4) or in your CI script. The environment variables still work for
per-run overrides; `qa.php` is applied after them, so it wins.

Delete `qaConfig.inc.bash` once `qa.php` is in place: the pipeline refuses to run while the Bash
file exists.

## 3. `qaConfig/tools/<tool>.inc.bash` becomes `qaConfig/tools/<tool>.php`

A Bash override replaced a whole lane with arbitrary shell. A PHP override returns a
`ToolInterface` implementation that replaces the shipped lane; it receives the `ToolContext`
(configuration, process runner, PHP invoker, console output) and returns a `ToolResultDto`.

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

return new class implements ToolInterface {
    public function name(): string
    {
        return 'phpstan';
    }

    public function identifier(): string
    {
        return 'myproject.phpstan';
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $result = $context->php->withoutXdebug(
            $context->config->paths->pharDir . '/phpstan.phar',
            ['analyse', '--configuration=custom-phpstan.neon', '--level=8', ...$context->config->pathsToCheck],
            $context->config->paths->projectRoot,
        );

        return $result->succeeded() ? ToolResultDto::passed() : ToolResultDto::failed('PHPStan reported errors');
    }
};
```

A tool never calls `exit`, `echo` or a shell string: it prints through `$context->writeln()` and
runs commands through `$context->php` or `$context->processes`. Delete the `.inc.bash` file;
the pipeline refuses a lane whose Bash override is still present.

## 4. `hookPre.bash` / `hookPost.bash` become `hookPre.php` / `hookPost.php`

Each returns a callable that receives the `ToolContext`. The pre hook runs after configuration
and before the lock; the post hook runs only after every tool passed.

```php
<?php

declare(strict_types=1);

use LTS\PHPQA\Pipeline\Tool\ToolContext;

return static function (ToolContext $context): void {
    $context->writeln('warming the cache');
    $context->php->withoutXdebug('bin/console', ['cache:warmup'], $context->config->paths->projectRoot);
};
```

To fail the run from a hook, throw. Delete the `.bash` files.

## 5. Check the run

```bash
QA_READONLY=1 CI=true vendor/bin/qa
```

A leftover Bash file is reported first, naming the file and the section above that replaces it.
Once the run is green, commit the new `qaConfig/` files with the removal of the old ones in one
commit so the switch is atomic.
