<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PhpstanReplace;

use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use LTS\PHPQA\PhpstanReplace\PhpstanReplaceSync;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * composer.json replaces phpstan/phpstan at the version of the shipped phar (#60), so the
 * replace has to move whenever the phar does. An install verifies it and changes nothing; an
 * update rewrites it to the phar's version, touching nothing else in the file.
 *
 * @internal
 */
#[CoversClass(PhpstanReplaceSync::class)]
#[UsesClass(PhpstanDocsCatalogue::class)]
#[Small]
final class PhpstanReplaceSyncTest extends TestCase
{
    private const string COMPOSER = 'composer.json';

    private const string INSTALLED_PHAR = '2.3.0';

    private const string COMPOSER_AT = <<<'JSON'
        {
          "name": "lts/php-qa-ci",
          "replace": {
            "phpstan/phpstan": "%s"
          },
          "require": {
            "phpstan/phpstan-strict-rules": "^2.0.12"
          }
        }

        JSON;

    private TempDir $library;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->library = TempDir::create('phpqa-phpstan-replace');
        $this->output  = new BufferedOutput();
        $this->library->write('phive.xml', '<phive><phar name="phpstan" version="^2.2" location="./vendor-phar/phpstan.phar" copy="true" installed="' . self::INSTALLED_PHAR . '"/></phive>');
    }

    protected function tearDown(): void
    {
        $this->library->remove();
    }

    #[Test]
    public function anInstallPassesWhenTheReplaceMatchesThePhar(): void
    {
        $this->library->write(self::COMPOSER, \sprintf(self::COMPOSER_AT, self::INSTALLED_PHAR));

        self::assertSame(0, $this->sync(PhpstanReplaceSync::MODE_INSTALL));
    }

    #[Test]
    public function anInstallFailsAndChangesNothingWhenTheReplaceDiffers(): void
    {
        $this->library->write(self::COMPOSER, \sprintf(self::COMPOSER_AT, '*'));

        self::assertSame(1, $this->sync(PhpstanReplaceSync::MODE_INSTALL));
        self::assertStringContainsString('replaces phpstan/phpstan at *, but the shipped phpstan.phar is 2.3.0', $this->output->fetch());
        self::assertSame(\sprintf(self::COMPOSER_AT, '*'), $this->library->read(self::COMPOSER));
    }

    #[Test]
    public function anUpdateMovesTheReplaceToThePharAndTouchesNothingElse(): void
    {
        $this->library->write(self::COMPOSER, \sprintf(self::COMPOSER_AT, '2.2.16'));

        self::assertSame(0, $this->sync(PhpstanReplaceSync::MODE_UPDATE));
        self::assertSame(\sprintf(self::COMPOSER_AT, self::INSTALLED_PHAR), $this->library->read(self::COMPOSER));
        self::assertStringContainsString('phpstan/phpstan replace 2.2.16 -> 2.3.0', $this->output->fetch());
    }

    #[Test]
    public function anUpdateAtTheRightVersionLeavesTheFileAlone(): void
    {
        $this->library->write(self::COMPOSER, \sprintf(self::COMPOSER_AT, self::INSTALLED_PHAR));

        self::assertSame(0, $this->sync(PhpstanReplaceSync::MODE_UPDATE));
        self::assertSame(\sprintf(self::COMPOSER_AT, self::INSTALLED_PHAR), $this->library->read(self::COMPOSER));
        self::assertSame('', $this->output->fetch());
    }

    #[Test]
    public function aComposerJsonThatDoesNotReplacePhpstanFails(): void
    {
        $this->library->write(self::COMPOSER, "{\n  \"name\": \"lts/php-qa-ci\"\n}\n");

        self::assertSame(1, $this->sync(PhpstanReplaceSync::MODE_UPDATE));
        self::assertStringContainsString('does not replace phpstan/phpstan', $this->output->fetch());
    }

    #[Test]
    public function aPhiveXmlWithoutPhpstanFails(): void
    {
        $this->library->write('phive.xml', '<phive/>');
        $this->library->write(self::COMPOSER, \sprintf(self::COMPOSER_AT, self::INSTALLED_PHAR));

        self::assertSame(1, $this->sync(PhpstanReplaceSync::MODE_INSTALL));
        self::assertStringContainsString('phive.xml records no installed phpstan.phar', $this->output->fetch());
    }

    private function sync(string $mode): int
    {
        return new PhpstanReplaceSync($this->output)->run($this->library->path, $mode);
    }
}
