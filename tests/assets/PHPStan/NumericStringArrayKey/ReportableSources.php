<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * Keys made where no element write is: array_merge() and array_replace()
 * given a literal, the functions that build keys from values, functions that
 * renumber int keys, and an inline @var asserting string keys over data whose
 * keys PHPStan does not know.
 */
final class ReportableSources
{
    /** @var array<string, object> */
    private array $tools = [];

    /** @var array<string, int> */
    private array $byName = [];

    public function mergedIntoProperty(string $name, object $tool): void
    {
        $this->tools = array_merge($this->tools, [$name => $tool]);
    }

    /**
     * @param array<string, int> $map
     *
     * @return array<string, int>
     */
    public function mergedThenReturned(array $map, string $name): array
    {
        $next = array_merge($map, [$name => 1]);

        return $next;
    }

    /**
     * @param array<string, int> $map
     *
     * @return array<string, int>
     */
    public function mergedFromASpread(array $map, string $name): array
    {
        return array_merge($map, ...[[$name => 1]]);
    }

    public function replacedIntoProperty(string $name, object $tool): void
    {
        $this->tools = array_replace($this->tools, [$name => $tool]);
    }

    /** @return array<string, int> */
    public function replacedThenReturned(string $name): array
    {
        $next = array_replace(['fixed' => 0], [$name => 1]);

        return $next;
    }

    public function fileNamesAsKeys(string $dir): void
    {
        foreach (iterator_to_array(new \FilesystemIterator($dir, \FilesystemIterator::KEY_AS_FILENAME)) as $name => $file) {
            $this->takes($name);
        }
    }

    /** @param list<string> $names */
    public function flippedThenRead(array $names): void
    {
        $set = array_flip($names);
        foreach ($set as $name => $index) {
            $this->takes($name);
        }
    }

    /** @param list<string> $names */
    public function flippedIntoProperty(array $names): void
    {
        $this->byName = array_flip($names);
    }

    /** @param list<string> $names */
    public function filledKeysRead(array $names): void
    {
        $set = array_fill_keys($names, true);
        foreach (array_keys($set) as $name) {
            $this->takes($name);
        }
    }

    /**
     * @param list<string> $names
     * @param list<int>    $sizes
     *
     * @return array<string, int>
     */
    public function combined(array $names, array $sizes): array
    {
        return array_combine($names, $sizes);
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, int>
     */
    public function countedValues(array $names): array
    {
        return array_count_values($names);
    }

    /**
     * @param list<array{name: string, size: int}> $rows
     *
     * @return array<string, int>
     */
    public function columnIndexedByName(array $rows): array
    {
        return array_column($rows, 'size', 'name');
    }

    /** @param list<string> $names */
    public function keysRenumbered(array $names): void
    {
        $set = [];
        foreach ($names as $name) {
            $set[$name] = true;
        }

        $this->byName = array_map(static fn (bool $seen): int => (int) $seen, array_reverse($set));
    }

    public function keysPreservedIntoProperty(string $name): void
    {
        $counts        = [];
        $counts[$name] = 1;
        $this->byName  = array_map(static fn (int $count): int => $count + 1, $counts);
    }

    /** @return array<string, int> */
    public function keysPreservedThenReturned(string $name): array
    {
        $counts        = [];
        $counts[$name] = 1;
        $kept          = array_filter($counts);

        return $kept;
    }

    public function assertedOverDecodedJson(string $json): void
    {
        /** @var array<string, string> $psr4 */
        $psr4 = json_decode($json, true);
        foreach ($psr4 as $root => $path) {
            $this->takes($root . $path);
        }
    }

    /** @param list<string> $names */
    public function keyReadByATwoParameterCallback(array $names): bool
    {
        $count = [];
        foreach ($names as $name) {
            $count[$name] = 1;
        }

        return array_any($count, fn (int $seen, string $name): bool => $this->isTaken($name));
    }

    /** @param list<string> $names */
    public function keyInterpolatedThenUsedAfterTheLoop(array $names): void
    {
        $set = [];
        foreach ($names as $name) {
            $set[$name] = true;
        }

        $last = '';
        foreach ($set as $key => $seen) {
            echo "{$key}\n";
        }

        $this->takes($key ?? $last);
    }

    private function takes(string $name): void
    {
    }

    private function isTaken(string $name): bool
    {
        return '' === $name;
    }
}
