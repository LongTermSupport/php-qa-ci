<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness;

use Iterator;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\OutdatedPackageDto;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\OutdatedOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading `composer outdated --locked --direct --format=json`. The sample is
 * Composer 2.11's own shape.
 *
 * @internal
 */
#[CoversClass(OutdatedOutput::class)]
#[CoversClass(OutdatedPackageDto::class)]
#[Small]
final class OutdatedOutputTest extends TestCase
{
    private const string SAMPLE = <<<'JSON'
        {
            "locked": [
                {
                    "name": "Acme/Widgets",
                    "direct-dependency": true,
                    "version": "v0.2.1",
                    "latest": "v0.3.0",
                    "latest-status": "update-possible"
                },
                {
                    "name": "roave/security-advisories",
                    "version": "dev-master 7597e92",
                    "latest": "dev-master 7252ec7",
                    "latest-status": "semver-safe-update"
                }
            ]
        }
        JSON;

    #[Test]
    public function itReadsEachOutdatedPackageWithItsLockedAndNewestVersion(): void
    {
        $output = new OutdatedOutput(self::SAMPLE);

        self::assertTrue($output->answered());
        self::assertEquals(
            [
                new OutdatedPackageDto('acme/widgets', 'v0.2.1', 'v0.3.0'),
                new OutdatedPackageDto('roave/security-advisories', 'dev-master 7597e92', 'dev-master 7252ec7'),
            ],
            $output->packages(),
        );
    }

    #[Test]
    public function anEmptyListIsAnAnswerThatNothingIsOutdated(): void
    {
        $output = new OutdatedOutput("{\n    \"locked\": []\n}");

        self::assertTrue($output->answered());
        self::assertSame([], $output->packages());
    }

    #[Test]
    #[DataProvider('provideOutputThatIsNoAnswer')]
    public function anythingElseIsNoAnswer(string $printed): void
    {
        $output = new OutdatedOutput($printed);

        self::assertFalse($output->answered());
        self::assertSame([], $output->packages());
    }

    /** @return Iterator<string, array{string}> */
    public static function provideOutputThatIsNoAnswer(): Iterator
    {
        yield 'nothing' => [''];
        yield 'a shell shim' => ["exec /usr/bin/composer \"\$@\"\n"];
        yield 'json that is not an object' => ['[]'];
        yield 'an object without the list' => ['{"installed": []}'];
        yield 'a list that is not a list' => ['{"locked": "none"}'];
    }

    #[Test]
    public function anEntryWithoutAStringNameOrVersionsIsSkipped(): void
    {
        $output = new OutdatedOutput('{"locked": [{"name": 1}, {"name": "a/b", "version": "1.0.0"}, "x", {"name": "a/c", "version": "1.0.0", "latest": "1.1.0"}]}');

        self::assertEquals([new OutdatedPackageDto('a/c', '1.0.0', '1.1.0')], $output->packages());
    }
}
