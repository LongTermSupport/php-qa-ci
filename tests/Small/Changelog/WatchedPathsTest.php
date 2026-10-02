<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\WatchedPaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WatchedPaths::class)]
#[Small]
final class WatchedPathsTest extends TestCase
{
    private const string COMPOSER_JSON = 'composer.json';

    private const string SOURCE_FILE = 'src/A.php';

    #[Test]
    public function aTrailingSlashWatchesADirectoryAndAnythingElseIsAGlobOrAFile(): void
    {
        $watched = new WatchedPaths('src/', './composer.json', '/.claude/hooks/php-qa-ci__*', 'rules-*.neon');

        self::assertSame(
            [self::SOURCE_FILE, 'src/Deep/B.php', self::COMPOSER_JSON, '.claude/hooks/php-qa-ci__x.py', 'rules-default.neon'],
            $watched->matching(self::SOURCE_FILE, 'src/Deep/B.php', 'srcX/C.php', self::COMPOSER_JSON, 'composer.lock', 'tests/src/D.php', '.claude/hooks/php-qa-ci__x.py', '.claude/hooks/other', 'rules-default.neon', 'CHANGELOG.md'),
        );
    }

    #[Test]
    public function aGlobStarCrossesDirectories(): void
    {
        self::assertSame(['templates/a/b/c.yml'], new WatchedPaths('templates/*.yml')->matching('templates/a/b/c.yml', 'templates/a/b/c.md'));
    }

    #[Test]
    public function thePathsAreReportedNormalised(): void
    {
        self::assertSame(['src/', self::COMPOSER_JSON], new WatchedPaths('./src/', '/composer.json', '  ')->paths());
    }

    #[Test]
    public function nothingIsWatchedWhenNoPathIsGiven(): void
    {
        self::assertSame([], new WatchedPaths()->matching(self::SOURCE_FILE));
    }
}
