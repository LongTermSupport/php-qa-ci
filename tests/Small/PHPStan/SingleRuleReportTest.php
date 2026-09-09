<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\SingleRuleReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The report is the mechanism behind `bin/phpstan-rule <identifier> <path>`:
 * given PHPStan's JSON output for a path, say whether ONE rule fired there and
 * where. Everything else PHPStan found is noise for that question.
 *
 * @internal
 */
#[CoversClass(SingleRuleReport::class)]
#[Small]
final class SingleRuleReportTest extends TestCase
{
    private const string PHPQACI_NESTED_TERNARY = 'phpqaci.nestedTernary';

    private const string JSON = <<<'JSON'
        {
          "totals": {"errors": 0, "file_errors": 3},
          "files": {
            "/repo/src/A.php": {
              "errors": 2,
              "messages": [
                {"message": "No nested ternary", "line": 10, "ignorable": true, "identifier": "phpqaci.nestedTernary"},
                {"message": "Something else", "line": 12, "ignorable": true, "identifier": "argument.type"}
              ]
            },
            "/repo/src/B.php": {
              "errors": 1,
              "messages": [
                {"message": "No nested ternary", "line": 3, "ignorable": true, "identifier": "phpqaci.nestedTernary"}
              ]
            }
          },
          "errors": []
        }
        JSON;

    #[Test]
    public function itListsOnlyTheFiringsOfTheRequestedRule(): void
    {
        $firings = SingleRuleReport::fromJson(self::JSON)->firingsOf(self::PHPQACI_NESTED_TERNARY);

        self::assertSame(
            [
                '/repo/src/A.php:10 No nested ternary',
                '/repo/src/B.php:3 No nested ternary',
            ],
            $firings,
        );
    }

    #[Test]
    public function aRuleThatDidNotFireYieldsNothing(): void
    {
        self::assertSame([], SingleRuleReport::fromJson(self::JSON)->firingsOf('phpqaci.emptyCatchBlock'));
    }

    #[Test]
    public function aRunWithNoFilesYieldsNothing(): void
    {
        self::assertSame([], SingleRuleReport::fromJson('{"totals":{"errors":0,"file_errors":0},"files":[],"errors":[]}')->firingsOf(self::PHPQACI_NESTED_TERNARY));
    }

    #[Test]
    public function outputThatIsNotPhpstanJsonIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SingleRuleReport::fromJson('not json');
    }

    #[Test]
    public function renderingSaysFiredWithLocationsOrDidNotFire(): void
    {
        $report = SingleRuleReport::fromJson(self::JSON);

        $fired = $report->render(self::PHPQACI_NESTED_TERNARY);
        self::assertStringStartsWith('phpqaci.nestedTernary FIRED (2)', $fired);
        self::assertStringContainsString('/repo/src/B.php:3', $fired);

        self::assertSame("phpqaci.emptyCatchBlock did not fire\n", $report->render('phpqaci.emptyCatchBlock'));
    }
}
