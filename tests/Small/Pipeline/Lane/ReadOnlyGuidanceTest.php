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
#[UsesClass(ContextFactory::class)]
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

    #[Test]
    public function itPrintsTheBlockBetweenRules(): void
    {
        ReadOnlyGuidance::wouldModify($this->factory->context(), 'PHP CS Fixer', 'fixer');
        $printed = $this->factory->output->fetch();

        self::assertSame(2, substr_count($printed, '=================================================='));
        self::assertStringContainsString('-t fixer', $printed);
    }
}
