<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\RequireDeclareStrictTypesRule;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * Analyses real fixture files so the FileNode is produced by PHPStan itself
 * (constructing FileNode by hand is outside PHPStan's BC promise). The fixtures
 * live under tests/assets/ — one of them deliberately omits strict_types — where
 * the pipeline's own strict-types/psr4/phpstan gates ignore them.
 *
 * @internal
 *
 * @extends RuleTestCase<RequireDeclareStrictTypesRule>
 */
#[CoversClass(RequireDeclareStrictTypesRule::class)]
#[Medium]
final class RequireDeclareStrictTypesRuleTest extends RuleTestCase
{
    private const string MISSING_MESSAGE =
        'Missing declare(strict_types=1) at the top of the file. All PHP files must declare strict types.';

    #[Test]
    public function getNodeTypeIsFileNode(): void
    {
        self::assertSame(FileNode::class, $this->getRule()->getNodeType());
    }

    #[Test]
    public function fileDeclaringStrictTypesIsNotFlagged(): void
    {
        $this->analyse([__DIR__ . '/../../../assets/PHPStan/StrictTypes/HasStrictTypes.php'], []);
    }

    #[Test]
    public function fileMissingStrictTypesIsFlagged(): void
    {
        $this->analyse(
            [__DIR__ . '/../../../assets/PHPStan/StrictTypes/MissingStrictTypes.php'],
            [[self::MISSING_MESSAGE, 3]],
        );
    }

    #[Test]
    public function fileDeclaringADifferentDirectiveIsStillFlagged(): void
    {
        $this->analyse(
            [__DIR__ . '/../../../assets/PHPStan/StrictTypes/DeclaresTicksOnly.php'],
            [[self::MISSING_MESSAGE, 3]],
        );
    }

    protected function getRule(): Rule
    {
        return new RequireDeclareStrictTypesRule();
    }
}
