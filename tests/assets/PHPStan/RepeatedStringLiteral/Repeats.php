<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\RepeatedStringLiteral;

use PHPUnit\Framework\Attributes\Group;

#[Group('fixture')]
#[Group('fixture')]
#[Group('fixture')]
final class Repeats
{
    /** @return list<string> */
    public function versions(): array
    {
        return ['8.5.10', '8.5.10', '8.5.10'];
    }

    /** @return array<string, string> */
    public function keysAreNotCounted(): array
    {
        $row = ['name' => 'a', 'name2' => 'b'];
        $x   = $row['name'];
        $y   = $row['name'];

        return ['name' => $x . $y, 'other' => 'ab'];
    }

    public function shortAndTwiceAreNotCounted(): string
    {
        return ', ' . ', ' . ', ' . 'twice' . 'twice';
    }

    public function nested(): object
    {
        return new class {
            public function three(): string
            {
                return 'inner' . 'inner' . 'inner';
            }
        };
    }
}
