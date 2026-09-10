<?php

declare(strict_types=1);

/*
 * php-qa-ci's own QA configuration, applied when the pipeline runs against
 * this repository. The canonical worked example of qaConfig/qa.php.
 */

use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;

return static fn (QaConfigBuilder $qa): QaConfigBuilder => $qa
    // Monotonic ratchet — raise-only. The floors sit a few points under the
    // last measured covered MSI to absorb run-to-run timeout variance.
    ->withInfectionFloors(msi: 82, coveredMsi: 82)
    // Test fixtures and the TestDox printer are not first-party code to scan.
    ->withIgnoredPaths('tests/assets', 'src/PHPUnit/TestDox')
    // A pure QA/tooling library never receives a password, token or secret,
    // so there is legitimately no #[\SensitiveParameter] in its src/. This is
    // exactly the escape hatch documented for downstream consumers.
    ->withSensitiveParameterCheck(false)
    // Dead-code detection, dogfooded here first. Every PHP script under bin/
    // is an entry point the detector would otherwise never see; bin/phpunit,
    // bin/neon-lint and bin/php-parse are Composer proxies for packages and
    // are not ours to analyse.
    ->withDeadCodeDetection(true)
    ->withDeadCodeEntryPoints(
        'bin/bootstrap.php',
        'bin/config-template-ignorelist-check',
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
