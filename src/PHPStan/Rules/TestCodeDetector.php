<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

/**
 * Pure decision: is the code being analysed the project's tests, or the project itself?
 *
 * Shared by every rule that exempts test code, so they hold one definition, anchored on the
 * analysed project's root rather than on a substring of an absolute path. A bare `/tests/`
 * substring holds for every file of a checkout that itself sits under a directory named
 * `tests`, and `str_contains($namespace, 'Tests')` holds for `App\Contests`; either turns
 * production code into test code and silences the rule over it.
 *
 * PHPStan sets its current working directory to the analysed project root, so
 * `%currentWorkingDirectory%` is the anchor, injected via the neon as for VendoredCodeDetector.
 * See docs/phpstan-rules/forbid-unanchored-tests-substring-check.md.
 */
final readonly class TestCodeDetector
{
    private const string TESTS_DIR = '/tests/';

    private const string TESTS_SEGMENT = 'Tests';

    public function __construct(
        private string $currentWorkingDirectory,
    ) {
    }

    /**
     * Test code is a file under `<project root>/tests/`, or a namespace one of whose
     * `\`-separated segments is exactly `Tests`.
     */
    public function isTestCode(string $fileName, ?string $namespace): bool
    {
        if (null !== $namespace && \in_array(self::TESTS_SEGMENT, explode('\\', $namespace), true)) {
            return true;
        }

        return str_starts_with($fileName, rtrim($this->currentWorkingDirectory, '/') . self::TESTS_DIR);
    }
}
