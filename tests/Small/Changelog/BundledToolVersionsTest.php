<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Changelog;

use LTS\PHPQA\Changelog\BundledToolVersions;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BundledToolVersions::class)]
#[UsesClass(ChangelogReleaseException::class)]
#[Small]
final class BundledToolVersionsTest extends TestCase
{
    private const string PHIVE = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phive xmlns="https://phar.io/phive">
          <phar name="phpstan" version="^2.2.13" location="./vendor-phar/phpstan.phar" copy="true" installed="2.2.16"/>
          <phar installed="1.3.1" name="phparkitect/arkitect" version="^1.3" location="./vendor-phar/phparkitect.phar" copy="true"/>
          <phar name="not-installed" version="^1" location="./vendor-phar/x.phar" copy="true"/>
          <pharos name="not-a-phar" installed="9.9.9"/>
        </phive>
        XML;

    private const string MANIFEST = '{"require": {"php": "^8.5", "rector/rector": "@stable"}}';

    private const string LOCK = '{"packages": [{"name": "nikic/php-parser", "version": "v5.6.0"}, {"name": "rector/rector", "version": "2.6.6"}, {"name": 7}]}';

    private const string PHPSTAN = 'phpstan';

    private const string RECTOR = 'rector/rector';

    private const string PHPSTAN_NEW = '2.2.16';

    private const string RECTOR_VERSION = '2.6.6';

    #[Test]
    public function everySourceContributesItsTools(): void
    {
        $files = [
            'phive.xml'                     => self::PHIVE,
            'vendor-bin/shellcheck.version' => "v0.11.0\n",
            'build/rector/composer.json'    => self::MANIFEST,
            'build/rector/composer.lock'    => self::LOCK,
        ];

        self::assertSame(
            [self::PHPSTAN => self::PHPSTAN_NEW, 'phparkitect/arkitect' => '1.3.1', 'shellcheck' => 'v0.11.0', self::RECTOR => self::RECTOR_VERSION],
            new BundledToolVersions()->read(static fn (string $path): ?string => $files[$path] ?? null, 'rector'),
        );
    }

    #[Test]
    public function anAbsentSourceOrOneWithoutTheExpectedShapeContributesNothing(): void
    {
        $files = [
            'vendor-bin/shellcheck.version' => "\n",
            'build/nolock/composer.json'    => self::MANIFEST,
            'build/badlock/composer.json'   => self::MANIFEST,
            'build/badlock/composer.lock'   => '{"packages": "nope"}',
            'build/scalar/composer.json'    => '"a string"',
            'build/scalar/composer.lock'    => self::LOCK,
        ];

        self::assertSame([], new BundledToolVersions()->read(static fn (string $path): ?string => $files[$path] ?? null, 'nolock', 'badlock', 'scalar', 'absent'));
    }

    #[Test]
    public function aBuildFileThatIsNotJsonIsRefused(): void
    {
        $files = ['build/broken/composer.json' => self::MANIFEST, 'build/broken/composer.lock' => 'not json'];

        try {
            new BundledToolVersions()->read(static fn (string $path): ?string => $files[$path] ?? null, 'broken');
            self::fail('expected the lock to be refused');
        } catch (ChangelogReleaseException $changelogReleaseException) {
            self::assertStringStartsWith('build/broken/composer.lock is not valid JSON: ', $changelogReleaseException->getMessage());
            self::assertInstanceOf(\Safe\Exceptions\JsonException::class, $changelogReleaseException->getPrevious());
        }
    }

    #[Test]
    public function theEntryNamesEveryToolThatMovedWasAddedOrWasRemoved(): void
    {
        $entry = new BundledToolVersions()->entry(
            [self::PHPSTAN => '2.2.15', self::RECTOR => self::RECTOR_VERSION, 'old-tool' => '1.0.0'],
            [self::PHPSTAN => self::PHPSTAN_NEW, self::RECTOR => self::RECTOR_VERSION, 'new-tool' => '0.1.0'],
        );

        self::assertSame('**Bundled tool versions updated** by the weekly dependency update: phpstan 2.2.15 → 2.2.16; new-tool 0.1.0 (added); old-tool 1.0.0 (removed).', $entry);
    }

    #[Test]
    public function nothingMovedIsNoEntry(): void
    {
        self::assertNull(new BundledToolVersions()->entry([self::PHPSTAN => self::PHPSTAN_NEW], [self::PHPSTAN => self::PHPSTAN_NEW]));
    }
}
