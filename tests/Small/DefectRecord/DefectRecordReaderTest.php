<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\DefectRecord;

use LTS\PHPQA\DefectRecord\DefectRecordReader;
use LTS\PHPQA\DefectRecord\Dto\DefectRecordDto;
use LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto;
use LTS\PHPQA\DefectRecord\Dto\NoPatternConclusionDto;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Method specification section 2 (1.1.0): a Defect found and not fixed now, and
 * the conclusion that no pattern exists, are recorded where the project's other
 * decisions are enumerable. The record is qaConfig/defect-record.neon. An entry
 * the reader cannot use is a problem it names, never an entry that silently
 * drops out of the enumeration: a misspelt field would otherwise vanish.
 *
 * @internal
 */
#[CoversClass(DefectRecordReader::class)]
#[UsesClass(DefectRecordDto::class)]
#[UsesClass(DeferredDefectDto::class)]
#[UsesClass(NoPatternConclusionDto::class)]
#[Small]
final class DefectRecordReaderTest extends TestCase
{
    private const string RECORD = 'qaConfig/defect-record.neon';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-defect-record');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aProjectWithNoRecordHasAnEmptyOne(): void
    {
        $record = new DefectRecordReader()->read($this->project->path);

        self::assertNull($record->path);
        self::assertSame([], $record->deferred);
        self::assertSame([], $record->noPattern);
        self::assertSame([], $record->problems);
    }

    #[Test]
    public function aRecordOfCommentsOnlyIsPresentAndEmpty(): void
    {
        $path   = $this->project->write(self::RECORD, "# Nothing deferred yet.\n");
        $record = new DefectRecordReader()->read($this->project->path);

        self::assertSame($path, $record->path);
        self::assertSame([], $record->deferred);
        self::assertSame([], $record->noPattern);
        self::assertSame([], $record->problems);
    }

    #[Test]
    public function bothSectionsAreReadInOrder(): void
    {
        $this->project->write(self::RECORD, <<<'NEON'
            deferred:
                -
                    defect: The cache key omits the locale.
                    class: A cache key built from a subset of the inputs the value depends on.
                    found: src/Cache/KeyBuilder.php
                    deferredBy: 'Owner, pending the cache rewrite'
                -
                    defect: A timeout is swallowed in the retry loop.
                    found: src/Http/Retry.php
                    deferredBy: Owner
            noPattern:
                -
                    defect: The invoice total was rounded twice.
                    found: src/Invoice/Total.php
                    conclusion: 'No pattern exists; a text search and a reading of every rounding caller found nothing alike.'
                    techniques:
                        - 'a text search for round( over src/'
                        - 'reading every caller of Money::round()'
            NEON);

        $record = new DefectRecordReader()->read($this->project->path);

        self::assertSame([], $record->problems);
        self::assertEquals(
            [
                new DeferredDefectDto('The cache key omits the locale.', 'A cache key built from a subset of the inputs the value depends on.', 'src/Cache/KeyBuilder.php', 'Owner, pending the cache rewrite'),
                new DeferredDefectDto('A timeout is swallowed in the retry loop.', null, 'src/Http/Retry.php', 'Owner'),
            ],
            $record->deferred,
        );
        self::assertEquals(
            [
                new NoPatternConclusionDto(
                    'The invoice total was rounded twice.',
                    'src/Invoice/Total.php',
                    'No pattern exists; a text search and a reading of every rounding caller found nothing alike.',
                    ['a text search for round( over src/', 'reading every caller of Money::round()'],
                ),
            ],
            $record->noPattern,
        );
    }

    /** @param list<non-empty-string> $expected */
    #[Test]
    #[DataProvider('malformedRecords')]
    public function aMalformedRecordIsAProblemNotASilentGap(string $neon, array $expected): void
    {
        $this->project->write(self::RECORD, $neon);

        $record = new DefectRecordReader()->read($this->project->path);

        self::assertCount(\count($expected), $record->problems, implode("\n", $record->problems));
        foreach ($expected as $index => $problem) {
            self::assertArrayHasKey($index, $record->problems);
            self::assertStringStartsWith($problem, $record->problems[$index]);
        }

        self::assertSame([], $record->deferred, 'a malformed entry is not listed as if it were well-formed');
        self::assertSame([], $record->noPattern);
    }

    /** @return iterable<string, array{string, list<non-empty-string>}> */
    public static function malformedRecords(): iterable
    {
        yield 'not NEON' => ["deferred:\n  - defect: [unclosed\n", ['qaConfig/defect-record.neon is not valid NEON']];

        yield 'not a mapping' => ["- a list\n", ['qaConfig/defect-record.neon: the record must be a mapping with the sections deferred and noPattern']];

        yield 'a misspelt section' => [
            "deffered:\n    -\n        defect: x\n",
            ['qaConfig/defect-record.neon: unknown section "deffered"; the sections are deferred and noPattern'],
        ];

        yield 'a section that is not a list' => [
            "deferred:\n    defect: x\n",
            ['qaConfig/defect-record.neon: deferred must be a list of entries, each a mapping of fields'],
        ];

        yield 'an entry that is not a mapping' => [
            "deferred:\n    - just a sentence\n",
            ['qaConfig/defect-record.neon: deferred #1 must be a mapping of fields'],
        ];

        yield 'missing and empty fields' => [
            "deferred:\n    -\n        defect: ''\n        found: src/A.php\n",
            [
                'qaConfig/defect-record.neon: deferred #1 "defect" must be a non-empty string',
                'qaConfig/defect-record.neon: deferred #1 "deferredBy" must be a non-empty string',
            ],
        ];

        yield 'a misspelt field' => [
            "deferred:\n    -\n        defect: x\n        clas: y\n        found: src/A.php\n        deferredBy: Owner\n",
            ['qaConfig/defect-record.neon: deferred #1 has an unknown field "clas"; its fields are defect, class, found and deferredBy'],
        ];

        yield 'a class written but empty' => [
            "deferred:\n    -\n        defect: x\n        class: ''\n        found: src/A.php\n        deferredBy: Owner\n",
            ['qaConfig/defect-record.neon: deferred #1 "class" must be a non-empty string; leave it out when no class is apparent'],
        ];

        yield 'one technique' => [
            "noPattern:\n    -\n        defect: x\n        found: src/A.php\n        conclusion: none\n        techniques:\n            - a text search\n",
            ['qaConfig/defect-record.neon: noPattern #1 "techniques" must list at least two different techniques, each a non-empty string'],
        ];

        yield 'the same technique twice' => [
            "noPattern:\n    -\n        defect: x\n        found: src/A.php\n        conclusion: none\n        techniques: [a text search, a text search]\n",
            ['qaConfig/defect-record.neon: noPattern #1 "techniques" must list at least two different techniques, each a non-empty string'],
        ];

        yield 'a no-pattern entry with a deferred field' => [
            "noPattern:\n    -\n        defect: x\n        found: src/A.php\n        conclusion: none\n        techniques: [a, b]\n        deferredBy: Owner\n",
            ['qaConfig/defect-record.neon: noPattern #1 has an unknown field "deferredBy"; its fields are defect, found, conclusion and techniques'],
        ];
    }
}
