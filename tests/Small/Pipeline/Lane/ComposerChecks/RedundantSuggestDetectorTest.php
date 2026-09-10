<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\ComposerChecks;

use LTS\PHPQA\Pipeline\Lane\ComposerChecks\RedundantSuggestDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RedundantSuggestDetector::class)]
#[Small]
final class RedundantSuggestDetectorTest extends TestCase
{
    private const string SUGGESTED = 'acme/suggested';

    private const string REQUIRED = 'acme/required';

    private const string CONSTRAINT = '^1.0';

    #[Test]
    public function aSuggestionThatIsAlsoRequiredIsReported(): void
    {
        $found = new RedundantSuggestDetector()->check([
            'require' => [self::REQUIRED => self::CONSTRAINT],
            'suggest' => [self::REQUIRED => 'you already have this'],
        ]);

        self::assertSame([self::REQUIRED . ' (require)'], $found);
    }

    #[Test]
    public function aSuggestionThatIsAlsoRequiredForDevIsReported(): void
    {
        $found = new RedundantSuggestDetector()->check([
            'require-dev' => [self::REQUIRED => self::CONSTRAINT],
            'suggest'     => [self::REQUIRED => 'you already have this'],
        ]);

        self::assertSame([self::REQUIRED . ' (require-dev)'], $found);
    }

    #[Test]
    public function aSuggestionOfSomethingNotRequiredIsTheWholePointAndIsNotReported(): void
    {
        $found = new RedundantSuggestDetector()->check([
            'require' => [self::REQUIRED => self::CONSTRAINT],
            'suggest' => [self::SUGGESTED => 'genuinely optional'],
        ]);

        self::assertSame([], $found);
    }

    #[Test]
    public function everyRedundantEntryIsReportedInTheOrderTheyAreDeclared(): void
    {
        $found = new RedundantSuggestDetector()->check([
            'require'     => ['acme/one' => self::CONSTRAINT],
            'require-dev' => ['acme/two' => self::CONSTRAINT],
            'suggest'     => [
                'acme/two'      => 'dev',
                self::SUGGESTED => 'fine',
                'acme/one'      => 'prod',
            ],
        ]);

        self::assertSame(['acme/two (require-dev)', 'acme/one (require)'], $found);
    }

    #[Test]
    public function aManifestWithNoSuggestBlockIsClean(): void
    {
        self::assertSame([], new RedundantSuggestDetector()->check(['require' => [self::REQUIRED => self::CONSTRAINT]]));
    }

    #[Test]
    public function malformedSectionsAreIgnoredRatherThanCrashing(): void
    {
        self::assertSame([], new RedundantSuggestDetector()->check(['suggest' => 'not an array', 'require' => 7]));
    }

    #[Test]
    public function aPackageInBothRequireSectionsIsReportedOnceAgainstRequire(): void
    {
        $found = new RedundantSuggestDetector()->check([
            'require'     => [self::REQUIRED => self::CONSTRAINT],
            'require-dev' => [self::REQUIRED => self::CONSTRAINT],
            'suggest'     => [self::REQUIRED => 'both'],
        ]);

        self::assertSame([self::REQUIRED . ' (require)'], $found);
    }
}
