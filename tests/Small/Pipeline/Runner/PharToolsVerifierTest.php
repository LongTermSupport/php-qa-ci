<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use LTS\PHPQA\Pipeline\Runner\Exception\MissingPharException;
use LTS\PHPQA\Pipeline\Runner\PharToolsVerifier;
use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Turbo\TurboManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "PHPStan runs without Turbo because the manifest and the phar disagree".
 *
 * The Turbo binaries are fetched against vendor-phar/turbo-ext.json, and a binary built for
 * another PHPStan release does not load: the phar then runs without Turbo and says nothing. So a
 * run refuses to start when the committed manifest is missing or names another version than the
 * phar phive.xml installed, the same way it refuses a missing PHAR.
 *
 * @internal
 */
#[CoversClass(PharToolsVerifier::class)]
#[CoversClass(MissingPharException::class)]
#[UsesClass(PhpstanDocsCatalogue::class)]
#[UsesClass(TurboManifest::class)]
#[Small]
final class PharToolsVerifierTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../../..';

    private const string VERSION = '2.3.0';

    private const string PHAR = 'vendor-phar/phpstan.phar';

    private TempDir $library;

    protected function setUp(): void
    {
        $this->library = TempDir::create('phar-tools');
        $this->library->write('phive.xml', \sprintf('<phive><phar name="phpstan" version="^2" location="./%s" copy="true" installed="%s"/></phive>', self::PHAR, self::VERSION));
        $this->library->write(self::PHAR, 'phar');
    }

    protected function tearDown(): void
    {
        $this->library->remove();
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function aLibraryWhoseManifestMatchesThePharPasses(): void
    {
        $this->library->write(TurboManifest::PATH, new TurboManifest(self::VERSION, [])->toJson());

        new PharToolsVerifier()->verify($this->library->path);
    }

    /** The shipped library itself: the committed manifest is for the committed phar. */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function theShippedLibraryPasses(): void
    {
        new PharToolsVerifier()->verify(self::REPO_ROOT);
    }

    #[Test]
    public function aManifestForAnotherPhpstanVersionFails(): void
    {
        $this->library->write(TurboManifest::PATH, new TurboManifest('2.2.16', [])->toJson());

        $this->expectException(MissingPharException::class);
        $this->expectExceptionMessageMatches('/2\.2\.16.*2\.3\.0|2\.3\.0.*2\.2\.16/');

        new PharToolsVerifier()->verify($this->library->path);
    }

    #[Test]
    public function aMissingManifestFails(): void
    {
        $this->expectException(MissingPharException::class);
        $this->expectExceptionMessageIsOrContains(TurboManifest::PATH);

        new PharToolsVerifier()->verify($this->library->path);
    }

    #[Test]
    public function aMissingPharIsStillReportedByName(): void
    {
        $this->library->write(TurboManifest::PATH, new TurboManifest(self::VERSION, [])->toJson());
        \Safe\unlink($this->library->path . '/' . self::PHAR);

        $this->expectException(MissingPharException::class);
        $this->expectExceptionMessageIsOrContains('phpstan');

        new PharToolsVerifier()->verify($this->library->path);
    }
}
