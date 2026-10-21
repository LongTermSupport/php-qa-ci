<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\BuildConstant;

/**
 * Nothing here is reported: each use reads only the constant's truth or its number, which is the
 * same for 0/1 and false/true, or the constant is another one.
 */
final class NotReportable
{
    public const ZTS = (bool)\PHP_ZTS;

    /** @return list<mixed> */
    public function casts(): array
    {
        return [
            (bool)\PHP_ZTS,
            (int)\PHP_DEBUG,
            (float)\PHP_ZTS,
            true === (bool)\PHP_ZTS,
            \boolval(\PHP_ZTS),
            \intval(\PHP_DEBUG),
            \floatval(\PHP_ZTS),
        ];
    }

    /** @return list<mixed> */
    public function truth(bool $other): array
    {
        return [
            !\PHP_ZTS,
            \PHP_ZTS ? 'zts' : '',
            \PHP_ZTS && $other,
            \PHP_DEBUG || $other,
            \PHP_ZTS and $other,
            \PHP_DEBUG or $other,
            \PHP_ZTS xor $other,
            empty(\PHP_DEBUG),
        ];
    }

    public function conditions(): string
    {
        if (\PHP_ZTS) {
            return 'zts';
        } elseif (\PHP_DEBUG) {
            return 'debug';
        }

        while (\PHP_DEBUG) {
            break;
        }

        do {
            break;
        } while (\PHP_ZTS);

        for (; \PHP_DEBUG;) {
            break;
        }

        return '';
    }

    /** @return list<bool|int> */
    public function comparedWithItsOwnValues(): array
    {
        return [
            1 == \PHP_ZTS,
            \PHP_ZTS != 0,
            \PHP_DEBUG == true,
            '1' == \PHP_ZTS,
            \PHP_ZTS > 0,
            \PHP_DEBUG <= 1,
            \PHP_ZTS < 1,
            \PHP_ZTS <=> 1,
            \PHP_ZTS === \PHP_DEBUG,
        ];
    }

    /**
     * Comparisons PHPStan already reports as always true or always false, which are so on both
     * types alike.
     *
     * @return list<bool>
     */
    public function comparedWithValuesItCannotHave(): array
    {
        return [
            \PHP_DEBUG <= 2,
            \PHP_ZTS > 2,
            \PHP_ZTS < 0.5,
            \PHP_ZTS >= 0.5,
            2 >= \PHP_DEBUG,
            2 < \PHP_ZTS,
            \PHP_ZTS === 2,
            null === \PHP_DEBUG,
        ];
    }

    public function caseOfASwitchOnItsOwnValue(): string
    {
        switch (1) {
            case \PHP_ZTS:
                return 'zts';

            default:
                return '';
        }
    }

    public function switchedOnTheOtherConstant(): string
    {
        switch (\PHP_ZTS) {
            case \PHP_DEBUG:
                return 'same';

            default:
                return 'other';
        }
    }

    /** @return list<float|int> */
    public function arithmetic(int $total): array
    {
        $total += \PHP_ZTS;

        return [
            \PHP_ZTS + 1,
            2 * \PHP_DEBUG,
            \PHP_ZTS | \PHP_DEBUG,
            \PHP_ZTS << 1,
            -\PHP_ZTS,
            $total,
        ];
    }

    /** @param list<string> $names */
    public function keys(array $names): string
    {
        $byFlag = [\PHP_ZTS => 'zts'];

        return $byFlag[1] ?? $names[\PHP_DEBUG] ?? '';
    }

    public function switchedOnItsOwnValues(): string
    {
        switch (\PHP_ZTS) {
            case 1:
                return 'zts';

            case false:
                return 'nts';

            default:
                return '';
        }
    }

    public function matchedOnACast(): string
    {
        return match (true) {
            (bool)\PHP_ZTS => 'zts',
            default        => '',
        };
    }

    /** @return list<bool> */
    public function otherConstants(): array
    {
        return [
            8 === \PHP_MAJOR_VERSION,
            1 === \Vendor\PHP_ZTS,
            'PHP_ZTS' === \PHP_OS_FAMILY,
        ];
    }

    /**
     * A value passed on unchanged by ??, a ternary or a match is judged where it ends up.
     *
     * @return list<bool>
     */
    public function passedOnToACast(bool|int|null $zts, bool $flag, int $choice): array
    {
        return [
            (bool)($zts ?? \PHP_ZTS),
            (bool)($flag ? \PHP_DEBUG : 0),
            !(\PHP_ZTS ?: $flag),
            (bool)match ($choice) {
                1       => \PHP_ZTS,
                default => false,
            },
            ($zts ?? \PHP_DEBUG) ? true : false,
        ];
    }

    public function discarded(): void
    {
        \PHP_ZTS;
    }
}
