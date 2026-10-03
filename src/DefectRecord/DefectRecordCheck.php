<?php

declare(strict_types=1);

namespace LTS\PHPQA\DefectRecord;

/**
 * The defect record's half of the phpstanIgnoreJustification lane: the
 * record must be readable in full, so that every deferred Defect and every
 * no-pattern conclusion in it reaches `bin/rules` and the agent summary. Thin
 * I/O around {@see DefectRecordReader}; a project with no record passes.
 *
 * @internal
 */
final readonly class DefectRecordCheck
{
    public function __construct(private DefectRecordReader $reader = new DefectRecordReader())
    {
    }

    /** @return int 0 when the record is absent or well-formed, 1 otherwise */
    public function run(string $projectRoot): int
    {
        $record = $this->reader->read($projectRoot);
        if (null === $record->path) {
            echo 'Defect record: no ' . DefectRecordReader::RELATIVE_PATH . ', nothing recorded.' . \PHP_EOL;

            return 0;
        }

        if ([] === $record->problems) {
            echo \sprintf(
                'Defect record: %s and %s, all well-formed.',
                $this->count(\count($record->deferred), 'deferred defect'),
                $this->count(\count($record->noPattern), 'no-pattern conclusion'),
            ) . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — ' . DefectRecordReader::RELATIVE_PATH . ' is malformed' . \PHP_EOL
            . '------------------------------------------------------------' . \PHP_EOL;
        foreach ($record->problems as $problem) {
            echo $problem . \PHP_EOL;
        }

        echo \PHP_EOL . 'An entry the record cannot read is not listed by bin/rules or the agent summary, so the'
            . ' Defect it records is as unseen as one never written down. Correct each entry named above.'
            . ' The format is in docs/tools/phpstanIgnoreJustification.md, "The defect record".' . \PHP_EOL;

        return 1;
    }

    private function count(int $count, string $noun): string
    {
        return \sprintf('%d %s%s', $count, $noun, 1 === $count ? '' : 's');
    }
}
