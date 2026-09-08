<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\BranchNamePolicyConfig;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\BranchNamePolicyDecision;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\Dto\BranchPolicyConfigDto;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\Dto\BranchVerdictDto;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicyTool;
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
#[CoversClass(BranchNamePolicyTool::class)]
#[CoversClass(BranchNamePolicyDecision::class)]
#[CoversClass(BranchNamePolicyConfig::class)]
#[CoversClass(BranchPolicyConfigDto::class)]
#[CoversClass(BranchVerdictDto::class)]
#[CoversClass(GitBranches::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[Small]
final class BranchNamePolicyToolTest extends TestCase
{
    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->mkdir('.git');
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function theDecisionPrefersExemptionThenPrefixThenFails(): void
    {
        $decision = new BranchNamePolicyDecision();

        self::assertSame('exempt', $decision->decide('main', ['main'], ['feature/'])->reason);
        self::assertSame('feature/', $decision->decide('feature/x', ['main'], ['feature/', 'bugfix/'])->reason);
        $failed = $decision->decide('plan/00001-x', ['main'], BranchNamePolicyDecision::DEFAULT_PREFIXES);
        self::assertFalse($failed->passes);
        self::assertTrue($failed->isPlanBranch);
        self::assertFalse($decision->decide('wip', [], [])->isPlanBranch);
    }

    #[Test]
    public function theConfigReadsBothListsAndTreatsAMissingFileAsEmpty(): void
    {
        self::assertNull(new BranchNamePolicyConfig()->load($this->factory->project->path . '/qaConfig')->file);

        $this->factory->project->write('qaConfig/branchNamePolicy.yaml', "# comment\nextra_allowed_prefixes:\n  - release/\nextra_exempt_branches:\n  - php8.4\n  - \"php8.5\"\nother: ignored\n");
        $config = new BranchNamePolicyConfig()->load($this->factory->project->path . '/qaConfig');

        self::assertSame(['release/'], $config->extraAllowedPrefixes);
        self::assertSame(['php8.4', 'php8.5'], $config->extraExemptBranches);
        self::assertStringEndsWith('branchNamePolicy.yaml', (string)$config->file);
    }

    #[Test]
    public function anAllowedBranchPassesWithTheDefaultBranchDetectedLocally(): void
    {
        $this->factory->processes
            ->willSucceed("feature/thing\n")
            ->willSucceed("refs/remotes/origin/main\n")
        ;

        $result = new BranchNamePolicyTool()->run($this->factory->context());

        self::assertTrue($result->isSuccess());
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('Default branch detected: main', $printed);
        self::assertStringContainsString("PASS — branch 'feature/thing' matches allowed prefix 'feature/'", $printed);
        self::assertSame(['git rev-parse --abbrev-ref HEAD', 'git symbolic-ref refs/remotes/origin/HEAD'], $this->factory->processes->commandLines());
    }

    #[Test]
    public function theDefaultBranchFallsBackToTheRemoteAndIsExempt(): void
    {
        $this->factory->processes
            ->willSucceed("main\n")
            ->willFail(128, 'fatal: ref refs/remotes/origin/HEAD is not a symbolic ref')
            ->willSucceed("ref: refs/heads/main\tHEAD\nabc123\tHEAD\n")
        ;

        $result = new BranchNamePolicyTool()->run($this->factory->context());

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString("PASS — branch 'main' is exempt", $this->factory->output->fetch());
    }

    #[Test]
    public function configuredExemptionsAndPrefixesApply(): void
    {
        $this->factory->project->write('qaConfig/branchNamePolicy.yaml', "extra_allowed_prefixes:\n  - release/\nextra_exempt_branches:\n  - php8.5\n");
        $this->factory->processes->willSucceed("php8.5\n")->willFail(128)->willFail(128);

        self::assertTrue(new BranchNamePolicyTool()->run($this->factory->context())->isSuccess());
        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('WARNING: could not detect default branch', $printed);
        self::assertStringContainsString('Loading project overrides from', $printed);

        $this->factory->processes->willSucceed("release/1.2\n")->willSucceed("refs/remotes/origin/main\n");
        self::assertTrue(new BranchNamePolicyTool()->run($this->factory->context())->isSuccess());
    }

    #[Test]
    public function aDisallowedBranchFailsWithGuidanceAndTheIdentifier(): void
    {
        $this->factory->processes->willSucceed("wip-stuff\n")->willSucceed("refs/remotes/origin/main\n");

        $result  = new BranchNamePolicyTool()->run($this->factory->context());
        $printed = $this->factory->output->fetch();

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('DISALLOWED BRANCH', $printed);
        self::assertStringContainsString('    - feature/', $printed);
        self::assertStringNotContainsString('PLAN BRANCH DETECTED', $printed);
        self::assertStringContainsString(BranchNamePolicyTool::IDENTIFIER, $printed);
    }

    #[Test]
    public function aPlanBranchGetsTheExtraLoudGuidance(): void
    {
        $this->factory->processes->willSucceed("plan/00003-x\n")->willSucceed("refs/remotes/origin/main\n");

        $result = new BranchNamePolicyTool()->run($this->factory->context());

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome);
        self::assertStringContainsString('PLAN BRANCH DETECTED', $this->factory->output->fetch());
    }

    #[Test]
    public function outsideAWorkTreeAndOnADetachedHeadTheLaneSkips(): void
    {
        $factory = ContextFactory::create();
        $factory->processes->willFail(128, 'not a git repository');
        self::assertSame(ToolOutcomeEnum::Skipped, new BranchNamePolicyTool()->run($factory->context())->outcome);
        self::assertStringContainsString('No git repository detected', $factory->output->fetch());
        $factory->project->remove();

        $this->factory->processes->willSucceed("HEAD\n");
        self::assertSame(ToolOutcomeEnum::Skipped, new BranchNamePolicyTool()->run($this->factory->context())->outcome);
        self::assertStringContainsString('Detached HEAD', $this->factory->output->fetch());
    }
}
