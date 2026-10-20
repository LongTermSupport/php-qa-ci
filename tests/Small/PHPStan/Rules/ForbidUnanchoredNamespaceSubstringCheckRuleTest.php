<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ClassNameVariableWriteCollector;
use LTS\PHPQA\PHPStan\Rules\ForbidUnanchoredNamespaceSubstringCheckRule;
use LTS\PHPQA\PHPStan\Rules\NamespaceWordSubstringCallCollector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidUnanchoredNamespaceSubstringCheckRule>
 */
#[CoversClass(ForbidUnanchoredNamespaceSubstringCheckRule::class)]
#[CoversClass(NamespaceWordSubstringCallCollector::class)]
#[CoversClass(ClassNameVariableWriteCollector::class)]
#[Medium]
final class ForbidUnanchoredNamespaceSubstringCheckRuleTest extends RuleTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../assets/PHPStan/UnanchoredNamespaceSubstringCheck';

    private const string STR_CONTAINS = 'str_contains';

    private const string GENERATED = 'Generated';

    private const string MIGRATIONS = 'Migrations';

    #[Test]
    public function aBareWordSearchedForAnywhereInAClassNameIsReported(): void
    {
        require_once self::FIXTURES . '/Reportable.php';

        $this->analyse(
            [self::FIXTURES . '/Reportable.php'],
            [
                [$this->message(self::STR_CONTAINS, self::GENERATED, self::MIGRATIONS, 'PHPStan'), 20],
                [$this->message(self::STR_CONTAINS, self::GENERATED), 30],
                [$this->message('strpos', self::MIGRATIONS), 35],
                [$this->message('stripos', 'PHPStan'), 41],
                [$this->message(self::STR_CONTAINS, self::GENERATED), 46],
                [$this->message(self::STR_CONTAINS, self::MIGRATIONS), 51],
                [$this->message(self::STR_CONTAINS, self::GENERATED), 58],
            ],
        );
    }

    #[Test]
    public function wholeSegmentsAnchoredChecksAndStringsThatAreNotClassNamesAreNotReported(): void
    {
        require_once self::FIXTURES . '/NotReportable.php';

        $this->analyse([self::FIXTURES . '/NotReportable.php'], []);
    }

    #[Test]
    public function theMessageNamesTheWordAndTheCorrectConstruction(): void
    {
        self::assertSame(
            "str_contains() looks for 'Generated' anywhere in a class or namespace name, so it also matches a name that "
            . "merely contains it, such as App\\GeneratedReports. Compare whole namespace segments: in_array('Generated', "
            . "explode('\\\\', \$name), true). See docs/phpstan-rules/forbid-unanchored-namespace-substring-check.md",
            $this->message(self::STR_CONTAINS, self::GENERATED),
        );
    }

    protected function getRule(): Rule
    {
        return new ForbidUnanchoredNamespaceSubstringCheckRule();
    }

    protected function getCollectors(): array
    {
        return [
            new NamespaceWordSubstringCallCollector(),
            new ClassNameVariableWriteCollector(),
        ];
    }

    private function message(string $function, string $word, string ...$more): string
    {
        return \sprintf(
            "%s() looks for '%s' anywhere in a class or namespace name, so it also matches a name that merely contains it, "
            . "such as App\\%sReports. Compare whole namespace segments: in_array('%s', explode('\\\\', \$name), true). "
            . 'See docs/phpstan-rules/forbid-unanchored-namespace-substring-check.md',
            $function,
            implode("', '", [$word, ...$more]),
            $word,
            $word,
        );
    }
}
