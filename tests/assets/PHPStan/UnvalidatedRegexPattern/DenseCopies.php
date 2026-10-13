<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnvalidatedRegexPattern;

/**
 * Forty steps, each building two locals from both of the step before, so 2^40 routes lead from the
 * pattern back to the first. Every write is owned and none is unseen: the pattern is owned, and
 * deciding so must take each variable once, not once per route through the others.
 */
final readonly class DenseCopies
{
    public function buildsEachStepFromBothBefore(string $value, string $subject): void
    {
        $a0  = '/' . preg_quote($value, '/') . '/';
        $b0  = $a0;
        $a1  = $a0 . $b0;
        $b1  = $b0 . $a0;
        $a2  = $a1 . $b1;
        $b2  = $b1 . $a1;
        $a3  = $a2 . $b2;
        $b3  = $b2 . $a2;
        $a4  = $a3 . $b3;
        $b4  = $b3 . $a3;
        $a5  = $a4 . $b4;
        $b5  = $b4 . $a4;
        $a6  = $a5 . $b5;
        $b6  = $b5 . $a5;
        $a7  = $a6 . $b6;
        $b7  = $b6 . $a6;
        $a8  = $a7 . $b7;
        $b8  = $b7 . $a7;
        $a9  = $a8 . $b8;
        $b9  = $b8 . $a8;
        $a10 = $a9 . $b9;
        $b10 = $b9 . $a9;
        $a11 = $a10 . $b10;
        $b11 = $b10 . $a10;
        $a12 = $a11 . $b11;
        $b12 = $b11 . $a11;
        $a13 = $a12 . $b12;
        $b13 = $b12 . $a12;
        $a14 = $a13 . $b13;
        $b14 = $b13 . $a13;
        $a15 = $a14 . $b14;
        $b15 = $b14 . $a14;
        $a16 = $a15 . $b15;
        $b16 = $b15 . $a15;
        $a17 = $a16 . $b16;
        $b17 = $b16 . $a16;
        $a18 = $a17 . $b17;
        $b18 = $b17 . $a17;
        $a19 = $a18 . $b18;
        $b19 = $b18 . $a18;
        $a20 = $a19 . $b19;
        $b20 = $b19 . $a19;
        $a21 = $a20 . $b20;
        $b21 = $b20 . $a20;
        $a22 = $a21 . $b21;
        $b22 = $b21 . $a21;
        $a23 = $a22 . $b22;
        $b23 = $b22 . $a22;
        $a24 = $a23 . $b23;
        $b24 = $b23 . $a23;
        $a25 = $a24 . $b24;
        $b25 = $b24 . $a24;
        $a26 = $a25 . $b25;
        $b26 = $b25 . $a25;
        $a27 = $a26 . $b26;
        $b27 = $b26 . $a26;
        $a28 = $a27 . $b27;
        $b28 = $b27 . $a27;
        $a29 = $a28 . $b28;
        $b29 = $b28 . $a28;
        $a30 = $a29 . $b29;
        $b30 = $b29 . $a29;
        $a31 = $a30 . $b30;
        $b31 = $b30 . $a30;
        $a32 = $a31 . $b31;
        $b32 = $b31 . $a31;
        $a33 = $a32 . $b32;
        $b33 = $b32 . $a32;
        $a34 = $a33 . $b33;
        $b34 = $b33 . $a33;
        $a35 = $a34 . $b34;
        $b35 = $b34 . $a34;
        $a36 = $a35 . $b35;
        $b36 = $b35 . $a35;
        $a37 = $a36 . $b36;
        $b37 = $b36 . $a36;
        $a38 = $a37 . $b37;
        $b38 = $b37 . $a37;
        $a39 = $a38 . $b38;
        $b39 = $b38 . $a38;
        $a40 = $a39 . $b39;
        preg_match($a40, $subject);
    }
}
