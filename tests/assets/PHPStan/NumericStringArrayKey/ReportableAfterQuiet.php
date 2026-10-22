<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

/**
 * A key PHP made, which the rule leaves alone, ahead of an inline @var that it
 * reports: a finding that comes after a quiet record in the same file is still
 * reported, and a variable in a body that may write any variable unseen is held
 * to its inline @var.
 */
final class ReportableAfterQuiet
{
    /** @var array<string, int> */
    private array $byName = [];

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, int>
     */
    public function keysPhpMade(array $source): array
    {
        $copy = [];
        foreach ($source as $key => $_) {
            $copy[$key] = 1;
        }

        return $copy;
    }

    public function aHeldVariableInABodyThatMayWriteAnyVariable(string $json, string $name): void
    {
        $map = $this->byName;
        ${$name} = json_decode($json, true);
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    public function aHeldVariableThatIsAlsoAReference(): void
    {
        $map   = $this->byName;
        $alias = &$map;
        $alias = [];
        /** @var array<string, int> $map */
        $this->takesMap($map);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, int>
     */
    public function aKeyThatIsNotTheLoopsKeyOrIsWrittenAfterTheLoop(array $source, string $other): array
    {
        $copy = [];
        foreach ($source as $key => $_) {
            $copy[$other] = 1;
        }

        $copy[$key] = 2;

        return $copy;
    }

    /** @param array<string, int> $map */
    private function takesMap(array $map): void
    {
    }
}
