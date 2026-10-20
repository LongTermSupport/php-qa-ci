<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\PhpLintTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Filesystem\UnresolvablePath::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class PhpLintToolTest extends TestCase
{
    private const string SRC = '/src';

    private const string TESTS = '/tests';

    private const string EXCLUDE = '--exclude';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aCleanLintPassesAndRunsTheParallelLintPharOverTheCheckedPaths(): void
    {
        $this->factory->processes->willSucceed('No syntax error found');
        $root = $this->factory->project->path;

        $result = new PhpLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['/usr/bin/php', '-d', 'memory_limit=4G', '-f', $this->factory->context()->config->paths->pharDir . '/parallel-lint.phar', '--', $root . self::TESTS, $root . self::SRC],
            $this->factory->processes->lastSpec()->command,
        );
        self::assertSame($root, $this->factory->processes->lastSpec()->cwd);
        self::assertStringNotContainsString(PhpLintTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function eachIgnoredPathBecomesAnExcludeUnderTheProjectRoot(): void
    {
        $this->factory->processes->willSucceed();
        $root   = $this->factory->project->path;
        $config = $this->factory->builder()->withIgnoredPaths('tests/Asset', 'src/Generated')->build();

        new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(
            ['--', self::EXCLUDE, $root . '/tests/Asset', self::EXCLUDE, $root . '/src/Generated', $root . self::TESTS, $root . self::SRC],
            \array_slice($this->factory->processes->lastSpec()->command, 5),
        );
    }

    /** parallel-lint reports a dangling symlink as an unexplained "Unknown error" (#241). */
    #[Test]
    public function aDanglingSymlinkItWouldLintIsNamedAsSkippedAndExcluded(): void
    {
        $this->factory->processes->willSucceed();
        $root = $this->factory->project->path;
        $this->factory->project->write('src/Real.php', "<?php\n");
        \Safe\symlink('missing-target.php', $root . '/src/Gone.PHP');
        \Safe\symlink('missing-target.txt', $root . '/src/Gone.txt');
        $this->factory->project->write('tests/Asset/keep.txt', '');
        \Safe\symlink('missing-target.php', $root . '/tests/Asset/Ignored.php');
        $config = $this->factory->builder()->withIgnoredPaths('tests/Asset')->build();

        $result = new PhpLintTool()->run($this->factory->context($config));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome);
        self::assertSame(
            ['--', self::EXCLUDE, $root . '/tests/Asset', self::EXCLUDE, $root . '/src/Gone.PHP', $root . self::TESTS, $root . self::SRC],
            \array_slice($this->factory->processes->lastSpec()->command, 5),
        );
        self::assertSame(
            "PHP Lint skipped src/Gone.PHP: its real path cannot be resolved (a symlink whose target does not exist), so it was not checked\n",
            $this->factory->output->fetch(),
        );
    }

    #[Test]
    public function aNonZeroExitFailsWithTheIdentifierTrailer(): void
    {
        $this->factory->processes->willFail(1, 'Parse error: syntax error');

        $result = new PhpLintTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertSame('PHP Lint failed (exit 1)', $result->summary);
        self::assertStringContainsString(PhpLintTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function nameAndIdentifierAreStable(): void
    {
        $tool = new PhpLintTool();

        self::assertSame('phpLint', $tool->name());
        self::assertSame('phpqaci.phpLint', $tool->identifier());
    }
}
