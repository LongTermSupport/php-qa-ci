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
    private const string API_LEAKS_VIA_RETURN_PHP = '/ApiLeaksViaReturn.php';

    private const string ASSETS = __DIR__ . '/../../../assets/PHPStan/ApiInternalLeak';

    private const string INTERNAL = \LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak\ExposedInternalDto::class;

    private string $projectType = 'library';

    private string $enforceMode = 'auto';

    #[Test]
    public function itFlagsAnApiClassReturningAnInternalType(): void
    {
        $this->analyse(
            [self::ASSETS . self::API_LEAKS_VIA_RETURN_PHP],
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
    public function itSkipsMembersThatAreThemselvesInternal(): void
    {
        // An @api class with an @internal constructor (factory-only) and an
        // @internal mapper that both touch an internal type — not a leak, because
        // those members are not part of the consumer surface.
        $this->analyse([self::ASSETS . '/ApiWithInternalMembers.php'], []);
    }

    #[Test]
    public function itNoOpsForANonLibraryProject(): void
    {
        $this->projectType = 'project';

        $this->analyse([self::ASSETS . self::API_LEAKS_VIA_RETURN_PHP], []);
    }

    #[Test]
    public function itEnforcesForANonLibraryTypeWhenTheOverrideForcesItOn(): void
    {
        // The integrity check honours the same enforce override as its sibling
        // RequireApiOrInternalTagRule: a composer-plugin that opts in with
        // enforce=always must not leak its own @internal types either.
        $this->projectType = 'composer-plugin';
        $this->enforceMode = 'always';

        $this->analyse(
            [self::ASSETS . self::API_LEAKS_VIA_RETURN_PHP],
            [[$this->leakMessage(
                \LTS\PHPQA\Tests\Assets\PHPStan\ApiInternalLeak\ApiLeaksViaReturn::class,
                'return type of method fetch()',
            ), 10]],
        );
    }

    protected function getRule(): Rule
    {
        return new ApiMustNotExposeInternalRule(
            new ProjectComposerTypeReader(['type' => $this->projectType], $this->enforceMode),
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
