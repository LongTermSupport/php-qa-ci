<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireReadonlyServiceRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * The excluded-interface check reads the class's real ClassReflection, which is
 * final and cannot be doubled, so it is pinned by analysing a fixture.
 *
 * @internal
 *
 * @extends RuleTestCase<RequireReadonlyServiceRule>
 */
#[CoversClass(RequireReadonlyServiceRule::class)]
#[Medium]
final class RequireReadonlyServiceRuleAnalysisTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/PHPStan/ReadonlyService/Services.php';

    /** The fixture is loaded first so the reflection provider knows its classes, as a project's autoloaded ones are. */
    #[Test]
    public function aClassImplementingAnExcludedInterfaceIsNotFlaggedAndAnyOtherInterfaceChangesNothing(): void
    {
        require_once self::FIXTURE;

        $this->analyse(
            [self::FIXTURE],
            [
                [$this->message('Mailer'), 9],
                [$this->message('Clock'), 23],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new RequireReadonlyServiceRule(self::createReflectionProvider());
    }

    private function message(string $class): string
    {
        return 'Service class ' . $class . ' should be "final readonly class". Mutable services cause hidden state bugs. If per-call state is needed, extract it into a context DTO.';
    }
}
