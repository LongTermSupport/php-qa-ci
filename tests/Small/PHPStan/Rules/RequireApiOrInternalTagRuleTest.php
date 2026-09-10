<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PackageType\DevAutoloadNamespaceReader;
use LTS\PHPQA\PackageType\ProjectComposerTypeReader;
use LTS\PHPQA\PHPStan\Rules\ApiOrInternalTagDetector;
use LTS\PHPQA\PHPStan\Rules\RequireApiOrInternalTagRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<RequireApiOrInternalTagRule>
 */
#[CoversClass(RequireApiOrInternalTagRule::class)]
#[Medium]
final class RequireApiOrInternalTagRuleTest extends RuleTestCase
{
    private const string API_OR_INTERNAL_UNCLASSIFIED_PHP = '/../../../assets/PHPStan/ApiOrInternal/Unclassified.php';

    private const string MISSING_MESSAGE =
        'Class LTS\PHPQA\Tests\Assets\PHPStan\ApiOrInternal\Unclassified is part of a library\'s '
        . 'public surface but is not classified. Add exactly one of @api (a supported public '
        . 'contract — changing it later breaks consumers) or @internal (not for consumers) to its '
        . 'docblock. Safe default: @internal; promote to @api only what consumers genuinely need '
        . 'and the library will support long-term.';

    private const string BOTH_MESSAGE =
        'Class LTS\PHPQA\Tests\Assets\PHPStan\ApiOrInternal\Contradictory declares BOTH @api and '
        . '@internal, which is contradictory. Choose exactly one: @api (a supported public '
        . 'contract) or @internal (not for consumers).';

    private string $projectType = 'library';

    private string $enforceMode = 'auto';

    /** @var list<string> */
    private array $ignoredNamespacePrefixes = [];

    /** @var array<int|string, mixed> decoded composer.json for the dev-namespace reader */
    private array $composerJson = [];

    #[Test]
    public function itFlagsAnUnclassifiedClassInALibrary(): void
    {
        $this->analyse(
            [__DIR__ . self::API_OR_INTERNAL_UNCLASSIFIED_PHP],
            [[self::MISSING_MESSAGE, 7]],
        );
    }

    #[Test]
    public function itFlagsAClassDeclaringBothTags(): void
    {
        $this->analyse(
            [__DIR__ . '/../../../assets/PHPStan/ApiOrInternal/Contradictory.php'],
            [[self::BOTH_MESSAGE, 12]],
        );
    }

    #[Test]
    public function itAcceptsClassLikesClassifiedWithExactlyOneTag(): void
    {
        // A class (@api), an interface (@internal) and an enum (@api) — every
        // class-like kind InClassNode fires for, each classified, no errors.
        $this->analyse(
            [
                __DIR__ . '/../../../assets/PHPStan/ApiOrInternal/ApiClassified.php',
                __DIR__ . '/../../../assets/PHPStan/ApiOrInternal/InternalContract.php',
                __DIR__ . '/../../../assets/PHPStan/ApiOrInternal/ClassifiedEnum.php',
            ],
            [],
        );
    }

    #[Test]
    public function itDoesNotEnforceForANonLibraryProject(): void
    {
        $this->projectType = 'project';

        $this->analyse(
            [__DIR__ . self::API_OR_INTERNAL_UNCLASSIFIED_PHP],
            [],
        );
    }

    #[Test]
    public function itEnforcesForANonLibraryTypeWhenTheOverrideForcesItOn(): void
    {
        // A composer-plugin IS a form of library: with enforce=always it must still
        // classify its public surface, even though its composer `type` is not `library`.
        // This is the motivating opt-in (ballicom/ballicom-sql: a self-deploying
        // composer-plugin that still ships a public API).
        $this->projectType = 'composer-plugin';
        $this->enforceMode = 'always';

        $this->analyse(
            [__DIR__ . self::API_OR_INTERNAL_UNCLASSIFIED_PHP],
            [[self::MISSING_MESSAGE, 7]],
        );
    }

    #[Test]
    public function itDoesNotEnforceForALibraryWhenTheOverrideForcesItOff(): void
    {
        // enforce=never forces the rule off even for a library (the escape hatch).
        $this->projectType = 'library';
        $this->enforceMode = 'never';

        $this->analyse(
            [__DIR__ . self::API_OR_INTERNAL_UNCLASSIFIED_PHP],
            [],
        );
    }

    #[Test]
    public function itRespectsIgnoredNamespacePrefixes(): void
    {
        $this->ignoredNamespacePrefixes = ['LTS\PHPQA\Tests\Assets'];

        $this->analyse(
            [__DIR__ . self::API_OR_INTERNAL_UNCLASSIFIED_PHP],
            [],
        );
    }

    #[Test]
    public function itSkipsClassLikesInAnAutoloadDevNamespace(): void
    {
        // The fixture lives under LTS\PHPQA\Tests\Assets\…; declaring that prefix
        // as autoload-dev means the rule treats it as non-shipped dev code and
        // does not require it to be classified — no hand-tagging of test suites.
        $this->composerJson = [
            'autoload-dev' => ['psr-4' => ['LTS\PHPQA\Tests\\' => 'tests/']],
        ];

        $this->analyse(
            [__DIR__ . self::API_OR_INTERNAL_UNCLASSIFIED_PHP],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new RequireApiOrInternalTagRule(
            new ProjectComposerTypeReader(['type' => $this->projectType], $this->enforceMode),
            new ApiOrInternalTagDetector(),
            new DevAutoloadNamespaceReader($this->composerJson),
            $this->ignoredNamespacePrefixes,
        );
    }
}
