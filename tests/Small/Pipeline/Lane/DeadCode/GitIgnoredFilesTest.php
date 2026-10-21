<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DeadCode;

use LTS\PHPQA\Pipeline\Lane\DeadCode\GitIgnoredFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What `git ls-files -z --others --ignored --exclude-standard --directory` lists: each ignored
 * untracked file, and each wholly ignored directory once, with a trailing slash.
 *
 * @internal
 */
#[CoversClass(GitIgnoredFiles::class)]
#[Small]
final class GitIgnoredFilesTest extends TestCase
{
    private const string LISTING = "bin/.phpunit/\0bin/x.local\0build/\0";

    #[Test]
    #[DataProvider('paths')]
    public function aPathIsIgnoredWhenListedOrUnderAListedDirectory(string $relative, bool $ignored): void
    {
        self::assertSame($ignored, GitIgnoredFiles::fromListing(self::LISTING)->contains($relative));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'a listed file' => ['bin/x.local', true];
        yield 'a listed directory' => ['bin/.phpunit', true];
        yield 'a file in a listed directory' => ['bin/.phpunit/phpunit-9.6/phpunit', true];
        yield 'a file in another listed directory' => ['build/tool', true];
        yield 'a file beside a listed one' => ['bin/console', false];
        yield 'a file whose name a listed file starts' => ['bin/x.local.php', false];
        yield 'a directory whose name a listed directory starts' => ['bin/.phpunit-old/phpunit', false];
        yield 'a parent of a listed directory' => ['bin', false];
    }

    #[Test]
    public function noListingIgnoresNothing(): void
    {
        self::assertFalse(GitIgnoredFiles::none()->contains('bin/x.local'));
        self::assertFalse(GitIgnoredFiles::fromListing('')->contains(''));
    }
}
