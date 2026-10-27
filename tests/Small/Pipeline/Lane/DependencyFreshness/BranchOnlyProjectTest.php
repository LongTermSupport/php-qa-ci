<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DependencyFreshness;

use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\BranchOnlyProject;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The throwaway copy of a project that requires chosen packages by their
 * branch alternatives alone.
 *
 * @internal
 */
#[CoversClass(BranchOnlyProject::class)]
#[Small]
final class BranchOnlyProjectTest extends TestCase
{
    private const string JSON = 'composer.json';

    private const string LOCKFILE = 'composer.lock';

    private const string MAIN = 'dev-main';

    private const string EXT = '.json';

    private const string LOCK = '{"packages": []}';

    private TempDir $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('phpqa-branch-only');
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    #[Test]
    public function theNamedPackagesAreRequiredUnderTheirBranchAlternativesAndNothingElseChanges(): void
    {
        $this->dir->write(self::JSON, '{"name": "acme/app", "require": {"Acme/One": "dev-main || ^1.0", "acme/two": "^2.0"}, "require-dev": {"acme/three": "dev-x || ^3.0"}}');
        $this->dir->write(self::LOCKFILE, self::LOCK);

        $copy = BranchOnlyProject::create($this->dir->path, ['acme/one' => self::MAIN, 'acme/three' => 'dev-x@dev']);

        $json = \Safe\json_decode($this->dir->read($copy->jsonFile), true);
        self::assertSame(
            ['name' => 'acme/app', 'require' => ['Acme/One' => self::MAIN, 'acme/two' => '^2.0'], 'require-dev' => ['acme/three' => 'dev-x@dev']],
            $json,
        );
    }

    #[Test]
    public function theLockIsCopiedBesideTheCopyAndBothAreRemoved(): void
    {
        $this->dir->write(self::JSON, '{"require": {"acme/one": "dev-main || ^1.0"}}');
        $this->dir->write(self::LOCKFILE, self::LOCK);

        $copy = BranchOnlyProject::create($this->dir->path, ['acme/one' => self::MAIN]);

        self::assertStringEndsWith(self::EXT, $copy->jsonFile);
        self::assertSame(self::LOCK, $this->dir->read(basename($copy->jsonFile, self::EXT) . '.lock'));
        self::assertFileExists($this->dir->path . '/' . $copy->jsonFile);

        $copy->remove($this->dir->path);

        self::assertFileDoesNotExist($this->dir->path . '/' . $copy->jsonFile);
        self::assertFileDoesNotExist($this->dir->path . '/' . basename($copy->jsonFile, self::EXT) . '.lock');
        $copy->remove($this->dir->path);
    }

    /** A `require` that is not an object leaves `require-dev` still to be rewritten, and names match without regard to case. */
    #[Test]
    public function aRequireSectionThatIsNotAnObjectDoesNotStopTheNextSectionBeingRewritten(): void
    {
        $this->dir->write(self::JSON, '{"require": "x", "require-dev": {"acme/one": "dev-main || ^1.0"}}');
        $this->dir->write(self::LOCKFILE, self::LOCK);

        $copy = BranchOnlyProject::create($this->dir->path, ['ACME/One' => self::MAIN]);

        self::assertSame(
            ['require' => 'x', 'require-dev' => ['acme/one' => self::MAIN]],
            \Safe\json_decode($this->dir->read($copy->jsonFile), true),
        );
    }

    /** Composer rejects `[]` where the schema wants an object, so every empty object must stay one. */
    #[Test]
    public function emptyJsonObjectsStayObjectsInTheCopy(): void
    {
        $this->dir->write(self::JSON, '{"require": {}, "require-dev": {"acme/one": "dev-main || ^1.0"}, "config": {}, "extra": {"nested": {}}, "scripts": {}, "repositories": {}, "list": []}');
        $this->dir->write(self::LOCKFILE, self::LOCK);

        $copy = BranchOnlyProject::create($this->dir->path, ['acme/one' => self::MAIN]);

        self::assertSame(
            '{"require":{},"require-dev":{"acme/one":"dev-main"},"config":{},"extra":{"nested":{}},"scripts":{},"repositories":{},"list":[]}',
            $this->dir->read($copy->jsonFile),
        );
    }

    #[Test]
    public function twoCopiesDoNotShareAFile(): void
    {
        $this->dir->write(self::JSON, '{"require": {"acme/one": "dev-main || ^1.0"}}');
        $this->dir->write(self::LOCKFILE, self::LOCK);

        $first  = BranchOnlyProject::create($this->dir->path, []);
        $second = BranchOnlyProject::create($this->dir->path, []);

        self::assertNotSame($first->jsonFile, $second->jsonFile);
    }

    #[Test]
    public function aComposerJsonThatIsNotAnObjectIsRefused(): void
    {
        $this->dir->write(self::JSON, '"text"');
        $this->dir->write(self::LOCKFILE, self::LOCK);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('composer.json is not a JSON object');

        BranchOnlyProject::create($this->dir->path, []);
    }
}
