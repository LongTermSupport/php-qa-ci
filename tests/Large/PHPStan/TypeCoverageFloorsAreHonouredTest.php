<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\PHPStan;

use LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "a setting php-qa-ci passes to a tool that the
 * installed tool ignores". type-coverage reports a parameter it no longer
 * honours on stderr and carries on, so a floor set with
 * withTypeCoverageFloors() would leave the run green and the floor
 * unenforced. Every floor the builder can set is passed, as the phpstan lane
 * passes it, to the installed extension, which must take all of them.
 *
 * @internal
 */
#[CoversClass(TypeCoverageOptionsDto::class)]
#[Large]
final class TypeCoverageFloorsAreHonouredTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-type-coverage-floors');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function everyFloorTheBuilderCanSetIsHonouredByTheInstalledExtension(): void
    {
        $floors = [];
        foreach (new ReflectionMethod(QaConfigBuilder::class, 'withTypeCoverageFloors')->getParameters() as $parameter) {
            $floors[$parameter->getName()] = 50;
        }

        // The extension installer loads type-coverage, as it does in a consuming project.
        $neon = "parameters:\n    level: 0\n    tmpDir: " . $this->project->path . "/tmp\n    paths:\n        - src\n    type_coverage:\n";
        foreach (new TypeCoverageOptionsDto(...$floors)->neonParameters() as $key => $floor) {
            $neon .= \sprintf("        %s: %d\n", $key, $floor);
        }

        $this->project->write('phpstan.neon', $neon);
        $this->project->write('src/Typed.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Typed\n{\n    public const int LIMIT = 1;\n\n    public int \$count = 0;\n\n    public function add(int \$by): int\n    {\n        return \$this->count + \$by;\n    }\n}\n");

        $process = new Process([
            \PHP_BINARY,
            \Safe\realpath(self::REPO_ROOT . '/vendor-phar/phpstan.phar'),
            'analyse',
            '--configuration=phpstan.neon',
            '--no-progress',
            '--memory-limit=1G',
            '--autoload-file=' . \Safe\realpath(self::REPO_ROOT . '/vendor/autoload.php'),
        ], $this->project->path);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringNotContainsStringIgnoringCase(
            'deprecated',
            $process->getErrorOutput(),
            'type-coverage ignores a floor withTypeCoverageFloors() passes it',
        );
    }
}
