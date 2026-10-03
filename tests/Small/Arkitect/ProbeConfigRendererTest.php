<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Arkitect;

use LTS\PHPQA\Arkitect\ProbeConfigRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHPArkitect config the probe hands the phar: the project's own entry
 * config is loaded into a scratch Config, and every rule it registered is
 * re-added against the one probed directory. The rules are the project's; only
 * where they look changes.
 *
 * @internal
 */
#[CoversClass(ProbeConfigRenderer::class)]
#[Small]
final class ProbeConfigRendererTest extends TestCase
{
    private const string ENTRY = "/project/qaConfig/phparkitect's.php";

    private const string DIR = '/project/tests/Fixtures/Arkitect';

    #[Test]
    public function itLoadsTheEntryConfigAndReAddsEveryRuleAgainstTheProbedDirectory(): void
    {
        $config = new ProbeConfigRenderer()->render(self::ENTRY, self::DIR);

        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $config);
        self::assertStringContainsString('(require ' . var_export(self::ENTRY, true) . ')($project);', $config);
        self::assertStringContainsString('$project->getClassSetRules()', $config);
        self::assertStringContainsString('$classSetRules->getRules()', $config);
        self::assertStringContainsString('$config->add(ClassSet::fromDir(' . var_export(self::DIR, true) . '), ...$rules);', $config);
    }

    /**
     * An entry config that registers nothing would make every probe a miss
     * that looks like a verdict, so the generated config refuses to run.
     */
    #[Test]
    public function anEntryConfigThatRegistersNoRuleStopsTheRun(): void
    {
        $config = new ProbeConfigRenderer()->render(self::ENTRY, self::DIR);

        self::assertStringContainsString('if ([] === $rules) {', $config);
        self::assertStringContainsString('throw new RuntimeException(', $config);
    }

    #[Test]
    public function thePathsAreExportedNotInterpolated(): void
    {
        $config = new ProbeConfigRenderer()->render(self::ENTRY, self::DIR);

        self::assertStringContainsString("'/project/qaConfig/phparkitect\\'s.php'", $config);
    }
}
