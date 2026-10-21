<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * Inline @var tags that only restate keys PHPStan already typed string, on a
 * variable the statement binds or one already held, and iterator_to_array()
 * over a filesystem iterator held in a variable that keys by path name.
 */
final class NotReportableAssertions
{
    /** @var array<string, int> */
    private array $byName = [];

    /** @var array<string, array<string, int>> */
    private array $nested = [];

    /** @param array<string, int> $map */
    public function restatingAParameter(array $map): void
    {
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAVariableAssignedAKnownMap(): void
    {
        $map = $this->byName;
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAVariableAssignedInEitherBranch(bool $c): void
    {
        if ($c) {
            $map = $this->byName;
        } else {
            $map = ['fixed' => 1];
        }

        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAVariableReassignedOnlyAfterIt(string $json): void
    {
        $map = $this->byName;
        /** @var array<string, int> $map */
        $this->takesMap($map);
        $map = json_decode($json, true);
        echo \is_array($map) ? \count($map) : 0;
    }

    public function restatingAForeachValue(): void
    {
        /** @var array<string, int> $inner */
        foreach ($this->nested as $inner) {
            $this->takesMap($inner);
        }
    }

    public function restatingAnAssignmentThatMayBeFalse(): void
    {
        /** @var array<string, int> $map */
        $map = $this->mapOrFalse();
        $this->takesMap($map);
    }

    public function restatingAVariableGivenAStringKeyedElement(string $name): void
    {
        $map        = $this->byName;
        $map['new'] = 1;
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAnImportedVariable(): \Closure
    {
        $map = $this->byName;

        return function () use ($map): void {
            /** @var array<string, int> $map */
            $this->takesMap($map);
        };
    }

    /** @param array<string, int> $map */
    public function namingThisIsNoArray(array $map): void
    {
        /** @var array<string, int> $this->byName */
        $this->takesMap($map);
    }

    public function pathNamesFromAnIteratorHeld(string $dir): void
    {
        $iterator = new \FilesystemIterator($dir);
        foreach (iterator_to_array($iterator) as $path => $file) {
            $this->takes($path);
        }

        $skipping = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach (iterator_to_array($skipping) as $path => $file) {
            $this->takes($path);
        }

        foreach ($skipping as $path => $file) {
            $this->takes($path);
        }
    }

    public function restatingAfterAnUntypedWriteIsOverwritten(string $json): void
    {
        $map = json_decode($json, true);
        echo \is_array($map) ? \count($map) : 0;
        $map = $this->byName;
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterAnUntypedWriteIsOverwrittenInEveryBranch(string $json, int $c): void
    {
        $map = json_decode($json, true);
        echo \is_array($map) ? \count($map) : 0;
        if (0 === $c) {
            $map = $this->byName;
        } elseif (1 === $c) {
            throw new \RuntimeException('none');
        } else {
            $map = ['fixed' => 1];
        }

        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingInALoopAfterItsOwnOverwrite(string $json): void
    {
        for ($i = 0; $i < 2; ++$i) {
            $map = $this->byName;
            /** @var array<string, int> $map */
            $this->takesMap($map);
            $map = json_decode($json, true);
            echo \is_array($map) ? \count($map) : 0;
        }
    }

    public function pathNamesFromAnIteratorReassigned(string $dir): void
    {
        $iterator = new \FilesystemIterator($dir, \FilesystemIterator::KEY_AS_FILENAME);
        $iterator = new \FilesystemIterator($dir);
        foreach (iterator_to_array($iterator) as $path => $file) {
            $this->takes($path);
        }
    }

    /** @return array<string, int>|false */
    private function mapOrFalse(): array|false
    {
        return $this->byName;
    }

    private function takes(string $name): void
    {
    }

    /** @param array<string, int> $map */
    private function takesMap(array $map): void
    {
    }
}
