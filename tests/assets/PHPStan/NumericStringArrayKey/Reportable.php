<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

final class Reportable
{
    /** @var array<string, string> */
    private array $reasons = [];

    /** @var array<string, array<string, int>> */
    private array $nested = [];

    /** @var array<string, int> */
    private static array $counts = [];

    /** @param array<string, string> $declared */
    public function __construct(private array $declared)
    {
    }

    public function propertyWrite(string $path, string $reason): void
    {
        $this->reasons[$path] = $reason;
    }

    public function nestedPropertyWrite(string $file, string $rule): void
    {
        $this->nested[$file][$rule] = 1;
    }

    public function staticPropertyWrite(string $name): void
    {
        self::$counts[$name] = (self::$counts[$name] ?? 0) + 1;
    }

    public function compoundWrites(string $name): void
    {
        $this->reasons[$name] .= 'x';
        $this->reasons[$name] ??= 'y';
        ++self::$counts[$name];
    }

    /** @param array<string, string> $map */
    public function copiedThenPassed(string $path, array $map): void
    {
        $copy        = $this->declared;
        $copy[$path] = 'why';
        $this->accept($copy);
        $map[$path] = 'why';
        $this->accept(map: $map);
    }

    /** @return array<string, int> */
    public function freshReturned(string ...$paths): array
    {
        $counts = [];
        foreach ($paths as $path) {
            $counts[$path] = ($counts[$path] ?? 0) + 1;
        }

        return $counts;
    }

    public function freshPassedToConstructor(string $path): Holder
    {
        $byPath        = [];
        $byPath[$path] = 1;

        return new Holder(counts: $byPath);
    }

    public function freshAssignedToProperty(string $path): void
    {
        $reasons        = [];
        $reasons[$path] = 'why';
        $this->reasons  = $reasons;
    }

    public function keysReadByForeach(string $path): void
    {
        $seen        = [];
        $seen[$path] = true;
        foreach ($seen as $key => $unused) {
            $this->takes($key);
        }
    }

    /** @return list<string> */
    public function keysReadByFunction(string $path): array
    {
        $seen        = [];
        $seen[$path] = true;

        return array_keys($seen);
    }

    public function interpolatedDigitsOnly(int $year, string $suffix): void
    {
        $byYear                     = [];
        $byYear["{$year}{$suffix}"] = 'x';
        $byYear[$suffix . '1']      = 'y';
        $this->accept($byYear);
    }

    /** @param array<string, list<string>> $groups */
    public function nestedVariableWrite(array $groups, string $group, string $member): void
    {
        $groups[$group][] = $member;
        $this->acceptGroups($groups);
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<string, int>
     */
    public function keyRewrittenInTheLoop(array $decoded): array
    {
        $trimmed = [];
        foreach ($decoded as $key => $value) {
            $key           = trim((string) $key);
            $trimmed[$key] = 1;
        }

        return $trimmed;
    }

    /**
     * @param iterable<string, int> $pairs
     *
     * @return array<string, int>
     */
    public function keyOfATraversable(iterable $pairs): array
    {
        $copied = [];
        foreach ($pairs as $key => $value) {
            $copied[$key] = $value;
        }

        return $copied;
    }

    /** @return array<string, string> */
    public function copiedBeforeReturned(string $path): array
    {
        $built        = [];
        $built[$path] = 'x';
        $result       = $built;

        return $result;
    }

    /** @param array<string, string> $map */
    private function accept(array $map): void
    {
    }

    /** @param array<string, list<string>> $groups */
    private function acceptGroups(array $groups): void
    {
    }

    private function takes(string $key): void
    {
    }
}

final readonly class Holder
{
    /** @param array<string, int> $counts */
    public function __construct(public array $counts)
    {
    }
}
