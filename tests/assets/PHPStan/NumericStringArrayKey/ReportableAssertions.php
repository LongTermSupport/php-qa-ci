<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * Inline @var tags asserting string keys over keys PHPStan does not know:
 * on a variable the statement binds (a foreach value, a destructured
 * element), on a variable already held, and an iterator_to_array() over a
 * filesystem iterator held in a variable that may key by file name.
 */
final class ReportableAssertions
{
    /** @var array<string, int> */
    private array $byName = [];

    public function onAForeachValueOverJson(string $json): void
    {
        $lock = json_decode($json, true);
        if (!\is_array($lock) || !\is_array($lock['packages'] ?? null)) {
            return;
        }

        /** @var array<string, mixed> $package */
        foreach ($lock['packages'] as $package) {
            foreach ($package as $field => $_) {
                $this->takes($field);
            }
        }
    }

    /** @param array<array-key, mixed> $decoded */
    public function onAForeachValueWithAKey(array $decoded): void
    {
        /** @var array<string, int> $counts */
        foreach ($decoded as $name => $counts) {
            $this->takesMap($counts);
        }
    }

    /** @param list<array{mixed, mixed}> $rows */
    public function onAForeachValueDestructured(array $rows): void
    {
        /** @var array<string, int> $map */
        foreach ($rows as [$name, $map]) {
            $this->takesMap($map);
        }
    }

    public function onADestructuringAssignment(string $json): void
    {
        /** @var array<string, int> $map */
        [$name, $map] = json_decode($json, true);
        $this->takesMap($map);
    }

    public function onAKeyedDestructuringAssignment(string $json): void
    {
        /** @var array<string, int> $map */
        ['map' => $map] = json_decode($json, true);
        $this->takesMap($map);
    }

    public function onAHeldVariableAssignedJsonInOneBranch(string $json, bool $c): void
    {
        $map = $c ? $this->byName : json_decode($json, true);
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function onAHeldVariableReassignedLaterInTheLoop(string $json): void
    {
        $map = $this->byName;
        for ($i = 0; $i < 2; ++$i) {
            /** @var array<string, int> $map */
            $this->takesMap($map);
            $map = json_decode($json, true);
        }
    }

    public function onAHeldVariableWrittenByReference(string $subject): void
    {
        $map = $this->byName;
        preg_match('/x/', $subject, $map);
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    /** @param list<string> $names */
    public function onAHeldVariableWithAMixedElementWrite(array $names, mixed $key): void
    {
        $map = $this->byName;
        $map[$key] = 1;
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function onAGlobal(): void
    {
        /** @var array<string, int> $registry */
        global $registry;
        $this->takesMap($registry);
    }

    public function fileNamesFromAnIteratorHeld(string $dir): void
    {
        $iterator = new \FilesystemIterator($dir, \FilesystemIterator::KEY_AS_FILENAME);
        foreach (iterator_to_array($iterator) as $name => $file) {
            $this->takes($name);
        }
    }

    public function anIteratorHeldWhoseFlagsMayChange(string $dir): void
    {
        $iterator = new \FilesystemIterator($dir);
        $iterator->setFlags(\FilesystemIterator::KEY_AS_FILENAME);
        foreach (iterator_to_array($iterator) as $name => $file) {
            $this->takes($name);
        }
    }

    public function anIteratorHeldThatIsAlsoAParameter(\FilesystemIterator $iterator, bool $c): void
    {
        if ($c) {
            $iterator = new \FilesystemIterator('.');
        }

        foreach (iterator_to_array($iterator) as $name => $file) {
            $this->takes($name);
        }
    }

    public function onAHeldVariableOverwrittenInOneBranchOnly(string $json, bool $c): void
    {
        $map = json_decode($json, true);
        if ($c) {
            $map = $this->byName;
        }

        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    private function takes(string $name): void
    {
    }

    /** @param array<string, int> $map */
    private function takesMap(array $map): void
    {
    }
}
