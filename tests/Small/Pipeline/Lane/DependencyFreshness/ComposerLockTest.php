<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness;

use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\ComposerLock;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(ComposerLock::class)]
#[Small]
final class ComposerLockTest extends TestCase
{
    private const string LOCK = 'composer.lock';

    private TempDir $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('phpqa-lock');
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    #[Test]
    public function itListsEveryLockedPackageProductionThenDev(): void
    {
        $path = $this->dir->write(self::LOCK, '{"packages": [{"name": "lts/php-qa-ci"}, {"name": "psr/log"}], "packages-dev": [{"name": "acme/tool"}]}');

        self::assertSame(['lts/php-qa-ci', 'psr/log', 'acme/tool'], ComposerLock::packageNames($path));
    }

    #[Test]
    public function aLockWithoutPackageListsHasNoPackages(): void
    {
        self::assertSame([], ComposerLock::packageNames($this->dir->write(self::LOCK, '{"content-hash": "x"}')));
    }

    #[Test]
    public function anEntryWithoutANameIsNotAPackage(): void
    {
        self::assertSame(['psr/log'], ComposerLock::packageNames($this->dir->write(self::LOCK, '{"packages": [{"version": "1.0.0"}, {"name": 7}, "x", {"name": "psr/log"}]}')));
    }

    #[Test]
    public function itReadsTheLockedVersionOfEveryPackageThatRecordsOne(): void
    {
        $path = $this->dir->write(self::LOCK, '{"packages": [{"name": "lts/php-qa-ci", "version": "dev-php8.5"}, {"name": "psr/log"}, {"name": "acme/x", "version": 3}], "packages-dev": [{"name": "acme/tool", "version": "1.2.0"}]}');

        self::assertSame(['lts/php-qa-ci' => 'dev-php8.5', 'acme/tool' => '1.2.0'], ComposerLock::versions($path));
    }

    #[Test]
    public function aLockThatIsNotJsonIsRefusedNamingTheFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('composer.lock is not valid JSON');

        ComposerLock::packageNames($this->dir->write(self::LOCK, '{not json'));
    }

    #[Test]
    public function theVersionsOfALockThatIsNotJsonAreRefusedToo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('composer.lock is not valid JSON');

        ComposerLock::versions($this->dir->write(self::LOCK, '{not json'));
    }

    #[Test]
    public function aLockThatIsNotAnObjectIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('composer.lock is not a JSON object');

        ComposerLock::packageNames($this->dir->write(self::LOCK, '"text"'));
    }
}
