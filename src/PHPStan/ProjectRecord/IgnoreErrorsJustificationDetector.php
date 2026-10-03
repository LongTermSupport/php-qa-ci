<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\ProjectRecord;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\JustificationFindingDto;

/**
 * Reads a phpstan.neon and checks that every ignoreErrors entry is preceded by
 * a comment that could be a reason: long enough to name the hazard accepted
 * and the scope, and not a phrase that fits any entry unchanged. This is the
 * toolchain's convention for a project record PHPStan gives no field for. The
 * check cannot tell whether a sentence is true; that is the reviewer's, which
 * is why the same entries are listed where a vacuous one sits beside its
 * neighbours.
 *
 * @internal
 */
final readonly class IgnoreErrorsJustificationDetector
{
    public const int MIN_LENGTH = 40;

    private const string GENERIC = '/^(needed for now|for now|legacy( code)?|todo|fixme|wip|temporary|temp|will fix later|fix later|too noisy|noisy|known issue|ignore|false positive)\W*$/i';

    /** @return list<JustificationFindingDto> */
    public function check(string $neon): array
    {
        $lines    = explode("\n", $neon);
        $findings = [];
        foreach ($this->entries(...$lines) as $i => $entry) {
            $fault = $this->faultIn($this->commentAbove($i, ...$lines));
            if (null !== $fault) {
                $findings[] = new JustificationFindingDto(line: $i + 1, entry: $entry, fault: $fault);
            }
        }

        return $findings;
    }

    /**
     * How many ignoreErrors entries are written as `-` items, the only form
     * a justification comment can sit above. NEON may decode more: the rest
     * are written in a form this detector cannot read.
     */
    public function entryCount(string $neon): int
    {
        return \count($this->entries(...explode("\n", $neon)));
    }

    /** @return array<int, string> each entry's first meaningful line, keyed by the index of its `-` line */
    private function entries(string ...$lines): array
    {
        for ($i = 0, $n = \count($lines); $i < $n; ++$i) {
            if (1 === \Safe\preg_match('/^(\s*)ignoreErrors:\s*$/', $lines[$i], $m)) {
                /** @var array{string, string} $m the one group always participates in a match */
                return $this->itemsUnder($i, \strlen($m[1]), ...$lines);
            }
        }

        return [];
    }

    /**
     * The `-` items directly under the ignoreErrors key on line $keyLine.
     *
     * @return array<int, string>
     */
    private function itemsUnder(int $keyLine, int $keyIndent, string ...$lines): array
    {
        $entries    = [];
        $itemIndent = null;
        for ($i = $keyLine + 1, $n = \count($lines); $i < $n; ++$i) {
            $line = $lines[$i];
            if ('' === trim($line)) {
                continue;
            }

            if ($this->isComment($line)) {
                continue;
            }

            $indent = \strlen($line) - \strlen(ltrim($line));
            if ($indent <= $keyIndent) {
                break;
            }

            if (1 !== \Safe\preg_match('/^\s*-(?:\s+|$)(.*)$/', $line, $m)) {
                continue;
            }

            $itemIndent ??= $indent;
            if ($indent !== $itemIndent) {
                continue;
            }

            /** @var array{string, string} $m the one group always participates in a match: '' for a bare `-` */
            $inline      = trim($m[1]);
            $entries[$i] = '' !== $inline ? $inline : $this->firstMeaningfulLine($i + 1, ...$lines);
        }

        return $entries;
    }

    private function isComment(string $line): bool
    {
        return str_starts_with(ltrim($line), '#');
    }

    private function firstMeaningfulLine(int $from, string ...$lines): string
    {
        for ($i = $from, $n = \count($lines); $i < $n; ++$i) {
            $text = trim($lines[$i]);
            if ('' !== $text && !str_starts_with($text, '#')) {
                return $text;
            }
        }

        return '';
    }

    /**
     * The contiguous comment lines directly above the entry, joined.
     */
    private function commentAbove(int $entryIndex, string ...$lines): string
    {
        $parts = [];
        foreach (array_reverse(\array_slice($lines, 0, $entryIndex)) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            if (!$this->isComment($line)) {
                break;
            }

            array_unshift($parts, trim(ltrim(ltrim($line), '#')));
        }

        return trim(implode(' ', $parts));
    }

    private function faultIn(string $comment): ?string
    {
        if ('' === $comment) {
            return 'no justification: add a comment directly above the entry naming the hazard accepted and why it is acceptable at this path';
        }

        if (1 === \Safe\preg_match(self::GENERIC, $comment)) {
            return \sprintf('the justification "%s" could be pasted onto any entry unchanged; name the hazard accepted and the scope', $comment);
        }

        if (\strlen($comment) < self::MIN_LENGTH) {
            return \sprintf('the justification "%s" is too short to name a hazard and a scope', $comment);
        }

        return null;
    }
}
