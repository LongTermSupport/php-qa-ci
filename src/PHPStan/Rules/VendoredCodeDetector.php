<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

/**
 * Pure, framework-free decision: does a source file belong to a third-party
 * (vendored) dependency of the analysed project, or to the project itself?
 *
 * Extracted so any rule that must treat project-owned code differently from its
 * dependencies (e.g. {@see ForbidMockingFinalClassRule}) shares ONE correct
 * definition, and so the decision can be unit-tested without PHPStan's Scope or
 * RuleTestCase (PHPStan ships as a PHAR here, so neither is autoloadable as a
 * Composer class).
 *
 * The decision is made against the analysed project's OWN vendor directory —
 * `<currentWorkingDirectory>/vendor/` — NOT a bare `/vendor/` substring of the
 * absolute path. The substring test is subtly wrong: it misfires the moment the
 * project ROOT itself lives under a `/vendor/` path, which is the normal case
 * when a package is developed in-place inside a consumer's vendor/ (e.g. fixing
 * php-qa-ci from vendor/lts/php-qa-ci). There the project's own src files
 * contain `/vendor/` yet are emphatically the code under development, not
 * vendored dependencies.
 *
 * PHPStan sets its current working directory to the analysed project root, so
 * `%currentWorkingDirectory%` is the correct anchor (injected via the neon).
 */
final readonly class VendoredCodeDetector
{
    private const string VENDOR_DIR = '/vendor/';

    public function __construct(
        private string $currentWorkingDirectory,
    ) {
    }

    /**
     * @param string|null $fileName the class's source file, or null when reflection
     *                              cannot resolve one (PHP-internal / eval'd code) —
     *                              such code is not project-owned, so it is treated
     *                              as vendored (i.e. skipped by owning rules)
     */
    public function isVendoredCode(?string $fileName): bool
    {
        if (null === $fileName) {
            return true;
        }

        return str_starts_with($fileName, rtrim($this->currentWorkingDirectory, '/') . self::VENDOR_DIR);
    }
}
