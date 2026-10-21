<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\BuildConstant;

/**
 * Every use here gives a different result, or a different type, on PHP 8.3 (where PHP_ZTS and
 * PHP_DEBUG are 0 or 1) and PHP 8.4 on (where they are false or true). One report per line.
 */
final class Reportable
{
    /** Not reported, and first, so a use that is not reported does not end the search. */
    public const NTS = !\PHP_ZTS;

    public const ZTS = \PHP_ZTS;

    /** @return list<bool> */
    public function strictComparisons(): array
    {
        return [
            1 === \PHP_ZTS,
            \PHP_ZTS === 1,
            0 !== \PHP_DEBUG,
            true === PHP_ZTS,
        ];
    }

    public function matchSubject(): string
    {
        return match (\PHP_DEBUG) {
            1       => 'debug',
            default => '',
        };
    }

    public function matchArm(): string
    {
        return match (true) {
            \PHP_ZTS => 'zts',
            default  => '',
        };
    }

    public function storedInAVariable(): bool
    {
        $zts = \PHP_ZTS;

        return 1 === $zts;
    }

    public function strictInArray(): bool
    {
        return \in_array(\PHP_ZTS, [1], true);
    }

    public function typeTested(): bool
    {
        return \is_int(\PHP_ZTS);
    }

    public function castToString(): string
    {
        return (string)\PHP_DEBUG;
    }

    public function concatenated(): string
    {
        return 'zts-' . \PHP_ZTS;
    }

    public function concatenatedOnto(string $key): string
    {
        $key .= \PHP_DEBUG;

        return $key;
    }

    public function returned(): bool|int
    {
        return \PHP_ZTS;
    }

    public function echoed(): void
    {
        echo \PHP_DEBUG;
    }

    public function encoded(): false|string
    {
        return \json_encode(\PHP_ZTS);
    }

    /** @return array<string, bool|int> */
    public function arrayValue(): array
    {
        return ['zts' => \PHP_ZTS];
    }

    public function castToArray(): array
    {
        return (array)\PHP_ZTS;
    }

    public function comparedWithAnotherInteger(): bool
    {
        return \PHP_DEBUG < 2;
    }

    public function looselyComparedWithAWord(): bool
    {
        return 'yes' == \PHP_ZTS;
    }

    public function looselyComparedWithAVariable(int $level): bool
    {
        return \PHP_DEBUG == $level;
    }

    public function coalesced(): bool|int
    {
        return \PHP_ZTS ?? 0;
    }

    public function shortTernary(): bool|int
    {
        return \PHP_ZTS ?: 2;
    }

    public function ternaryBranch(bool $threadSafe): bool|int
    {
        return $threadSafe ? \PHP_ZTS : 0;
    }

    public function bitwiseNot(): int
    {
        return ~\PHP_ZTS;
    }

    public function switchedOnAWord(): string
    {
        switch (\PHP_ZTS) {
            case 'yes':
                return 'zts';

            default:
                return '';
        }
    }

    public function caseOfASwitchOnAWord(): string
    {
        switch ('yes') {
            case \PHP_DEBUG:
                return 'debug';

            default:
                return '';
        }
    }

    public function parameterDefault(bool|int $zts = \PHP_ZTS): bool|int
    {
        return $zts;
    }

    public function argument(): string
    {
        return \sprintf('%s', \PHP_DEBUG);
    }

    public function dynamicCall(callable $call): mixed
    {
        return $call(\PHP_ZTS);
    }

    public function constructorOfAClassNamedLikeACast(): object
    {
        return new \BoolVal(\PHP_DEBUG);
    }

    /** @return list<bool|int> */
    public function comparedWithOtherValues(): array
    {
        return [
            \PHP_DEBUG >= 2,
            \PHP_ZTS <= 0.5,
            \PHP_ZTS > 0.5,
            2 > \PHP_DEBUG,
            2 <= \PHP_ZTS,
            '' == \PHP_ZTS,
            \PHP_ZTS <=> 2,
        ];
    }

    /**
     * A value passed on unchanged by ??, a ternary or a match is judged where it ends up.
     *
     * @return list<mixed>
     */
    public function passedOnToAComparison(bool|int|null $zts, bool $flag, int $choice): array
    {
        return [
            1 === ($zts ?? \PHP_ZTS),
            'zts-' . ($flag ? \PHP_DEBUG : 0),
            match ($choice) {
                1       => \PHP_ZTS,
                default => false,
            },
            match (\PHP_DEBUG ?? 0) {
                1       => 'debug',
                default => '',
            },
            match (true) {
                $flag ?: \PHP_ZTS => 'zts',
                default           => '',
            },
        ];
    }
}
