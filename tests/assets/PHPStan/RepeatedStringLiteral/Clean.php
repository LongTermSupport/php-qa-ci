<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\RepeatedStringLiteral;

final class Clean
{
    private const string VERSION = '8.5.10';

    /** @return list<string> */
    public function versions(): array
    {
        return [self::VERSION, self::VERSION, self::VERSION];
    }
}
