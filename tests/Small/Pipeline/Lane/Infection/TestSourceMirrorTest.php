<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Lane\Infection\TestSourceMirror;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TestSourceMirror::class)]
#[Small]
final class TestSourceMirrorTest extends TestCase
{
    private const string LANE_SOURCE = 'src/Pipeline/Lane/InfectionTool.php';

    private const string PHP_OPEN = '<?php';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-mirror');
        $this->project->write(self::LANE_SOURCE, self::PHP_OPEN);
        $this->project->write('src/Tool.php', self::PHP_OPEN);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aTestUnderASuiteDirectoryMirrorsTheSourceWithTheSuiteStripped(): void
    {
        self::assertSame(self::LANE_SOURCE, $this->mirror()->sourceFor('tests/Small/Pipeline/Lane/InfectionToolTest.php'));
    }

    #[Test]
    public function aTestWithoutASuiteDirectoryMirrorsTheSourceDirectly(): void
    {
        self::assertSame(self::LANE_SOURCE, $this->mirror()->sourceFor('tests/Pipeline/Lane/InfectionToolTest.php'));
    }

    #[Test]
    public function theLongestMatchingPathWinsOverAShorterOne(): void
    {
        $this->project->write('src/Lane/InfectionTool.php', self::PHP_OPEN);

        self::assertSame(self::LANE_SOURCE, $this->mirror()->sourceFor('tests/Unit/Pipeline/Lane/InfectionToolTest.php'));
        self::assertSame('src/Lane/InfectionTool.php', $this->mirror()->sourceFor('tests/Unit/Other/Lane/InfectionToolTest.php'));
    }

    #[Test]
    public function aTestWhoseSourceDoesNotExistMirrorsNothing(): void
    {
        self::assertNull($this->mirror()->sourceFor('tests/Small/Pipeline/Lane/GoneToolTest.php'));
    }

    #[Test]
    public function aFileThatIsNotATestCaseMirrorsNothing(): void
    {
        self::assertNull($this->mirror()->sourceFor('tests/Support/Tool.php'), 'support code is not named after a source file');
        self::assertNull($this->mirror()->sourceFor('tests/fixtures/ToolTest.json'));
    }

    #[Test]
    public function aPathOutsideTheTestsDirectoryMirrorsNothing(): void
    {
        self::assertNull($this->mirror()->sourceFor('src/ToolTest.php'));
        self::assertNull($this->mirror()->sourceFor('testsuite/ToolTest.php'));
    }

    #[Test]
    public function aTopLevelTestMirrorsATopLevelSource(): void
    {
        self::assertSame('src/Tool.php', $this->mirror()->sourceFor('tests/ToolTest.php'));
    }

    #[Test]
    public function theDirectoriesAreReadRelativeToTheProjectRoot(): void
    {
        $this->project->write('lib/Tool.php', self::PHP_OPEN);
        $mirror = new TestSourceMirror($this->project->path, $this->project->path . '/lib', $this->project->path . '/test');

        self::assertSame('lib/Tool.php', $mirror->sourceFor('test/Unit/ToolTest.php'));
        self::assertNull($mirror->sourceFor('tests/Unit/ToolTest.php'));
    }

    private function mirror(): TestSourceMirror
    {
        return new TestSourceMirror($this->project->path, $this->project->path . '/src', $this->project->path . '/tests');
    }
}
