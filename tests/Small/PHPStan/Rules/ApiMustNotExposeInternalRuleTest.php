<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PackageType\ProjectComposerTypeReader;
use LTS\PHPQA\PHPStan\Rules\ApiMustNotExposeInternalRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ApiMustNotExposeInternalRule>
 */
#[CoversClass(ApiMustNotExposeInternalRule::class)]
#[Medium]
final class ApiMustNotExposeInternalRuleTest extends RuleTestCase
{
    private const string ASSETS = __DIR__ . '/../../../assets/PHPStan/ApiInternalLeak';

    private const string INTERNAL = \LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak\ExposedInternalDto::class;

    private string $projectType = 'library';

    #[Test]
    public function itFlagsAnApiClassReturningAnInternalType(): void
    {
        $this->analyse(
            [self::ASSETS . '/ApiLeaksViaReturn.php'],
            [[$this->leakMessage(
                \LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak\ApiLeaksViaReturn::class,
                'return type of method fetch()',
            ), 10]],
        );
    }

    #[Test]
    public function itFlagsAnApiClassAcceptingAnInternalParameter(): void
    {
        $this->analyse(
            [self::ASSETS . '/ApiLeaksViaParam.php'],
            [[$this->leakMessage(
                \LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak\ApiLeaksViaParam::class,
                'parameter $dto of method consume()',
            ), 10]],
        );
    }

    #[Test]
    public function itAllowsAnApiClassWithAScalarOnlyPublicSurface(): void
    {
        $this->analyse([self::ASSETS . '/ApiCleanSurface.php'], []);
    }

    #[Test]
    public function itNoOpsForANonLibraryProject(): void
    {
        $this->projectType = 'project';

        $this->analyse([self::ASSETS . '/ApiLeaksViaReturn.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ApiMustNotExposeInternalRule(
            new ProjectComposerTypeReader(['type' => $this->projectType]),
            $this->createReflectionProvider(),
        );
    }

    private function leakMessage(string $apiClass, string $where): string
    {
        return \sprintf(
            'Class %s is @api but exposes @internal type %s through its public surface (%s). '
            . 'A consumer using this @api class would be forced to touch an @internal one '
            . '(PHPStan flags that as class.internal/method.internal). Either promote %s to @api '
            . '(commit to it as a public contract) or keep it off the public surface '
            . '(e.g. map it to an @api type at the boundary).',
            $apiClass,
            self::INTERNAL,
            $where,
            self::INTERNAL,
        );
    }
}
