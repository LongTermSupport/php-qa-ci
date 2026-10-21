<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * Keys made where no element write is, that cannot be all digits or that no
 * code reads as a string.
 */
final class NotReportableSources
{
    /** @var array<string, int> */
    private array $named = [];

    /** @var array<array-key, int> */
    private array $honest = [];

    /** @param array<string, int> $map */
    public function mergedKeysThatCannotBeAllDigits(array $map, string $name): void
    {
        $this->named = array_merge($map, ['fixed' => 1, $name . '.php' => 2]);
    }

    public function replacedIntoAnHonestProperty(string $name): void
    {
        $this->honest = array_replace($this->honest, [$name => 1]);
    }

    /**
     * @param list<'a'|'b'>        $letters
     * @param list<class-string>   $classes
     * @param list<numeric-string> $numbers
     * @param list<int>            $ints
     */
    public function flippedValuesThatCannotBeAllDigitsOrAreTypedHonestly(array $letters, array $classes, array $numbers, array $ints): void
    {
        $this->named = array_flip($letters);
        $this->named = array_flip($classes);
        $this->honest = array_flip($numbers);
        $this->honest = array_flip($ints);
    }

    public function pathNamesAsKeys(string $dir): void
    {
        foreach (iterator_to_array(new \FilesystemIterator($dir)) as $path => $file) {
            $this->takes($path);
        }

        foreach (iterator_to_array(new \FilesystemIterator($dir, \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS)) as $path => $file) {
            $this->takes($path);
        }

        foreach (iterator_to_array(new \FilesystemIterator($dir, \FilesystemIterator::KEY_AS_FILENAME), false) as $file) {
            $this->takes((string) $file);
        }
    }

    /**
     * @param list<array{name: string, size: int}> $rows
     *
     * @return list<int>
     */
    public function columnWithoutAnIndex(array $rows): array
    {
        return array_column($rows, 'size');
    }

    public function assertedHonestlyOrOverKnownKeys(string $json): void
    {
        /** @var array<array-key, string> $decoded */
        $decoded = json_decode($json, true);
        /** @var array<class-string, string> $classes */
        $classes = json_decode($json, true);
        /** @var array<string, int> $copy */
        $copy = $this->named;
        $this->honest = [...$this->honest, \count($decoded) + \count($classes) + \count($copy)];
    }

    /** @param list<string> $names */
    public function keysPassedToNoCodeThatReadsThem(array $names): bool
    {
        $count = [];
        foreach ($names as $name) {
            $count[$name] = ($count[$name] ?? 0) + 1;
        }

        array_walk($count, static function (int &$seen): void {
            ++$seen;
        });

        return array_any($count, static fn (int $seen): bool => $seen > 1)
            || array_all($count, static fn (int $seen): bool => $seen > 0)
            || null !== array_find($count, static fn (int $seen): bool => $seen > 2);
    }

    /** @param list<string> $names */
    public function keysOnlyCounted(array $names): int
    {
        $set = [];
        foreach ($names as $name) {
            $set[$name] = true;
        }

        return \count(array_keys($set));
    }

    /** @param list<string> $names */
    public function keysOnlyMadeIntoStrings(array $names): string
    {
        $set = [];
        foreach ($names as $name) {
            $set[$name] = true;
        }

        $out = '';
        foreach ($set as $key => $seen) {
            $out .= "{$key}\n" . $key . '/' . (string) $key;
        }

        foreach (array_flip($names) as $flipped => $index) {
            $out .= "{$flipped}";
        }

        return $out;
    }

    /** @param list<string> $names */
    public function keysPreservedOrderReversed(array $names): void
    {
        $set = [];
        foreach ($names as $name) {
            $set[$name] = 1;
        }

        $this->honest = array_reverse($set, true);
    }

    /** @return list<array<array-key, int>> */
    public function handedOnInsideALiteralHonestAboutItsKeys(string $name): array
    {
        $counts        = [];
        $counts[$name] = 1;

        return [$counts];
    }

    /** @return \Generator<int, int> */
    public function valuesYielded(string $name): \Generator
    {
        $counts        = [];
        $counts[$name] = 1;

        yield from array_values($counts);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    public function keysOnlyConvertedToStrings(array $names): array
    {
        $set = [];
        foreach ($names as $name) {
            $set[$name] = true;
        }

        return array_map(strval(...), array_keys($set));
    }

    public function assertedHonestlyOverAVariableAlreadyHeld(string $json): void
    {
        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return;
        }

        /** @var array<array-key, int> $decoded */
        $this->honest = [...$this->honest, ...array_values($decoded)];
    }

    private function takes(string $name): void
    {
    }
}
