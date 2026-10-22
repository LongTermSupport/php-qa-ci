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

    public function restatingAfterATryAndEveryCatchOverwrite(string $json): void
    {
        $map = json_decode($json, true);
        echo \is_array($map) ? \count($map) : 0;
        try {
            $map = $this->byName;
        } catch (\RuntimeException) {
            $map = ['fixed' => 1];
        } catch (\LogicException $e) {
            throw $e;
        }

        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterAFinallyOverwrites(string $json): void
    {
        $map = json_decode($json, true);
        try {
            echo \is_array($map) ? \count($map) : 0;
        } finally {
            $map = $this->byName;
        }

        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterEveryCaseOfASwitchOverwrites(string $json, int $c): void
    {
        $map = json_decode($json, true);
        echo \is_array($map) ? \count($map) : 0;
        switch ($c) {
            case 0:
            case 1:
                foreach ($this->byName as $_) {
                    break;
                }

                $map = $this->byName;
                break;

            case 2:
                throw new \RuntimeException('none');

            default:
                $map = ['fixed' => 1];
                break;
        }

        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterABranchLeavingThroughATryAndOneOverwriting(string $json, bool $c): void
    {
        $map = json_decode($json, true);
        echo \is_array($map) ? \count($map) : 0;
        if ($c) {
            try {
                return;
            } finally {
                echo 'left';
            }
        } else {
            $map = $this->byName;
        }

        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterSortsThatKeepKeys(): void
    {
        $map = $this->byName;
        ksort($map);
        krsort($map);
        asort($map);
        arsort($map);
        natsort($map);
        natcasesort($map);
        uasort($map, static fn (int $a, int $b): int => $a <=> $b);
        uksort($map, static fn (string $a, string $b): int => strcmp($a, $b));
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterAReferenceParameterDeclaredStringKeyed(): void
    {
        $map = $this->byName;
        $this->touch($map);
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAVariableOnlyAReferenceParameterWrites(): void
    {
        $this->fill($filled);
        /** @var array<string, int> $filled */
        $this->takesMap($filled);
    }

    public function restatingAfterAnUnknownReferenceWriteIsOverwritten(): void
    {
        $map = $this->byName;
        $this->touchAnything($map);
        $map = $this->byName;
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterACaseContinuesOrBreaksOutOfTheLoop(string $json, int $c): void
    {
        foreach ([1, 2] as $_) {
            $map = json_decode($json, true);
            echo \is_array($map) ? \count($map) : 0;
            switch ($c) {
                case 0:
                    continue 2;

                case 1:
                    if ('' === $json) {
                        break 2;
                    }

                    $map = ['fixed' => 1];
                    break;

                case 2:
                    foreach ($this->byName as $_) {
                        continue 3;
                    }

                    $map = $this->byName;
                    break;

                default:
                    $map = $this->byName;
            }

            /** @var array<string, int> $map */
            $this->takesMap($map);
        }
    }

    public function restatingAfterAnArrowFunctionSortsItsOwnCopy(): void
    {
        $map  = $this->byName;
        $sort = fn () => sort($map);
        $sort();
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterAClosureSortsItsOwnCopy(): void
    {
        $map  = $this->byName;
        $sort = function () use ($map): void {
            sort($map);
        };
        $sort();
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterTwoWholeWritesOnlyTheLastReaches(string $json): void
    {
        $map = json_decode($json, true);
        $map = $this->byName;
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function restatingAfterThreeWholeWritesOnlyTheLastReaches(): void
    {
        $map = $this->byName;
        $map = $this->byName;
        $map = $this->byName;
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    /** @param array<string, int> $map */
    private function touch(array &$map): void
    {
        $map['fixed'] = 1;
    }

    private function touchAnything(mixed &$map): void
    {
        $map = 1;
    }

    /** @param-out array<string, int> $map */
    private function fill(mixed &$map): void
    {
        $map = $this->byName;
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
