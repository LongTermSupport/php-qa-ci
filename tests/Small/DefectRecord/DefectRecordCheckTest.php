<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\DefectRecord;

use LTS\PHPQA\DefectRecord\DefectRecordCheck;
use LTS\PHPQA\DefectRecord\DefectRecordReader;
use LTS\PHPQA\DefectRecord\Dto\DefectRecordDto;
use LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto;
use LTS\PHPQA\DefectRecord\Dto\NoPatternConclusionDto;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The thin runner the phpstanIgnoreJustification lane calls for the defect
 * record: print what was read, exit 0 or 1. A record nobody has written passes;
 * a malformed one fails with every problem named.
 *
 * @internal
 */
#[CoversClass(DefectRecordCheck::class)]
#[UsesClass(DefectRecordReader::class)]
#[UsesClass(DefectRecordDto::class)]
#[UsesClass(DeferredDefectDto::class)]
#[UsesClass(NoPatternConclusionDto::class)]
#[Small]
final class DefectRecordCheckTest extends TestCase
{
    private const string RECORD = 'qaConfig/defect-record.neon';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-defect-record-check');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function noRecordPasses(): void
    {
        $this->expectOutputString('Defect record: no qaConfig/defect-record.neon, nothing recorded.' . \PHP_EOL);
        self::assertSame(0, new DefectRecordCheck()->run($this->project->path));
    }

    #[Test]
    public function aWellFormedRecordPassesWithItsCounts(): void
    {
        $this->project->write(self::RECORD, "deferred:\n    -\n        defect: x\n        found: src/A.php\n        deferredBy: Owner\n");

        $this->expectOutputString('Defect record: 1 deferred defect and 0 no-pattern conclusions, all well-formed.' . \PHP_EOL);
        self::assertSame(0, new DefectRecordCheck()->run($this->project->path));
    }

    #[Test]
    public function theCountsArePluralisedFromTheRecord(): void
    {
        $this->project->write(
            self::RECORD,
            "deferred:\n    - {defect: x, found: a, deferredBy: Owner}\n    - {defect: y, found: b, deferredBy: Owner}\n"
            . "noPattern:\n    - {defect: z, found: c, conclusion: none, techniques: [p, q]}\n",
        );

        $this->expectOutputString('Defect record: 2 deferred defects and 1 no-pattern conclusion, all well-formed.' . \PHP_EOL);
        self::assertSame(0, new DefectRecordCheck()->run($this->project->path));
    }

    #[Test]
    public function aMalformedRecordFailsNamingEveryProblem(): void
    {
        $this->project->write(self::RECORD, "deferred:\n    -\n        defect: x\n        clas: y\n");

        \Safe\ob_start();

        try {
            $exit = new DefectRecordCheck()->run($this->project->path);
        } finally {
            $printed = (string)ob_get_clean();
        }

        self::assertSame(1, $exit);
        self::assertStringContainsString('ERROR — qaConfig/defect-record.neon is malformed', $printed);
        self::assertStringContainsString('deferred #1 "found" must be a non-empty string', $printed);
        self::assertStringContainsString('deferred #1 "deferredBy" must be a non-empty string', $printed);
        self::assertStringContainsString('deferred #1 has an unknown field "clas"', $printed);
        self::assertStringContainsString('docs/tools/phpstanIgnoreJustification.md', $printed);
    }
}
