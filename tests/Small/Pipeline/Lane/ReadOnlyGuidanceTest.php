<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Lane\ReadOnlyGuidance;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReadOnlyGuidance::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class ReadOnlyGuidanceTest extends TestCase
{
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
    public function itNamesTheToolAndTheQaTargetInTheRemediation(): void
    {
        ReadOnlyGuidance::wouldModify($this->factory->context(), "Rector ('Safe')", 'rector');
        $printed = $this->factory->output->fetch();

        self::assertStringContainsString("Rector ('Safe'): pending changes in a READ-ONLY run", $printed);
        self::assertStringContainsString("so Rector ('Safe') did NOT modify any", $printed);
        self::assertStringContainsString('QA_READONLY=0 vendor/bin/qa -t rector', $printed);
        self::assertStringContainsString('git add -A && git commit', $printed);
        self::assertStringContainsString('Then push. CI passes because no pending changes remain.', $printed);
    }

    /** The whole block, line for line: its layout is what a reader scanning a failed CI log finds. */
    #[Test]
    public function itPrintsTheWholeRemediationBlock(): void
    {
        ReadOnlyGuidance::wouldModify($this->factory->context(), 'PHP CS Fixer', 'fixer');

        self::assertSame(<<<'BLOCK'


                ==================================================

                    PHP CS Fixer: pending changes in a READ-ONLY run

                --------------------------------------------------

                This run is read-only (qaReadOnly=true), so PHP CS Fixer did NOT modify any
                files. It found changes it WOULD make, which fails the gate. The diff is
                shown above.

                Read-only mode is auto-enabled on GitHub Actions. It is INDEPENDENT of CI /
                interactivity: a Claude Code or local run is non-interactive (so it never
                hangs) but still WRITES, so you can apply fixes there.

                TO FIX -- apply the changes where writes are allowed, then commit them:

                    QA_READONLY=0 vendor/bin/qa -t fixer
                    git add -A && git commit

                Then push. CI passes because no pending changes remain.

                ==================================================


            BLOCK, $this->factory->output->fetch());
    }

    #[Test]
    public function itPrintsTheBlockBetweenRules(): void
    {
        ReadOnlyGuidance::wouldModify($this->factory->context(), 'PHP CS Fixer', 'fixer');
        $printed = $this->factory->output->fetch();

        self::assertSame(2, substr_count($printed, '=================================================='));
        self::assertStringContainsString('-t fixer', $printed);
    }
}
