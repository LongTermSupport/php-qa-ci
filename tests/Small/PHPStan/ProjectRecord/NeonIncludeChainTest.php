<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\ProjectRecord;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto;
use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto;
use LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The files PHPStan reads for a project, found by following `includes:` from
 * the project's phpstan.neon, and everything that stops the chain being read
 * in full.
 *
 * @internal
 */
#[CoversClass(NeonIncludeChain::class)]
#[UsesClass(NeonIncludeChainDto::class)]
#[UsesClass(NeonRecordFileDto::class)]
#[Small]
final class NeonIncludeChainTest extends TestCase
{
    private const string ENTRY = 'qaConfig/phpstan.neon';

    private const string B_NEON = 'qaConfig/b.neon';

    private TempDir $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('phpqa-neon-chain');
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    #[Test]
    public function aFileWithNoIncludesIsTheWholeChain(): void
    {
        $this->dir->write(self::ENTRY, "parameters:\n    level: max\n");

        $chain = $this->chain();

        self::assertSame([self::ENTRY], $this->displays($chain));
        self::assertSame([], $chain->problems);
        self::assertSame(0, $chain->files[0]->declaredEntries);
        self::assertSame("parameters:\n    level: max\n", $chain->files[0]->neon);
        self::assertSame($this->dir->path . '/' . self::ENTRY, $chain->files[0]->path);
    }

    #[Test]
    public function includesAreFollowedDepthFirstRelativeToTheIncludingFileAndComeBeforeIt(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - ../config/a.neon\n    - b.neon\n");
        $this->dir->write('config/a.neon', "includes:\n    - nested/c.neon\n");
        $this->dir->write('config/nested/c.neon', "parameters:\n    level: 8\n");
        $this->dir->write(self::B_NEON, "parameters:\n    level: 9\n");

        $chain = $this->chain();

        self::assertSame(['config/nested/c.neon', 'config/a.neon', self::B_NEON, self::ENTRY], $this->displays($chain));
        self::assertSame([], $chain->problems);
    }

    #[Test]
    public function aFileReachedTwiceOrInACycleIsReadOnce(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - a.neon\n    - b.neon\n");
        $this->dir->write('qaConfig/a.neon', "includes:\n    - b.neon\n    - phpstan.neon\n");
        $this->dir->write(self::B_NEON, "includes:\n    - a.neon\n");

        self::assertSame([self::B_NEON, 'qaConfig/a.neon', self::ENTRY], $this->displays($this->chain()));
    }

    #[Test]
    public function anAbsoluteIncludeAndTheWorkingDirectoryParameterResolve(): void
    {
        $absolute = $this->dir->write('elsewhere/abs.neon', "parameters:\n    level: 7\n");
        $this->dir->write('config/cwd.neon', "parameters:\n    level: 6\n");
        $this->dir->write(self::ENTRY, "includes:\n    - " . $absolute . "\n    - '%currentWorkingDirectory%/config/cwd.neon'\n");

        $chain = $this->chain();

        self::assertSame(['elsewhere/abs.neon', 'config/cwd.neon', self::ENTRY], $this->displays($chain));
        self::assertSame([], $chain->problems);
    }

    #[Test]
    public function aFileOutsideTheProjectIsShownByItsAbsolutePath(): void
    {
        $outside = TempDir::create('phpqa-neon-outside');

        try {
            $file = $outside->write('shared.neon', "parameters:\n    level: 5\n");
            $this->dir->write(self::ENTRY, "includes:\n    - " . $file . "\n");

            self::assertSame([\Safe\realpath($file), self::ENTRY], $this->displays($this->chain()));
        } finally {
            $outside->remove();
        }
    }

    /** A sibling directory whose name merely starts with the project root's is still outside it. */
    #[Test]
    public function aFileBesideTheProjectSharingItsNamePrefixIsShownByItsAbsolutePath(): void
    {
        $sibling = $this->dir->path . 'x';
        $shared  = $sibling . '/shared.neon';
        \Safe\mkdir($sibling);

        try {
            \Safe\file_put_contents($shared, "parameters:\n    level: 5\n");
            $this->dir->write(self::ENTRY, \sprintf("includes:\n    - %s\n", $shared));

            self::assertSame([\Safe\realpath($shared), self::ENTRY], $this->displays($this->chain()));
        } finally {
            \Safe\unlink($shared);
            \Safe\rmdir($sibling);
        }
    }

    #[Test]
    public function phpstansOwnConfigurationIsNotFollowed(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - '%rootDir%/conf/bleedingEdge.neon'\n");

        $chain = $this->chain();

        self::assertSame([self::ENTRY], $this->displays($chain));
        self::assertSame([], $chain->problems);
    }

    /**
     * PHPStan's documented `phar://phpstan.phar/conf/bleedingEdge.neon` names the
     * running PHPStan phar by its alias; the same file reached by the archive's own
     * path is PHPStan's too. Neither exists in this process, and neither is project
     * configuration.
     */
    #[Test]
    public function phpstansOwnConfigurationInsideItsPharIsNotFollowed(): void
    {
        $this->dir->write(
            self::ENTRY,
            "includes:\n    - phar://phpstan.phar/conf/bleedingEdge.neon\n    - phar:///nowhere/vendor/phpstan/phpstan/phpstan.phar/conf/bleedingEdge.neon\n",
        );

        $chain = $this->chain();

        self::assertSame([self::ENTRY], $this->displays($chain));
        self::assertSame([], $chain->problems);
    }

    #[Test]
    public function aMissingProjectIncludeBesidePhpstansPharIsStillAProblem(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - phar://phpstan.phar/conf/bleedingEdge.neon\n    - gone.neon\n");

        self::assertSame(['qaConfig/phpstan.neon includes gone.neon, which does not exist'], $this->chain()->problems);
    }

    /**
     * Any other archive may carry ignoreErrors entries like any other file, so it
     * is read as one: followed when it can be opened, a problem when it cannot.
     */
    #[Test]
    public function anIncludeInsideAnotherArchiveIsFollowedAndRead(): void
    {
        $archive = $this->dir->path . '/tools/ext.tar';
        \Safe\mkdir(\dirname($archive));
        new \PharData($archive)->addFromString('conf/rules.neon', "parameters:\n    ignoreErrors:\n        - '#x#'\n");
        $include = 'phar://' . $archive . '/conf/rules.neon';
        $this->dir->write(self::ENTRY, \sprintf("includes:\n    - %s\n", $include));

        $chain = $this->chain();

        self::assertSame([$include, self::ENTRY], $this->displays($chain));
        self::assertSame([], $chain->problems);
        self::assertSame(1, $chain->files[0]->declaredEntries);
    }

    #[Test]
    public function anIncludeInsideAnArchiveThatCannotBeOpenedIsAProblem(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - phar://other.phar/conf/rules.neon\n    - phar:///nowhere/notphpstan.phar/conf/rules.neon\n");

        self::assertSame(
            [
                'qaConfig/phpstan.neon includes phar://other.phar/conf/rules.neon, which does not exist',
                'qaConfig/phpstan.neon includes phar:///nowhere/notphpstan.phar/conf/rules.neon, which does not exist',
            ],
            $this->chain()->problems,
        );
    }

    #[Test]
    public function anyOtherParameterStopsTheChainAndIsAProblem(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - '%env.CONFIG_DIR%/extra.neon'\n");

        self::assertSame(
            ['qaConfig/phpstan.neon includes %env.CONFIG_DIR%/extra.neon, whose %parameter% cannot be resolved outside PHPStan, so nothing it reaches can be checked'],
            $this->chain()->problems,
        );
    }

    #[Test]
    public function aMissingIncludeIsAProblem(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - gone.neon\n");

        self::assertSame(['qaConfig/phpstan.neon includes gone.neon, which does not exist'], $this->chain()->problems);
    }

    #[Test]
    public function aFileThatIsNotNeonIsAProblemAndIsNotRead(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - broken.neon\n");
        $this->dir->write('qaConfig/broken.neon', "parameters:\n  a: [\n");

        $chain = $this->chain();

        self::assertSame([self::ENTRY], $this->displays($chain));
        self::assertCount(1, $chain->problems);
        self::assertStringStartsWith('qaConfig/broken.neon is not valid NEON: ', $chain->problems[0]);
    }

    #[Test]
    public function includesThatAreNotAListOfPathsAreAProblem(): void
    {
        $this->dir->write(self::ENTRY, "includes: just-a-string.neon\n");
        $this->dir->write('qaConfig/other.neon', "includes:\n    - [nested]\n");
        $this->dir->write('qaConfig/entry2.neon', "includes:\n    - other.neon\n");

        $chain = $this->chain();
        self::assertSame(['qaConfig/phpstan.neon has an includes key that is not a list of paths'], $chain->problems);
        self::assertSame([self::ENTRY], $this->displays($chain), 'the file itself is still read, so its own entries are checked');
        self::assertSame(
            ['qaConfig/other.neon has an includes key that is not a list of paths'],
            new NeonIncludeChain()->resolve($this->dir->path . '/qaConfig/entry2.neon', $this->dir->path)->problems,
        );
    }

    #[Test]
    public function aPhpIncludeThatSetsIgnoreErrorsOrIncludesIsAProblem(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - baseline.php\n    - more.php\n    - plain.php\n");
        $this->dir->write('qaConfig/baseline.php', "<?php return ['parameters' => ['ignoreErrors' => []]];\n");
        $this->dir->write('qaConfig/more.php', "<?php return ['includes' => ['x.neon']];\n");
        $this->dir->write('qaConfig/plain.php', "<?php return ['parameters' => ['level' => 5]];\n");

        $chain = $this->chain();

        self::assertSame([self::ENTRY], $this->displays($chain));
        self::assertSame(
            [
                'qaConfig/phpstan.neon includes baseline.php, a PHP configuration file that sets ignoreErrors or includes; a PHP file cannot carry the justification comment, so move them into a .neon file',
                'qaConfig/phpstan.neon includes more.php, a PHP configuration file that sets ignoreErrors or includes; a PHP file cannot carry the justification comment, so move them into a .neon file',
            ],
            $chain->problems,
        );
    }

    #[Test]
    public function theDeclaredEntriesAreCountedFromTheDecodedFile(): void
    {
        $this->dir->write(self::ENTRY, "parameters:\n    ignoreErrors: ['#a#', '#b#']\n");
        $this->dir->write('qaConfig/scalar.neon', "parameters:\n    ignoreErrors: nope\n");
        $this->dir->write('qaConfig/flat.neon', "parameters: nope\n");

        self::assertSame(2, $this->chain()->files[0]->declaredEntries);
        self::assertSame(0, new NeonIncludeChain()->resolve($this->dir->path . '/qaConfig/scalar.neon', $this->dir->path)->files[0]->declaredEntries);
        self::assertSame(0, new NeonIncludeChain()->resolve($this->dir->path . '/qaConfig/flat.neon', $this->dir->path)->files[0]->declaredEntries);
    }

    private function chain(): NeonIncludeChainDto
    {
        return new NeonIncludeChain()->resolve($this->dir->path . '/' . self::ENTRY, $this->dir->path);
    }

    /** @return list<string> */
    private function displays(NeonIncludeChainDto $chain): array
    {
        return array_map(static fn (NeonRecordFileDto $file): string => $file->display, $chain->files);
    }
}
