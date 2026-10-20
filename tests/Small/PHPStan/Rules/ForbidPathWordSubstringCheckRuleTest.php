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

    private const string SHOP = 'shop';

    #[Test]
    public function everyWordSearchOfAKnownAbsolutePathIsReported(): void
    {
        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$this->message('stripos', '$absPathRoot', self::MAGENTO), 25],
                [$this->message(self::STR_CONTAINS, '$this->projectRoot', 'drupal'), 30],
                [$this->message(self::STR_CONTAINS, '$absoluteFilePath', self::MAGENTO), 35],
                [$this->message(self::STR_CONTAINS, '__DIR__', 'vendor'), 40],
                [$this->message('strpos', '(string) getcwd()', 'magento'), 41],
                [$this->message('stristr', "\\Safe\\realpath('.')", 'Symfony'), 42],
                [$this->message(self::STR_CONTAINS, "__FILE__ . '/x'", 'Laravel'), 43],
                [$this->message(self::STR_CONTAINS, '\dirname(__DIR__)', 'wordpress'), 44],
                [$this->message(self::STR_CONTAINS, '(string) $info->getRealPath()', 'generated'), 49],
                [$this->message(self::STR_CONTAINS, '$info->getPathname()', 'Generated'), 50],
                [$this->message(self::STR_CONTAINS, '(string) $class->getFileName()', 'legacy'), 51],
                [$this->message('strrpos', '$x', self::SHOP), 58],
                [$this->message(self::STR_CONTAINS, "\$this->projectRoot . '/' . \$relative", self::MAGENTO), 63],
                [$this->message(self::STR_CONTAINS, '$rootDir', self::MAGENTO), 68],
                [$this->message(self::STR_CONTAINS, '"{$this->projectRoot}/app"', self::MAGENTO), 73],
                [$this->message(self::STR_CONTAINS, '(string) $info?->getPathname()', 'generated'), 78],
                [$this->message(self::STR_CONTAINS, '(string) $other?->projectRoot', 'drupal'), 79],
                [$this->message(self::STR_CONTAINS, 'self::$cacheDir', 'phpstan'), 84],
                [$this->message(self::STR_CONTAINS, '__DIR__', 'vendor'), 89],
                [$this->message(self::STR_CONTAINS, '$x', self::SHOP), 97],
                [$this->message(self::STR_CONTAINS, '$x', self::SHOP), 104],
                [$this->message(self::STR_CONTAINS, '$x', self::SHOP), 111],
                [$this->message(self::STR_CONTAINS, '$x', self::SHOP), 120],
                [$this->message(self::STR_CONTAINS, '$realPath', self::SHOP), 125],
                [$this->message(self::STR_CONTAINS, '$fullPath', self::SHOP), 126],
                [$this->message(self::STR_CONTAINS, '$workDir', self::SHOP), 127],
                [$this->message(self::STR_CONTAINS, '$imageFolder', self::SHOP), 128],
                [$this->message(self::STR_CONTAINS, '$cwd', self::SHOP), 129],
                [$this->message(self::STR_CONTAINS, '$projectDir', 'magento'), 136],
                [$this->message(self::STR_CONTAINS, '$cacheDir', self::SHOP), 141],
            ],
        );
    }

    #[Test]
    public function textHaystacksNonWordNeedlesUnqualifiedNamesAndRetypedLocalsAreClean(): void
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
