<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\ProjectRecord;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\JustificationFindingDto;
use LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck;
use LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The thin runner: locate the project's phpstan.neon, hand it to the
 * detector, print, exit 0 or 1. A project with no phpstan.neon override has
 * no project record of its own and passes.
 *
 * @internal
 */
#[CoversClass(IgnoreErrorsJustificationCheck::class)]
#[UsesClass(IgnoreErrorsJustificationDetector::class)]
#[UsesClass(JustificationFindingDto::class)]
#[Small]
final class IgnoreErrorsJustificationCheckTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \Safe\tempnam(sys_get_temp_dir(), 'phpqa-record-');
        \Safe\unlink($this->root);
        \Safe\mkdir($this->root . '/qaConfig', 0o755, true);
    }

    #[Test]
    public function aProjectWithoutAnOverrideHasNothingToCheck(): void
    {
        \Safe\rmdir($this->root . '/qaConfig');

        $this->expectOutputString('PHPStan project record: no qaConfig/phpstan.neon override, nothing to check.' . \PHP_EOL);
        self::assertSame(0, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    #[Test]
    public function aJustifiedRecordPasses(): void
    {
        \Safe\file_put_contents(
            $this->root . '/qaConfig/phpstan.neon',
            "parameters:\n    ignoreErrors:\n        # The generated client reaches a class that only exists at runtime;\n        # scoped to the generated directory.\n        -\n            identifier: class.notFound\n            path: ../src/Generated/*\n",
        );

        $this->expectOutputString('PHPStan project record: 1 ignoreErrors entry, all justified.' . \PHP_EOL);
        self::assertSame(0, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    #[Test]
    public function theEntryCountIsPluralisedFromTheRecord(): void
    {
        \Safe\file_put_contents(
            $this->root . '/qaConfig/phpstan.neon',
            "parameters:\n    ignoreErrors:\n        # The generated client reaches a class that only exists at runtime;\n        # scoped to the generated directory.\n        - '#Class Generated\\\\Client not found#'\n        # The legacy importer builds SQL from trusted constants only, and is deleted\n        # in the next release; scoped to that one file.\n        - '#Raw SQL#'\n",
        );

        $this->expectOutputString('PHPStan project record: 2 ignoreErrors entries, all justified.' . \PHP_EOL);
        self::assertSame(0, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    #[Test]
    public function anUnjustifiedRecordFailsNamingTheEntry(): void
    {
        \Safe\file_put_contents(
            $this->root . '/qaConfig/phpstan.neon',
            "parameters:\n    ignoreErrors:\n        # legacy\n        -\n            identifier: class.notFound\n",
        );

        $this->expectOutputRegex('/qaConfig\/phpstan.neon:4.*paste/s');
        self::assertSame(1, new IgnoreErrorsJustificationCheck()->run($this->root));
    }
}
