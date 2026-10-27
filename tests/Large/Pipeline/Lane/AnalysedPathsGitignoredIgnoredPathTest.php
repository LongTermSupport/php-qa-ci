<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\AnalysedPathsTool;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\RealLaneContext;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "an ignored path that git ignores fails the analysedPaths lane
 * while the file it names is absent" (#359). A framework's generated config file is
 * gitignored and written by a run, so a fresh checkout lacks it: the lane failed there as
 * stale, then passed once a run had generated the file. The verdict must not depend on
 * whether the file has been generated yet, and must stay a failure for an absent path git
 * does not ignore.
 *
 * Each test runs the real lane over a real git work tree, with git isolated from the host's
 * configuration.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class AnalysedPathsGitignoredIgnoredPathTest extends TestCase
{
    private const string GENERATED = 'config/generated.php';

    private const string PHP = "<?php\n\ndeclare(strict_types=1);\n";

    /** @var array<string, string|false> the host values of the isolating variables, restored afterwards */
    private array $hostEnvironment = [];

    private TempDir $project;

    protected function setUp(): void
    {
        foreach (GitSandbox::environment() as $name => $value) {
            $this->hostEnvironment[$name] = getenv($name);
            \Safe\putenv($name . '=' . $value);
        }

        $this->project = TempDir::create('phpqa-ignored-path');
        $this->project->write('composer.json', '{"name": "probe/probe", "type": "project"}');
        $this->project->write('src/Service.php', self::PHP);
        $this->project->write('tests/ServiceTest.php', self::PHP);
        $this->project->write('.gitignore', "/config/generated.php\n/build/\n");
        $this->git('init', '--initial-branch=php8.5');
        $this->git('add', '-A');
        $this->git('commit', '-m', 'Initial');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        foreach ($this->hostEnvironment as $name => $value) {
            \Safe\putenv(false === $value ? $name : $name . '=' . $value);
        }
    }

    #[Test]
    public function aGitignoredFileThatIsAbsentIsAllowedToBeMissing(): void
    {
        self::assertSame(ToolOutcomeEnum::Passed, $this->lane(self::GENERATED)->outcome);
    }

    #[Test]
    public function aGitignoredDirectoryThatIsAbsentIsAllowedToBeMissing(): void
    {
        self::assertSame(ToolOutcomeEnum::Passed, $this->lane('./build/')->outcome);
    }

    #[Test]
    public function theVerdictIsTheSameOnceTheGitignoredFileHasBeenGenerated(): void
    {
        $first = $this->lane(self::GENERATED)->outcome;
        $this->project->write(self::GENERATED, self::PHP);
        $second = $this->lane(self::GENERATED)->outcome;

        self::assertSame(ToolOutcomeEnum::Passed, $first);
        self::assertSame($first, $second, 'the lane judged the project differently before and after the ignored file was generated');
    }

    #[Test]
    public function anAbsentPathGitDoesNotIgnoreStillFailsAsStale(): void
    {
        $output = new BufferedOutput();
        $result = new AnalysedPathsTool()->run($this->context($output, 'config/removed.php'));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("withIgnoredPaths('config/removed.php') names a path where nothing exists", $output->fetch());
    }

    #[Test]
    public function aPathThatExistsPassesWhetherOrNotGitIgnoresIt(): void
    {
        $this->project->write('fixtures/data.json', '{}');

        self::assertSame(ToolOutcomeEnum::Passed, $this->lane('fixtures')->outcome);
    }

    #[Test]
    public function onlyTheMissingUnignoredPathIsNamedWhenSeveralAreDeclared(): void
    {
        $output  = new BufferedOutput();
        $result  = new AnalysedPathsTool()->run($this->context($output, self::GENERATED, 'config/removed.php'));
        $printed = $output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString("withIgnoredPaths('config/removed.php')", $printed);
        self::assertStringNotContainsString("withIgnoredPaths('config/generated.php')", $printed);
    }

    private function lane(string ...$ignored): \LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto
    {
        return new AnalysedPathsTool()->run($this->context(new BufferedOutput(), ...$ignored));
    }

    private function context(BufferedOutput $output, string ...$ignored): ToolContext
    {
        return RealLaneContext::over($this->project->path, false, $output, ignoredPaths: array_values($ignored));
    }

    private function git(string ...$args): void
    {
        $process = new Process(['git', ...array_values($args)], $this->project->path, GitSandbox::environment());
        $process->mustRun();
    }
}
