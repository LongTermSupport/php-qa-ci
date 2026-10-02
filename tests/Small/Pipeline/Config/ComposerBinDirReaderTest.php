<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\ComposerBinDirReader;
use LTS\PHPQA\Pipeline\Config\Exception\ProjectLayoutException;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ComposerBinDirReader::class)]
#[CoversClass(ProjectLayoutException::class)]
#[Small]
final class ComposerBinDirReaderTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/pipeline';

    private const string COMPOSER_DEFAULT = 'vendor/bin';

    private ?TempDir $temp = null;

    protected function tearDown(): void
    {
        $this->temp?->remove();
    }

    #[Test]
    public function composersDefaultIsVendorBin(): void
    {
        self::assertSame(self::COMPOSER_DEFAULT, new ComposerBinDirReader()->read(self::FIXTURE . '/project'));
    }

    #[Test]
    public function aConfiguredBinDirIsReadFromComposerJson(): void
    {
        self::assertSame('bin', new ComposerBinDirReader()->read(self::FIXTURE . '/symfonyProject'));
    }

    #[Test]
    public function surroundingSlashesAreDropped(): void
    {
        self::assertSame('tools/bin', new ComposerBinDirReader()->read($this->projectWith('{"config": {"bin-dir": "/tools/bin/"}}')));
    }

    #[Test]
    public function anEmptyBinDirFallsBackToTheDefault(): void
    {
        self::assertSame(self::COMPOSER_DEFAULT, new ComposerBinDirReader()->read($this->projectWith('{"config": {"bin-dir": ""}}')));
    }

    #[Test]
    public function aConfigThatIsNotAnObjectFallsBackToTheDefault(): void
    {
        self::assertSame(self::COMPOSER_DEFAULT, new ComposerBinDirReader()->read($this->projectWith('{"config": "bin"}')));
    }

    #[Test]
    public function aComposerJsonThatIsNotAnObjectIsRejected(): void
    {
        self::assertStringContainsString('No readable composer.json', $this->failure($this->projectWith('"just a string"')));
    }

    #[Test]
    public function aProjectWithoutComposerJsonIsRejected(): void
    {
        self::assertStringContainsString('No readable composer.json', $this->failure(self::FIXTURE . '/noComposer'));
    }

    private function projectWith(string $composerJson): string
    {
        $this->temp = TempDir::create('phpqaci-bindir');
        $this->temp->write('composer.json', $composerJson);

        return $this->temp->path;
    }

    private function failure(string $projectRoot): string
    {
        try {
            new ComposerBinDirReader()->read($projectRoot);
        } catch (ProjectLayoutException $projectLayoutException) {
            return $projectLayoutException->getMessage();
        }

        self::fail('Expected a ProjectLayoutException for ' . $projectRoot);
    }
}
