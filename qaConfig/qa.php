<?php

declare(strict_types=1);

/*
 * php-qa-ci's own QA configuration, applied when the pipeline runs against
 * this repository. The canonical worked example of qaConfig/qa.php.
 */

use LTS\PHPQA\Changelog\ReleaseVersionPolicy;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    // Monotonic ratchet — raise-only. The floors sit a few points under the
    // last measured covered MSI to absorb run-to-run timeout variance.
    ->withInfectionFloors(msi: 82, coveredMsi: 82)
    // Test fixtures are deliberate instances, not first-party code to scan.
    ->withIgnoredPaths('tests/assets')
    // Every PHP file outside src/ and tests/ is accounted for here, with the
    // reason it is not analysed (the analysedPaths lane, docs/tools/analysedPaths.md).
    // A new PHP directory fails the run until it is added here or analysed.
    ->withUnanalysedPath(
        'configDefaults',
        'tool configs loaded by the PHAR tools on every run; the Rector, PHP-CS-Fixer, PHPArkitect and analyser classes they use exist only inside those PHARs',
    )
    ->withUnanalysedPath(
        'templates',
        'starting points copied into a consumer qaConfig/, where they run against that project; the PHPArkitect one uses classes only its PHAR holds',
    )
    ->withUnanalysedPath(
        'qaConfig',
        'the tool configs of this repository itself: every run loads qa.php, so an error in it fails the run, and the PHAR tools whose classes they use load the rest',
    )
    ->withUnanalysedPath(
        'bin/bootstrap.php',
        'an entry point the deadCode lane analyses through this phpstan.neon, as one of its withDeadCodeEntryPoints()',
    )
    ->withUnanalysedPath(
        '.claude/skills/defence-before-fix/examples',
        'copy-and-adapt example rules the defence-before-fix skill shows an agent, under a namespace no autoloader maps',
    )
    ->withUnanalysedPath(
        'CLAUDE/Plan/Completed/00009-upstream-php-src-bug-report-opcache-const-comparison',
        'the reproducer filed with php-src, kept verbatim as the plan record; it exists to crash OPcache',
    )
    // A pure QA/tooling library never receives a password, token or secret,
    // so there is legitimately no #[\SensitiveParameter] in its src/. This is
    // exactly the escape hatch documented for downstream consumers.
    ->withSensitiveParameterCheck(false)
    // Every change a consuming project can notice is recorded in CHANGELOG.md,
    // which is also what the release workflow reads to cut the next release
    // (CLAUDE/releases.md). The watched paths are what ships to, or is
    // deployed into, a consumer; tests/, docs/, CLAUDE/ and this qaConfig/ are
    // not. The .claude/ entries follow scripts/lib/deploy-manifest.inc.bash.
    ->withChangelogCheck(true)
    ->withChangelogWatchedPaths(
        'src/',
        'bin/',
        'configDefaults/',
        'templates/',
        'scripts/',
        'git-hooks/',
        'phpstorm/',
        'vendor-phar/',
        'vendor-bin/',
        'build/',
        'phive.xml',
        'composer.json',
        'rules-*.neon',
        '.claude/agents/php-qa-ci_*',
        '.claude/hooks/php-qa-ci__*',
        '.claude/skills/branch-policy/',
        '.claude/skills/defence-before-fix/',
        '.claude/skills/gh-links/',
        '.claude/skills/phpstan-fixer/',
        '.claude/skills/phpstan-runner/',
        '.claude/skills/phpunit-fixer/',
        '.claude/skills/phpunit-runner/',
        '.claude/skills/qa/',
        '.claude/skills/qa-tool-runner/',
    )
    // The exception to semantic versioning: the major is the PHP line this
    // branch targets (`^8.5` is 85), so it never moves and a breaking change
    // releases the next minor. A new PHP line is a new branch, not a release.
    ->withReleaseVersionPolicy(ReleaseVersionPolicy::lockedMajorFromPhpRequirement())
    // Dead-code detection, dogfooded here first. Every PHP script under bin/
    // is an entry point the detector would otherwise never see; bin/phpunit,
    // bin/neon-lint and bin/php-parse are Composer proxies for packages and
    // are not ours to analyse.
    ->withDeadCodeDetection(true)
    ->withDeadCodeEntryPoints(
        'bin/arkitect-rule',
        'bin/bootstrap.php',
        'bin/changelog-release',
        'bin/config-template-ignorelist-check',
        'bin/hooks-daemon-full-qa-blocker',
        'bin/infection-config-source-dirs-check',
        'bin/managed-source',
        'bin/mdlinks',
        'bin/package-type-check',
        'bin/phpstan-ignore-justification',
        'bin/psr4-validate',
        'bin/qa',
        'bin/rule-doc',
        'bin/rules',
        'bin/sensitive-parameter-usage',
        'bin/single-rule-report',
        'bin/version-pins-check',
    )
;
