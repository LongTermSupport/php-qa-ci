<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\RepeatedStringLiteral;

final class DigitLiteral
{
    /** @return list<string> */
    public function years(): array
    {
        return ['2024', '2024', '2024'];
    }
}
