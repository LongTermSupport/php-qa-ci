<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\InfectionConfig;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Safe\file_get_contents;
use function Safe\json_decode;
use function Safe\preg_match;

/**
 * Infection decides whether a mutant is ignored by matching each
 * global-ignoreSourceCodeByRegex entry as ^-\s*<entry>$ (flags m, u) against the
 * mutant's diff, so an entry sees one removed line plus the lines after it.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class ShippedInfectionConfigAttributeIgnoreTest extends TestCase
{
    private const array CONFIGS = ['configDefaults/generic/infection.json'];

    private const string SINGLE_LINE = <<<'DIFF'
        @@ @@
         {
             public function __construct(
        -        #[Route(a: 5 + 1, b: 2)]
        +        #[Route(a: 5 + 0, b: 2)]
                 public int $x,
             ) {
        DIFF;

    private const string CONTINUATION_LINE = <<<'DIFF'
        @@ @@
         {
             public function __construct(
                 #[Route(
        -            a: 7 + 1,
        +            a: 7 + 0,
                     b: 2 ** 3,
                 )]
                 public int $y,
        DIFF;

    private const string LAST_CONTINUATION_LINE = <<<'DIFF'
        @@ @@
                 #[Route(
                     a: 7 + 1,
        -            b: 2 ** 3,
        +            b: 2 ** 4,
                 )]
                 public int $y,
             ) {
        DIFF;

    private const string ORDINARY_CODE = <<<'DIFF'
        @@ @@
             public function size(): int
             {
        -        return 5 + 1;
        +        return 5 + 0;
             }
        DIFF;

    private const string ORDINARY_CALL_ARGUMENT = <<<'DIFF'
        @@ @@
             {
                 return $this->build(
        -            size: 5 + 1,
        +            size: 5 + 0,
                     name: 'x',
                 );
        DIFF;

    #[Test]
    #[DataProvider('attributeDiffs')]
    public function theShippedConfigIgnoresAMutantInsideAnAttributeAndNothingElse(string $config, string $diff, bool $ignored): void
    {
        self::assertSame($ignored, $this->ignoredByAnyEntry($config, $diff));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function attributeDiffs(): iterable
    {
        foreach (self::CONFIGS as $config) {
            yield $config . ' single-line attribute' => [$config, self::SINGLE_LINE, true];

            yield $config . ' attribute continuation line' => [$config, self::CONTINUATION_LINE, true];

            yield $config . ' last attribute continuation line' => [$config, self::LAST_CONTINUATION_LINE, true];

            yield $config . ' ordinary code' => [$config, self::ORDINARY_CODE, false];

            yield $config . ' ordinary call argument' => [$config, self::ORDINARY_CALL_ARGUMENT, false];
        }
    }

    private function ignoredByAnyEntry(string $config, string $diff): bool
    {
        $decoded = json_decode(file_get_contents(\dirname(__DIR__, 3) . '/' . $config), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['mutators']);
        $entries = $decoded['mutators']['global-ignoreSourceCodeByRegex'];
        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            self::assertIsString($entry);
            if ($this->patternMatches($this->infectionPattern($entry), $diff)) {
                return true;
            }
        }

        return false;
    }

    private function patternMatches(string $pattern, string $diff): bool
    {
        set_error_handler(static function (int $level, string $message) use ($pattern): never {
            throw new InvalidArgumentException(\sprintf('ignore pattern "%s" is not a valid regular expression (%s)', $pattern, $message), $level);
        });

        try {
            return 1 === preg_match($pattern, $diff);
        } finally {
            restore_error_handler();
        }
    }

    private function infectionPattern(string $entry): string
    {
        $delimiter = '/';
        foreach (['#', '%', ':', ';', '=', '?', '@', '^', '~'] as $candidate) {
            if (!str_contains($entry, $candidate)) {
                $delimiter = $candidate;

                break;
            }
        }

        return $delimiter . '^-\s*' . $entry . '$' . $delimiter . 'mu';
    }
}
