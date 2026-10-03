<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\ProjectRecord;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\JustificationFindingDto;
use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto;
use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto;
use LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck;
use LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationDetector;
use LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain;
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
#[UsesClass(NeonIncludeChain::class)]
#[UsesClass(NeonIncludeChainDto::class)]
#[UsesClass(NeonRecordFileDto::class)]
#[Small]
final class IgnoreErrorsJustificationCheckTest extends TestCase
{
    private const string QA_CONFIG_PHPSTAN_NEON = '/qaConfig/phpstan.neon';

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
            $this->root . self::QA_CONFIG_PHPSTAN_NEON,
            "parameters:\n    ignoreErrors:\n        # The generated client reaches a class that only exists at runtime;\n        # scoped to the generated directory.\n        -\n            identifier: class.notFound\n            path: ../src/Generated/*\n",
        );

        $this->expectOutputString('PHPStan project record: 1 ignoreErrors entry, all justified.' . \PHP_EOL);
        self::assertSame(0, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    #[Test]
    public function theEntryCountIsPluralisedFromTheRecord(): void
    {
        \Safe\file_put_contents(
            $this->root . self::QA_CONFIG_PHPSTAN_NEON,
            "parameters:\n    ignoreErrors:\n        # The generated client reaches a class that only exists at runtime;\n        # scoped to the generated directory.\n        - '#Class Generated\\\\Client not found#'\n        # The legacy importer builds SQL from trusted constants only, and is deleted\n        # in the next release; scoped to that one file.\n        - '#Raw SQL#'\n",
        );

        $this->expectOutputString('PHPStan project record: 2 ignoreErrors entries, all justified.' . \PHP_EOL);
        self::assertSame(0, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    #[Test]
    public function anUnjustifiedRecordFailsNamingTheEntry(): void
    {
        \Safe\file_put_contents(
            $this->root . self::QA_CONFIG_PHPSTAN_NEON,
            "parameters:\n    ignoreErrors:\n        # legacy\n        -\n            identifier: class.notFound\n",
        );

        $this->expectOutputRegex('/qaConfig\/phpstan.neon:4.*paste/s');
        self::assertSame(1, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    /** The failure report in full: banner, every fault of every file in order, then the remedy. */
    #[Test]
    public function aFailingRecordPrintsEveryFaultBetweenTheBannerAndTheRemedy(): void
    {
        \Safe\file_put_contents($this->root . self::QA_CONFIG_PHPSTAN_NEON, "parameters:\n    ignoreErrors:\n        - '#Raw SQL#'\n        - '#Undefined#'\n");
        $noJustification = '    no justification: add a comment directly above the entry naming the hazard accepted and why it is acceptable at this path' . \PHP_EOL;

        $this->expectOutputString(
            \PHP_EOL . 'ERROR — ignoreErrors entries without a usable justification' . \PHP_EOL
            . '------------------------------------------------------------' . \PHP_EOL
            . "qaConfig/phpstan.neon:3  '#Raw SQL#'" . \PHP_EOL . $noJustification
            . "qaConfig/phpstan.neon:4  '#Undefined#'" . \PHP_EOL . $noJustification
            . \PHP_EOL . 'Every ignoreErrors entry is an exception the project has decided to keep. Write, directly'
            . ' above it, what the rule would report there and why that is acceptable at that path, in a'
            . ' sentence that fits no other entry. The record is qaConfig/phpstan.neon and every file it'
            . ' includes. See docs/tools/phpstan.md, "Suppressing Errors".' . \PHP_EOL,
        );
        self::assertSame(1, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    #[Test]
    public function anUnjustifiedEntryInAnIncludedBaselineFailsNamingTheIncludedFile(): void
    {
        \Safe\file_put_contents($this->root . self::QA_CONFIG_PHPSTAN_NEON, "includes:\n    - phpstan-baseline.neon\n\nparameters:\n    level: max\n");
        \Safe\file_put_contents(
            $this->root . '/qaConfig/phpstan-baseline.neon',
            "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^Call to an undefined method#'\n\t\t\tcount: 1\n\t\t\tpath: ../src/Foo.php\n",
        );

        $this->expectOutputRegex('/qaConfig\/phpstan-baseline.neon:3  message:.*no justification/s');
        self::assertSame(1, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    #[Test]
    public function everyFileInTheChainIsCountedWhenAllAreJustified(): void
    {
        \Safe\file_put_contents(
            $this->root . self::QA_CONFIG_PHPSTAN_NEON,
            "includes:\n    - ../config/phpstan-generated.neon\n\nparameters:\n    ignoreErrors:\n        # The legacy importer builds SQL from trusted constants only, and is deleted\n        # in the next release; scoped to that one file.\n        - '#Raw SQL#'\n",
        );
        \Safe\mkdir($this->root . '/config');
        \Safe\file_put_contents(
            $this->root . '/config/phpstan-generated.neon',
            "parameters:\n    ignoreErrors:\n        # The generated client reaches a class that only exists at runtime;\n        # scoped to the generated directory.\n        -\n            identifier: class.notFound\n            path: ../src/Generated/*\n",
        );

        $this->expectOutputString('PHPStan project record: 2 ignoreErrors entries, all justified, across 2 files.' . \PHP_EOL);
        self::assertSame(0, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    #[Test]
    public function anEntryWrittenInlineCannotEscapeTheCheck(): void
    {
        \Safe\file_put_contents($this->root . self::QA_CONFIG_PHPSTAN_NEON, "parameters:\n    ignoreErrors: ['#Raw SQL#', '#Undefined#']\n");

        $this->expectOutputRegex('/qaConfig\/phpstan.neon  2 ignoreErrors entries are written in a form.*each as a `-` item/s');
        self::assertSame(1, new IgnoreErrorsJustificationCheck()->run($this->root));
    }

    #[Test]
    public function anIncludeThatCannotBeFollowedFails(): void
    {
        \Safe\file_put_contents($this->root . self::QA_CONFIG_PHPSTAN_NEON, "includes:\n    - missing.neon\n");

        $this->expectOutputRegex('/qaConfig\/phpstan.neon includes missing.neon, which does not exist/');
        self::assertSame(1, new IgnoreErrorsJustificationCheck()->run($this->root));
    }
}
