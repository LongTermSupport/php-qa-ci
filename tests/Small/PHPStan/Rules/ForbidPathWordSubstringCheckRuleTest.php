<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\AbsolutePathExpression;
use LTS\PHPQA\PHPStan\Rules\ForbidPathWordSubstringCheckRule;
use LTS\PHPQA\PHPStan\Rules\PathWordSubstringCheckCollector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidPathWordSubstringCheckRule>
 */
#[CoversClass(ForbidPathWordSubstringCheckRule::class)]
#[CoversClass(PathWordSubstringCheckCollector::class)]
#[CoversClass(AbsolutePathExpression::class)]
#[Medium]
final class ForbidPathWordSubstringCheckRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/PathWordSubstringCheck';

    private const string STR_CONTAINS = 'str_contains';

    private const string MAGENTO = 'Magento';

    #[Test]
    public function everyWordSearchOfAKnownAbsolutePathIsReported(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$this->message('stripos', '$absPathRoot', self::MAGENTO), 23],
                [$this->message(self::STR_CONTAINS, '$this->projectRoot', 'drupal'), 28],
                [$this->message(self::STR_CONTAINS, '$filePath', self::MAGENTO), 33],
                [$this->message(self::STR_CONTAINS, '__DIR__', 'vendor'), 38],
                [$this->message('strpos', '(string) getcwd()', 'magento'), 39],
                [$this->message('stristr', "\\Safe\\realpath('.')", 'Symfony'), 40],
                [$this->message(self::STR_CONTAINS, "__FILE__ . '/x'", 'Laravel'), 41],
                [$this->message(self::STR_CONTAINS, '\dirname(__DIR__)', 'wordpress'), 42],
                [$this->message(self::STR_CONTAINS, '(string) $info->getRealPath()', 'generated'), 47],
                [$this->message(self::STR_CONTAINS, '$info->getPathname()', 'Generated'), 48],
                [$this->message(self::STR_CONTAINS, '(string) $class->getFileName()', 'legacy'), 49],
                [$this->message('strrpos', '$x', 'shop'), 56],
                [$this->message(self::STR_CONTAINS, "\$this->projectRoot . '/' . \$relative", self::MAGENTO), 61],
                [$this->message(self::STR_CONTAINS, '$rootDir', self::MAGENTO), 66],
            ],
        );
    }

    #[Test]
    public function textHaystacksNonWordNeedlesAndRetypedLocalsAreClean(): void
    {
        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidPathWordSubstringCheckRule();
    }

    protected function getCollectors(): array
    {
        return [new PathWordSubstringCheckCollector()];
    }

    private function message(string $function, string $haystack, string $word): string
    {
        return \sprintf(
            "Word searched for in an absolute path: %s(%s, '%s'). It holds wherever any directory above the "
            . 'project carries the word, and fails for the same project checked out anywhere else. Decide from what '
            . 'the project is (its composer.json, a detector), or compare a path segment below the project root. '
            . 'See docs/phpstan-rules/forbid-path-word-substring-check.md',
            $function,
            $haystack,
            $word,
        );
    }
}
