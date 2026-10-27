<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\Dto\RequiredHandlersEditDto;
use LTS\PHPQA\HooksDaemon\RequiredHandlersEnforcer;
use LTS\PHPQA\HooksDaemon\RequiredHandlersYamlEditor;
use LTS\PHPQA\HooksDaemon\YamlLines;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Deploys against a real file: the contract is what is on disk afterwards.
 *
 * @internal
 */
#[CoversClass(RequiredHandlersEnforcer::class)]
#[UsesClass(RequiredHandlersYamlEditor::class)]
#[UsesClass(RequiredHandlersEditDto::class)]
#[UsesClass(YamlLines::class)]
#[Small]
final class RequiredHandlersEnforcerTest extends TestCase
{
    private const string CONFIG = '.claude/hooks-daemon.yaml';

    private const string COMPLIANT = <<<'YAML'
        # Why each setting is the way it is.
        handlers:
            pre_tool_use:
                git_stash:   # no stashes
                    enabled: true
                    options:
                        mode: deny
                plan_time_estimates:
                    enabled: true
                validate_instruction_content:
                    enabled: true
                markdown_organization:
                    enabled: true

        YAML;

    private TempDir $project;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqaci-required-handlers');
        $this->output  = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function deployingWhenTheSettingIsAlreadyRightLeavesTheFileUntouched(): void
    {
        $path = $this->project->write(self::CONFIG, self::COMPLIANT);
        \Safe\touch($path, 1_000_000_000);
        clearstatcache();

        self::assertSame(0, $this->enforce($path));
        self::assertSame(0, $this->enforce($path));

        clearstatcache();
        self::assertSame(self::COMPLIANT, $this->project->read(self::CONFIG));
        self::assertSame(1_000_000_000, \Safe\filemtime($path));
        self::assertStringContainsString('already configured correctly', $this->output->fetch());
    }

    #[Test]
    public function aDifferingModeChangesOnlyThatLineAndReportsIt(): void
    {
        $path = $this->project->write(self::CONFIG, str_replace('mode: deny', 'mode: warn', self::COMPLIANT));

        self::assertSame(0, $this->enforce($path));

        self::assertSame(self::COMPLIANT, $this->project->read(self::CONFIG));
        self::assertSame("  Configuration updated:\n  ✓ Updated git_stash.options.mode: warn → deny\n", $this->output->fetch());
    }

    #[Test]
    public function aMissingSettingIsAddedAndTheFileKeepsItsPermissions(): void
    {
        $path = $this->project->write(self::CONFIG, str_replace("            options:\n                mode: deny\n", '', self::COMPLIANT));
        \Safe\chmod($path, 0o640);

        self::assertSame(0, $this->enforce($path));

        clearstatcache();
        self::assertStringContainsString("    git_stash:   # no stashes\n", $this->project->read(self::CONFIG));
        self::assertStringContainsString("mode: deny\n", $this->project->read(self::CONFIG));
        self::assertStringContainsString('# Why each setting is the way it is.', $this->project->read(self::CONFIG));
        self::assertSame(0o640, \Safe\fileperms($path) & 0o777);
    }

    #[Test]
    public function aFileThatCannotBeEditedInPlaceIsRefusedUntouchedWithWhatToChangeByHand(): void
    {
        $original = "# keep me\nhandlers:\n  pre_tool_use:\n    git_stash: {enabled: true, options: {mode: warn}}\n";
        $path     = $this->project->write(self::CONFIG, $original);

        self::assertSame(1, $this->enforce($path));

        self::assertSame($original, $this->project->read(self::CONFIG));
        $expected = "  ⚠️  {$path} left untouched: handlers.pre_tool_use.git_stash cannot be edited in place, and a rewrite would drop the file's comments.\n"
            . "  Set these by hand, then re-run composer install:\n"
            . "    handlers.pre_tool_use.git_stash.enabled: true\n"
            . "    handlers.pre_tool_use.git_stash.options.mode: deny\n"
            . "    handlers.pre_tool_use.plan_time_estimates.enabled: true\n"
            . "    handlers.pre_tool_use.validate_instruction_content.enabled: true\n"
            . "    handlers.pre_tool_use.markdown_organization.enabled: true\n";
        self::assertSame($expected, $this->output->fetch());
    }

    #[Test]
    public function aMissingConfigIsReportedAndIsNoFailure(): void
    {
        self::assertSame(0, $this->enforce($this->project->path . '/' . self::CONFIG));
        self::assertStringContainsString('no hooks-daemon config', $this->output->fetch());
    }

    #[Test]
    public function mainReadsTheNamedFileAndWritesToTheGivenOutput(): void
    {
        $path = $this->project->write(self::CONFIG, "handlers:\n  pre_tool_use: {}\n");

        self::assertSame(0, RequiredHandlersEnforcer::main($path, $this->output));

        self::assertStringContainsString('git_stash:', $this->project->read(self::CONFIG));
        self::assertStringContainsString('Added git_stash.enabled: true', $this->output->fetch());
    }

    private function enforce(string $path): int
    {
        return new RequiredHandlersEnforcer($this->output)->run($path);
    }
}
