<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\UnvalidatedRegexPattern;

/**
 * Forty locals, each rebuilt in a loop from three others, so the copies form one dense cycle.
 * Every write is owned and none is unseen: the pattern is owned, and deciding so must take each
 * variable once, not once per route through the others.
 */
final readonly class DenseCopies
{
    public function rebuildsEachFromOthers(string $value, string $subject, int $rounds): void
    {
        $v0  = '/' . preg_quote($value, '/') . '/';
        $v1  = '/a/';
        $v2  = '/a/';
        $v3  = '/a/';
        $v4  = '/a/';
        $v5  = '/a/';
        $v6  = '/a/';
        $v7  = '/a/';
        $v8  = '/a/';
        $v9  = '/a/';
        $v10 = '/a/';
        $v11 = '/a/';
        $v12 = '/a/';
        $v13 = '/a/';
        $v14 = '/a/';
        $v15 = '/a/';
        $v16 = '/a/';
        $v17 = '/a/';
        $v18 = '/a/';
        $v19 = '/a/';
        $v20 = '/a/';
        $v21 = '/a/';
        $v22 = '/a/';
        $v23 = '/a/';
        $v24 = '/a/';
        $v25 = '/a/';
        $v26 = '/a/';
        $v27 = '/a/';
        $v28 = '/a/';
        $v29 = '/a/';
        $v30 = '/a/';
        $v31 = '/a/';
        $v32 = '/a/';
        $v33 = '/a/';
        $v34 = '/a/';
        $v35 = '/a/';
        $v36 = '/a/';
        $v37 = '/a/';
        $v38 = '/a/';
        $v39 = '/a/';
        for ($round = 0; $round < $rounds; ++$round) {
            $v0  = $v1 . $v5 . $v11;
            $v1  = $v2 . $v6 . $v12;
            $v2  = $v3 . $v7 . $v13;
            $v3  = $v4 . $v8 . $v14;
            $v4  = $v5 . $v9 . $v15;
            $v5  = $v6 . $v10 . $v16;
            $v6  = $v7 . $v11 . $v17;
            $v7  = $v8 . $v12 . $v18;
            $v8  = $v9 . $v13 . $v19;
            $v9  = $v10 . $v14 . $v20;
            $v10 = $v11 . $v15 . $v21;
            $v11 = $v12 . $v16 . $v22;
            $v12 = $v13 . $v17 . $v23;
            $v13 = $v14 . $v18 . $v24;
            $v14 = $v15 . $v19 . $v25;
            $v15 = $v16 . $v20 . $v26;
            $v16 = $v17 . $v21 . $v27;
            $v17 = $v18 . $v22 . $v28;
            $v18 = $v19 . $v23 . $v29;
            $v19 = $v20 . $v24 . $v30;
            $v20 = $v21 . $v25 . $v31;
            $v21 = $v22 . $v26 . $v32;
            $v22 = $v23 . $v27 . $v33;
            $v23 = $v24 . $v28 . $v34;
            $v24 = $v25 . $v29 . $v35;
            $v25 = $v26 . $v30 . $v36;
            $v26 = $v27 . $v31 . $v37;
            $v27 = $v28 . $v32 . $v38;
            $v28 = $v29 . $v33 . $v39;
            $v29 = $v30 . $v34 . $v0;
            $v30 = $v31 . $v35 . $v1;
            $v31 = $v32 . $v36 . $v2;
            $v32 = $v33 . $v37 . $v3;
            $v33 = $v34 . $v38 . $v4;
            $v34 = $v35 . $v39 . $v5;
            $v35 = $v36 . $v0 . $v6;
            $v36 = $v37 . $v1 . $v7;
            $v37 = $v38 . $v2 . $v8;
            $v38 = $v39 . $v3 . $v9;
            $v39 = $v0 . $v4 . $v10;
        }

        preg_match($v0, $subject);
    }
}
