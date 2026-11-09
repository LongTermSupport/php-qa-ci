<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Whether a project's constraint leaves out a release newer than its lock.
 * Composer's own semver library reads the constraint, so `^0.2` excludes
 * 0.3.0 exactly as `composer update` would. Only a stable release counts as
 * newer: a prerelease or a branch head is not something to be pinned behind.
 *
 * @internal
 */
final readonly class FirstPartyConstraintJudge
{
    /** An exact version as Composer reads one: `0.2.3`, `=0.2.3`, `v0.2.3`, `1.2`, `1.0.0-RC1`. */
    private const string EXACT = '/^=?\s*v?\d+(?:\.\d+){0,3}(?:-[0-9A-Za-z.]+)?$/';

    /** The only stability a release to be told about may have. */
    private const string STABLE = 'stable';

    /**
     * @param string $constraint the project's composer.json constraint
     * @param string $locked     the locked version as Composer prints it (`0.2.1`, `v0.2.1`, `dev-main abc1234`)
     * @param string $latest     the newest release as Composer prints it
     *
     * @throws InvalidArgumentException|UnexpectedValueException when the constraint or a version is not one Composer's parser reads
     */
    public static function judge(string $constraint, string $locked, string $latest): FirstPartyVerdictEnum
    {
        if (Tracking::followsBranch($constraint, $locked)) {
            return FirstPartyVerdictEnum::FollowsBranch;
        }

        $newest = self::version($latest);
        $plain  = Tracking::ref($constraint);
        $parser = new VersionParser();
        if (self::STABLE !== VersionParser::parseStability($newest) || Tracking::isBranchVersion($newest)) {
            return FirstPartyVerdictEnum::Current;
        }

        if (Semver::satisfies($newest, $plain) || !Comparator::greaterThan($parser->normalize($newest), $parser->normalize(self::version($locked)))) {
            return FirstPartyVerdictEnum::Current;
        }

        return 1 === \Safe\preg_match(self::EXACT, $plain) ? FirstPartyVerdictEnum::Pinned : FirstPartyVerdictEnum::Behind;
    }

    /** The constraint that admits the newest release's line alone: `^0.3` for 0.3.0, `^2.4` for 2.4.1. */
    public static function newestLine(string $latest): string
    {
        $parts = explode('.', ltrim(self::version($latest), 'vV'));

        return '^' . $parts[0] . '.' . ($parts[1] ?? '0');
    }

    /** The constraint that keeps the locked release and nothing else. */
    public static function exactPin(string $locked): string
    {
        return ltrim(self::version($locked), 'vV');
    }

    /** The version of `1.2.0` or `dev-main abc1234`, without the commit Composer prints after a branch. */
    private static function version(string $printed): string
    {
        return explode(' ', trim($printed), 2)[0];
    }
}
