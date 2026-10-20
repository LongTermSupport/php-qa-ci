<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\NumericStringArrayKey;

enum Section: string
{
    case Fixed = 'fixed';
    case Added = 'added';
}

final class NotReportable
{
    /** @var array<array-key, string> */
    private array $honest = [];

    /** @var array<string, int> */
    private array $named = [];

    /** @var array<int, string> */
    private array $byNumber = [];

    /** @param array<string, string> $declared */
    public function __construct(private array $declared)
    {
    }

    public function constantAndEnumKeys(Section $section): void
    {
        $this->named['fixed']          = 1;
        $this->named[$section->value]  = 2;
        $this->named[Section::Added->value] = 3;
    }

    public function classStringKey(object $object): void
    {
        $this->named[$object::class] = 1;
    }

    /** @param numeric-string $number */
    public function numericStringKeyIsAlreadyTypedIntOrString(string $number): void
    {
        $map           = [];
        $map[$number]  = 'x';
        $this->honest  = $map;
    }

    public function keyHoldingANonDigit(string $directory, string $name): void
    {
        $this->named[$directory . '/']        = 1;
        $this->named["{$directory}/{$name}"] = 2;
        $this->named['v' . $name]            = 3;
    }

    public function intKey(int $number, string $name): void
    {
        $this->byNumber[$number] = $name;
    }

    public function honestlyTypedKeys(string $path): void
    {
        $this->honest[$path] = 'why';
        foreach ($this->honest as $key => $reason) {
            $this->takes((string) $key . $reason);
        }
    }

    public function freshArrayWhoseKeysAreNeverRead(string $path): bool
    {
        $seen        = [];
        $seen[$path] = true;

        return isset($seen['x']);
    }

    /** @return array<array-key, string> */
    public function freshArrayReturnedAsArrayKey(string $path): array
    {
        $map        = [];
        $map[$path] = 'x';
        $this->acceptAnyKey($map);

        return $map;
    }

    /** @return array<string, string> */
    public function closureReturnsItsOwnArray(string $path): array
    {
        $build = static function () use ($path): array {
            $map        = [];
            $map[$path] = 'x';

            return $map;
        };
        $build();
        $map = ['a' => 'b'];

        return $map;
    }

    public function valuesNotKeys(string $path): void
    {
        $list   = [];
        $list[] = $path;
        foreach ($list as $index => $value) {
            $this->takes($value . $index);
        }

        $copy = $this->declared;
        $this->acceptAnyKey($copy);
    }

    /** @param array<array-key, string> $map */
    private function acceptAnyKey(array $map): void
    {
    }

    private function takes(string $value): void
    {
    }
}
