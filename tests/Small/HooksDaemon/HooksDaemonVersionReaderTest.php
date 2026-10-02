<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\HooksDaemonVersionReader;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(HooksDaemonVersionReader::class)]
#[Small]
final class HooksDaemonVersionReaderTest extends TestCase
{
    private const string HANDLER_RELEASE = '3.67.0';

    private TempDir $daemon;

    protected function setUp(): void
    {
        $this->daemon = TempDir::create('phpqaci-daemon-version');
    }

    protected function tearDown(): void
    {
        $this->daemon->remove();
    }

    #[Test]
    public function theVersionIsReadFromTheDaemonsVersionModule(): void
    {
        $this->daemon->write(HooksDaemonVersionReader::VERSION_FILE, \sprintf("\"\"\"Version.\"\"\"\n\n__version__ = \"%s\"\n", self::HANDLER_RELEASE));

        self::assertSame(self::HANDLER_RELEASE, new HooksDaemonVersionReader()->read($this->daemon->path));
    }

    #[Test]
    public function singleQuotesAndAPreReleaseSuffixAreRead(): void
    {
        $this->daemon->write(HooksDaemonVersionReader::VERSION_FILE, "__version__ = '3.68.0rc1'\n");

        self::assertSame('3.68.0rc1', new HooksDaemonVersionReader()->read($this->daemon->path));
    }

    #[Test]
    public function aMissingVersionModuleIsNoVersion(): void
    {
        self::assertNull(new HooksDaemonVersionReader()->read($this->daemon->path));
    }

    #[Test]
    public function aModuleWithNoVersionAssignmentIsNoVersion(): void
    {
        $this->daemon->write(HooksDaemonVersionReader::VERSION_FILE, "VERSION = \"3.67.0\"\n");

        self::assertNull(new HooksDaemonVersionReader()->read($this->daemon->path));
    }

    #[Test]
    public function aValueThatIsNotAVersionIsNoVersion(): void
    {
        $this->daemon->write(HooksDaemonVersionReader::VERSION_FILE, "__version__ = \"unknown\"\n");

        self::assertNull(new HooksDaemonVersionReader()->read($this->daemon->path));
    }

    #[Test]
    #[DataProvider('versions')]
    public function aVersionIsComparedAsAVersionNotAString(string $version, bool $atLeast): void
    {
        self::assertSame($atLeast, new HooksDaemonVersionReader()->isAtLeast($version, self::HANDLER_RELEASE));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function versions(): iterable
    {
        yield 'the minimum itself' => [self::HANDLER_RELEASE, true];
        yield 'a later patch' => ['3.67.1', true];
        yield 'a later minor, compared numerically' => ['3.100.0', true];
        yield 'a later major' => ['4.0.0', true];
        yield 'the previous minor' => ['3.66.9', false];
        yield 'an earlier major with a larger minor' => ['2.99.0', false];
        yield 'a pre-release of the minimum' => ['3.67.0rc1', false];
    }
}
