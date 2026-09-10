<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidBinDirToolRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidBinDirToolRule>
 */
#[CoversClass(ForbidBinDirToolRule::class)]
#[Medium]
final class ForbidBinDirToolRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/BinDirTool';

    private const string ADVICE = ' is run from the Composer bin dir. Ship it as a PHAR under vendor-phar/ and run $paths->pharDir instead: a Composer-installed CLI tool drags its dependency graph into every consumer.';

    #[Test]
    public function aToolPathBuiltFromBinDirIsReportedWhetherLiteralConstantOrDynamic(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                ['Tool parallel-lint' . self::ADVICE, 15],
                ['Tool composer-dependency-analyser' . self::ADVICE, 20],
                ['A tool named at run time' . self::ADVICE, 25],
            ],
        );
    }

    #[Test]
    public function phpunitParatestThePharDirAndUnrelatedPropertiesAreNotReported(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidBinDirToolRule();
    }
}
