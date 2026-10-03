<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\ProjectRecord;

use LTS\PHPQA\Helper;
use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto;

/**
 * Pipeline lane: the project's PHPStan ignoreErrors entries, this toolchain's
 * project record, must each carry a justification a reviewer could act on.
 * The record is every NEON file PHPStan reads from qaConfig/phpstan.neon
 * through `includes:` ({@see NeonIncludeChain}), so a generated baseline or a
 * shared file cannot hold an entry the check never sees; an include that
 * cannot be followed fails the lane. Thin I/O around
 * {@see IgnoreErrorsJustificationDetector}.
 *
 * @internal
 */
final readonly class IgnoreErrorsJustificationCheck
{
    private const string RECORD = 'qaConfig/phpstan.neon';

    public function __construct(
        private IgnoreErrorsJustificationDetector $detector = new IgnoreErrorsJustificationDetector(),
        private NeonIncludeChain $chain = new NeonIncludeChain(),
    ) {
    }

    /** @return int 0 when every entry is justified, 1 otherwise */
    public function run(string $projectRoot): int
    {
        $path = $projectRoot . '/' . self::RECORD;
        if (!file_exists($path)) {
            echo 'PHPStan project record: no ' . self::RECORD . ' override, nothing to check.' . \PHP_EOL;

            return 0;
        }

        $chain    = $this->chain->resolve($path, $projectRoot);
        $faults   = $chain->problems;
        $declared = 0;
        foreach ($chain->files as $file) {
            $declared += $file->declaredEntries;
            array_push($faults, ...$this->faultsIn($file));
        }

        if ([] === $faults) {
            $files = \count($chain->files);
            echo \sprintf(
                'PHPStan project record: %d ignoreErrors entr%s, all justified%s.',
                $declared,
                1 === $declared ? 'y' : 'ies',
                1 === $files ? '' : \sprintf(', across %d files', $files),
            ) . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — ignoreErrors entries without a usable justification' . \PHP_EOL
            . '------------------------------------------------------------' . \PHP_EOL;
        foreach ($faults as $fault) {
            echo $fault . \PHP_EOL;
        }

        echo \PHP_EOL . 'Every ignoreErrors entry is an exception the project has decided to keep. Write, directly'
            . ' above it, what the rule would report there and why that is acceptable at that path, in a'
            . ' sentence that fits no other entry. The record is ' . self::RECORD . ' and every file it'
            . ' includes. See docs/tools/phpstan.md, "Suppressing Errors".' . \PHP_EOL;

        return 1;
    }

    public static function main(): int
    {
        return new self()->run(Helper::getProjectRootDirectory());
    }

    /** @return list<string> */
    private function faultsIn(NeonRecordFileDto $file): array
    {
        $faults = [];
        foreach ($this->detector->check($file->neon) as $finding) {
            $faults[] = \sprintf('%s:%d  %s%s    %s', $file->display, $finding->line, $finding->entry, \PHP_EOL, $finding->fault);
        }

        $unread = $file->declaredEntries - $this->detector->entryCount($file->neon);
        if ($unread > 0) {
            $faults[] = \sprintf(
                '%s  %d ignoreErrors entr%s written in a form the record check cannot read; write each as a `-` item under parameters.ignoreErrors with its justification directly above it',
                $file->display,
                $unread,
                1 === $unread ? 'y is' : 'ies are',
            );
        }

        return $faults;
    }
}
