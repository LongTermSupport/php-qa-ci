<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ExitCodeTextCollector;
use LTS\PHPQA\PHPStan\Rules\ForbidHandFormattedExitCodeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidHandFormattedExitCodeRule>
 */
#[CoversClass(ForbidHandFormattedExitCodeRule::class)]
#[CoversClass(ExitCodeTextCollector::class)]
#[Medium]
final class ForbidHandFormattedExitCodeRuleTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/PHPStan/HandFormattedExitCode/Reports.php';

    #[Test]
    public function anExitCodeFormattedIntoTextOutsideTheResultIsReported(): void
    {
        $message = ForbidHandFormattedExitCodeRule::MESSAGE;

        $this->analyse([self::FIXTURE], [
            [$message, 18],
            [$message, 21],
            [$message, 22],
            [$message, 23],
            [$message, 24],
            [$message, 25],
            [$message, 26],
            [$message, 27],
            [$message, 28],
            [$message, 29],
            [$message, 38],
        ]);
    }

    protected function getRule(): Rule
    {
        return new ForbidHandFormattedExitCodeRule();
    }

    protected function getCollectors(): array
    {
        return [new ExitCodeTextCollector()];
    }
}
