<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Templates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Safe\file_get_contents;
use function Safe\glob;
use function Safe\json_decode;
use function Safe\preg_match;
use function Safe\preg_match_all;

/**
 * Every shipped GitHub Actions workflow that derives a PHP version from a
 * consumer's composer.json constraint must be able to produce the version
 * this package itself requires. The detection is a hand-written list of
 * versions, so a PHP bump that updates composer.json but not every workflow
 * leaves a template that quietly selects an OLDER PHP for a consumer on the
 * new one. This pins each list, and each fallback default, to the version
 * composer.json requires.
 *
 * Two detection shapes ship: a `for V in ...` loop with a `PHP_VERSION=`
 * default, and an if/elif chain of `*"x.y"*` matches with a default in the
 * else branch. A workflow with neither is not a detecting workflow and is
 * skipped.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class GithubActionsPhpVersionDetectionTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    #[Test]
    #[DataProvider('provideDetectingWorkflows')]
    public function everyVersionListCanSelectTheRequiredPhp(string $workflow): void
    {
        $required = self::requiredPhpVersion();
        $yaml     = file_get_contents($workflow);

        $lists = self::versionLists($yaml);
        self::assertNotSame([], $lists, $workflow . ' matched as a detecting workflow but no version list was parsed');

        foreach ($lists as $list) {
            self::assertContains($required, $list, \sprintf(
                '%s: the PHP version detection list [%s] cannot select PHP %s, which composer.json requires; a consumer on %s would get an older PHP',
                self::relative($workflow),
                implode(', ', $list),
                $required,
                $required,
            ));
        }
    }

    #[Test]
    #[DataProvider('provideDetectingWorkflows')]
    public function everyFallbackDefaultIsTheRequiredPhp(string $workflow): void
    {
        $required = self::requiredPhpVersion();
        $yaml     = file_get_contents($workflow);

        $defaults = self::fallbackDefaults($yaml);
        self::assertNotSame([], $defaults, $workflow . ' has a version list but no fallback default was parsed');

        foreach ($defaults as $default) {
            self::assertSame($required, $default, \sprintf(
                '%s: the fallback PHP version is %s but composer.json requires %s',
                self::relative($workflow),
                $default,
                $required,
            ));
        }
    }

    #[Test]
    public function theShippedConsumerTemplateIsIdenticalToTheWorkflowThisRepoRuns(): void
    {
        self::assertSame(
            file_get_contents(self::ROOT . '/.github/workflows/qa.yml'),
            file_get_contents(self::ROOT . '/templates/github-actions/php-qa-ci.yml'),
            'templates/github-actions/php-qa-ci.yml is the copy consumers install; it must stay byte-identical to .github/workflows/qa.yml',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideDetectingWorkflows(): iterable
    {
        $files = [
            ...glob(self::ROOT . '/.github/workflows/*.yml'),
            ...glob(self::ROOT . '/templates/github-actions/*.yml'),
        ];
        foreach ($files as $file) {
            if ([] === self::versionLists(file_get_contents($file))) {
                continue;
            }
            yield self::relative($file) => [$file];
        }
    }

    /** The major.minor this package requires, from composer.json's php constraint. */
    private static function requiredPhpVersion(): string
    {
        $composer = json_decode(file_get_contents(self::ROOT . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);
        $constraint = $composer['require']['php'] ?? null;
        self::assertIsString($constraint);
        self::assertSame(1, preg_match('/(\d+\.\d+)/', $constraint, $matches), 'composer.json php constraint carries no major.minor: ' . $constraint);

        return $matches[1];
    }

    /**
     * Every version list in the file: each `for V in 8.4 8.3 ...; do` loop,
     * and the set of `*"x.y"*` arms of each if/elif chain.
     *
     * @return list<list<string>>
     */
    private static function versionLists(string $yaml): array
    {
        $lists = [];

        preg_match_all('/for V in ((?:\d+\.\d+\s*)+); do/', $yaml, $loops);
        foreach ($loops[1] as $loop) {
            $lists[] = array_values(array_filter(explode(' ', trim($loop)), static fn (string $v): bool => '' !== $v));
        }

        preg_match_all('/\$PHP_CONSTRAINT" == \*"(\d+\.\d+)"\*/', $yaml, $arms);
        if ([] !== $arms[1]) {
            $lists[] = $arms[1];
        }

        return $lists;
    }

    /**
     * Every fallback default: `PHP_VERSION=8.3` before a loop, and the
     * `PHP_VERSION="8.5"` in the else branch of a chain.
     *
     * @return list<string>
     */
    private static function fallbackDefaults(string $yaml): array
    {
        preg_match_all('/^\s*PHP_VERSION="?(\d+\.\d+)"?\s*$/m', $yaml, $matches);

        $defaults = [];
        foreach ($matches[1] as $version) {
            $defaults[] = $version;
        }

        // Inside an if/elif chain every arm also assigns PHP_VERSION="x.y"; only the
        // LAST assignment in a chain is the default, so keep assignments that are not
        // preceded by a matching arm.
        preg_match_all('/== \*"(\d+\.\d+)"\* \]\]; then\s*\n\s*PHP_VERSION="(\d+\.\d+)"/', $yaml, $armAssignments);
        $armValues = array_count_values($armAssignments[2]);
        $result    = [];
        foreach ($defaults as $default) {
            if (isset($armValues[$default]) && $armValues[$default] > 0) {
                --$armValues[$default];

                continue;
            }
            $result[] = $default;
        }

        return $result;
    }

    private static function relative(string $path): string
    {
        return substr($path, \strlen(self::ROOT) + 1);
    }
}
