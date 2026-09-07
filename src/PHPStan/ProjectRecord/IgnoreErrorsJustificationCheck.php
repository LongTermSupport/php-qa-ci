<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\ProjectRecord;

use LTS\PHPQA\Helper;

/**
 * Pipeline lane: the project's PHPStan ignoreErrors entries, this toolchain's
 * project record, must each carry a justification a reviewer could act on.
 * Thin I/O around {@see IgnoreErrorsJustificationDetector}.
 *
 * @internal
 */
final readonly class IgnoreErrorsJustificationCheck
{
    private const string RECORD = 'qaConfig/phpstan.neon';

    public function __construct(
        private IgnoreErrorsJustificationDetector $detector = new IgnoreErrorsJustificationDetector(),
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

        $neon     = \Safe\file_get_contents($path);
        $findings = $this->detector->check($neon);
        $entries  = \Safe\preg_match_all('/^\s*-(\s|$)/m', $this->ignoreErrorsBlock($neon));

        if ([] === $findings) {
            echo \sprintf('PHPStan project record: %d ignoreErrors entr%s, all justified.', $entries, 1 === $entries ? 'y' : 'ies') . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — ignoreErrors entries without a usable justification' . \PHP_EOL
            . '------------------------------------------------------------' . \PHP_EOL;
        foreach ($findings as $finding) {
            echo \sprintf('%s:%d  %s%s    %s', self::RECORD, $finding->line, $finding->entry, \PHP_EOL, $finding->fault) . \PHP_EOL;
        }

        echo \PHP_EOL . 'Every ignoreErrors entry is an exception the project has decided to keep. Write, directly'
            . ' above it, what the rule would report there and why that is acceptable at that path, in a'
            . ' sentence that fits no other entry. See docs/tools/phpstan.md, "Suppressing Errors".' . \PHP_EOL;

        return 1;
    }

    public static function main(): int
    {
        return new self()->run(Helper::getProjectRootDirectory());
    }

    /** The text from the ignoreErrors key to the next key at the same or shallower indent. */
    private function ignoreErrorsBlock(string $neon): string
    {
        $lines     = explode("\n", $neon);
        $out       = [];
        $keyIndent = null;
        foreach ($lines as $line) {
            if (null === $keyIndent) {
                if (1 === \Safe\preg_match('/^(\s*)ignoreErrors:\s*$/', $line, $m) && isset($m[1])) {
                    $keyIndent = \strlen($m[1]);
                }

                continue;
            }

            $text = trim($line);
            if ('' !== $text && !str_starts_with($text, '#') && (\strlen($line) - \strlen(ltrim($line))) <= $keyIndent) {
                break;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }
}
