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
    public function includesAreFollowedDepthFirstRelativeToTheIncludingFile(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - ../config/a.neon\n    - b.neon\n");
        $this->dir->write('config/a.neon', "includes:\n    - nested/c.neon\n");
        $this->dir->write('config/nested/c.neon', "parameters:\n    level: 8\n");
        $this->dir->write('qaConfig/b.neon', "parameters:\n    level: 9\n");

        $chain = $this->chain();

        self::assertSame([self::ENTRY, 'config/a.neon', 'config/nested/c.neon', 'qaConfig/b.neon'], $this->displays($chain));
        self::assertSame([], $chain->problems);
    }

    #[Test]
    public function aFileReachedTwiceOrInACycleIsReadOnce(): void
    {
        $this->dir->write(self::ENTRY, "includes:\n    - a.neon\n    - b.neon\n");
        $this->dir->write('qaConfig/a.neon', "includes:\n    - b.neon\n    - phpstan.neon\n");
        $this->dir->write('qaConfig/b.neon', "includes:\n    - a.neon\n");

        self::assertSame([self::ENTRY, 'qaConfig/a.neon', 'qaConfig/b.neon'], $this->displays($this->chain()));
    }

    #[Test]
    public function anAbsoluteIncludeAndTheWorkingDirectoryParameterResolve(): void
    {
        $absolute = $this->dir->write('elsewhere/abs.neon', "parameters:\n    level: 7\n");
        $this->dir->write('config/cwd.neon', "parameters:\n    level: 6\n");
        $this->dir->write(self::ENTRY, "includes:\n    - " . $absolute . "\n    - '%currentWorkingDirectory%/config/cwd.neon'\n");

        $chain = $this->chain();

        self::assertSame([self::ENTRY, 'elsewhere/abs.neon', 'config/cwd.neon'], $this->displays($chain));
        self::assertSame([], $chain->problems);
    }

    #[Test]
    public function aFileOutsideTheProjectIsShownByItsAbsolutePath(): void
    {
        $outside = TempDir::create('phpqa-neon-outside');

        try {
            $file = $outside->write('shared.neon', "parameters:\n    level: 5\n");
            $this->dir->write(self::ENTRY, "includes:\n    - " . $file . "\n");

            self::assertSame([self::ENTRY, \Safe\realpath($file)], $this->displays($this->chain()));
        } finally {
            $outside->remove();
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

        self::assertSame(['qaConfig/phpstan.neon has an includes key that is not a list of paths'], $this->chain()->problems);
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
