<?php

declare(strict_types=1);

namespace LTS\PHPQA\DefectRecord;

use LTS\PHPQA\DefectRecord\Dto\DefectRecordDto;
use LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto;
use LTS\PHPQA\DefectRecord\Dto\NoPatternConclusionDto;
use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;

/**
 * Reads qaConfig/defect-record.neon: the place method specification section 2
 * (1.1.0) requires for a Defect found and not fixed now, and for the
 * conclusion that no pattern exists, so that both are enumerable beside the
 * project's other decisions (`bin/rules`) rather than left in a conversation.
 *
 * Two sections, each a list of entries. A `deferred` entry has `defect`,
 * `found`, `deferredBy` and, where a Class is already apparent, `class`. A
 * `noPattern` entry has `defect`, `found`, `conclusion` and at least two
 * different `techniques`. Anything else is a problem naming the entry: a
 * misspelt field or section would otherwise drop out of the enumeration
 * without anyone noticing, which is the failure the record exists to prevent.
 * A malformed entry is never returned as an entry.
 *
 * @internal
 */
final readonly class DefectRecordReader
{
    public const string RELATIVE_PATH = 'qaConfig/defect-record.neon';

    private const string DEFERRED = 'deferred';

    private const string NO_PATTERN = 'noPattern';

    private const string DEFECT = 'defect';

    private const string CLASS_FIELD = 'class';

    private const string FOUND = 'found';

    private const string DEFERRED_BY = 'deferredBy';

    private const string CONCLUSION = 'conclusion';

    private const string TECHNIQUES = 'techniques';

    private const array DEFERRED_FIELDS = [self::DEFECT, self::CLASS_FIELD, self::FOUND, self::DEFERRED_BY];

    private const array NO_PATTERN_FIELDS = [self::DEFECT, self::FOUND, self::CONCLUSION, self::TECHNIQUES];

    public function read(string $projectRoot): DefectRecordDto
    {
        $path = rtrim($projectRoot, '/') . '/' . self::RELATIVE_PATH;
        if (!is_file($path)) {
            return new DefectRecordDto(null, [], [], []);
        }

        try {
            $decoded = Neon::decode(\Safe\file_get_contents($path));
        } catch (NeonException $neonException) {
            return new DefectRecordDto($path, [], [], [\sprintf('%s is not valid NEON: %s', self::RELATIVE_PATH, $neonException->getMessage())]);
        }

        if (null === $decoded || [] === $decoded) {
            return new DefectRecordDto($path, [], [], []);
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            return new DefectRecordDto($path, [], [], [$this->problem(\sprintf('the record must be a mapping with the sections %s and %s', self::DEFERRED, self::NO_PATTERN))]);
        }

        $problems = [];
        foreach (array_keys($decoded) as $section) {
            if (self::DEFERRED !== $section && self::NO_PATTERN !== $section) {
                $problems[] = $this->problem(\sprintf('unknown section "%s"; the sections are %s and %s', $section, self::DEFERRED, self::NO_PATTERN));
            }
        }

        $deferred = [];
        foreach ($this->entries($decoded, self::DEFERRED, $problems) as $number => $entry) {
            $parsed = $this->deferred($number, $entry, $problems);
            if ($parsed instanceof DeferredDefectDto) {
                $deferred[] = $parsed;
            }
        }

        $noPattern = [];
        foreach ($this->entries($decoded, self::NO_PATTERN, $problems) as $number => $entry) {
            $parsed = $this->noPattern($number, $entry, $problems);
            if ($parsed instanceof NoPatternConclusionDto) {
                $noPattern[] = $parsed;
            }
        }

        return new DefectRecordDto($path, $deferred, $noPattern, $problems);
    }

    /**
     * The entries of one section, keyed by their 1-based number; none when the
     * section is absent or empty, and a problem when it is not a list.
     *
     * @param array<array-key, mixed> $decoded
     * @param list<string>            $problems
     *
     * @return array<int, mixed>
     */
    private function entries(array $decoded, string $section, array &$problems): array
    {
        $entries = $decoded[$section] ?? null;
        if (null === $entries) {
            return [];
        }

        if (!\is_array($entries) || !array_is_list($entries)) {
            $problems[] = $this->problem(\sprintf('%s must be a list of entries, each a mapping of fields', $section));

            return [];
        }

        $numbered = [];
        foreach ($entries as $index => $entry) {
            $numbered[$index + 1] = $entry;
        }

        return $numbered;
    }

    /** @param list<string> $problems */
    private function deferred(int $number, mixed $entry, array &$problems): ?DeferredDefectDto
    {
        $label  = \sprintf('%s #%d', self::DEFERRED, $number);
        $fields = $this->fields($label, $entry, $problems, ...self::DEFERRED_FIELDS);
        if (null === $fields) {
            return null;
        }

        $before     = \count($problems);
        $defect     = $this->requiredString($label, $fields, self::DEFECT, $problems);
        $found      = $this->requiredString($label, $fields, self::FOUND, $problems);
        $deferredBy = $this->requiredString($label, $fields, self::DEFERRED_BY, $problems);
        $class      = null;
        if (\array_key_exists(self::CLASS_FIELD, $fields)) {
            $class = $this->nonEmptyString($fields[self::CLASS_FIELD]);
            if (null === $class) {
                $problems[] = $this->problem(\sprintf('%s "%s" must be a non-empty string; leave it out when no class is apparent', $label, self::CLASS_FIELD));
            }
        }

        $this->unknownFields($label, $fields, $problems, ...self::DEFERRED_FIELDS);
        if (\count($problems) !== $before || null === $defect || null === $found || null === $deferredBy) {
            return null;
        }

        return new DeferredDefectDto($defect, $class, $found, $deferredBy);
    }

    /** @param list<string> $problems */
    private function noPattern(int $number, mixed $entry, array &$problems): ?NoPatternConclusionDto
    {
        $label  = \sprintf('%s #%d', self::NO_PATTERN, $number);
        $fields = $this->fields($label, $entry, $problems, ...self::NO_PATTERN_FIELDS);
        if (null === $fields) {
            return null;
        }

        $before     = \count($problems);
        $defect     = $this->requiredString($label, $fields, self::DEFECT, $problems);
        $found      = $this->requiredString($label, $fields, self::FOUND, $problems);
        $conclusion = $this->requiredString($label, $fields, self::CONCLUSION, $problems);
        $techniques = $this->techniques($fields[self::TECHNIQUES] ?? null);
        if (null === $techniques) {
            $problems[] = $this->problem(\sprintf('%s "%s" must list at least two different techniques, each a non-empty string', $label, self::TECHNIQUES));
        }

        $this->unknownFields($label, $fields, $problems, ...self::NO_PATTERN_FIELDS);
        if (\count($problems) !== $before || null === $defect || null === $found || null === $conclusion || null === $techniques) {
            return null;
        }

        return new NoPatternConclusionDto($defect, $found, $conclusion, $techniques);
    }

    /**
     * The entry's fields, or null with a problem when it is not a mapping.
     *
     * @param list<string> $problems
     *
     * @return array<array-key, mixed>|null
     */
    private function fields(string $label, mixed $entry, array &$problems, string ...$known): ?array
    {
        if (\is_array($entry) && ([] === $entry || !array_is_list($entry))) {
            return $entry;
        }

        $problems[] = $this->problem(\sprintf('%s must be a mapping of fields (%s)', $label, $this->fieldList(...$known)));

        return null;
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param list<string>            $problems
     */
    private function requiredString(string $label, array $fields, string $field, array &$problems): ?string
    {
        $value = $this->nonEmptyString($fields[$field] ?? null);
        if (null === $value) {
            $problems[] = $this->problem(\sprintf('%s "%s" must be a non-empty string', $label, $field));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param list<string>            $problems
     */
    private function unknownFields(string $label, array $fields, array &$problems, string ...$known): void
    {
        foreach (array_keys($fields) as $field) {
            if (!\in_array($field, $known, true)) {
                $problems[] = $this->problem(\sprintf('%s has an unknown field "%s"; its fields are %s', $label, $field, $this->fieldList(...$known)));
            }
        }
    }

    /** @return list<string>|null at least two different non-empty strings, or null */
    private function techniques(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }

        $techniques = [];
        foreach ($value as $technique) {
            $text = $this->nonEmptyString($technique);
            if (null === $text) {
                return null;
            }

            $techniques[] = $text;
        }

        return \count(array_unique($techniques)) >= 2 ? $techniques : null;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function fieldList(string ...$fields): string
    {
        $last = array_pop($fields);

        return implode(', ', $fields) . ' and ' . $last;
    }

    private function problem(string $message): string
    {
        return self::RELATIVE_PATH . ': ' . $message;
    }
}
