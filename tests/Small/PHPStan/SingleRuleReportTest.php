<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\SingleRuleReport;
use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The report is the mechanism behind `bin/phpstan-rule <identifier> <path>`:
 * given PHPStan's JSON output for a path, say whether ONE rule fired there and
 * where. Everything else PHPStan found is noise for that question.
 *
 * @internal
 */
#[CoversClass(SingleRuleReport::class)]
#[UsesClass(PhpstanCrash::class)]
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

    /**
     * PHPStan drops every finding when it abandons an analysis on internal errors, so "did not
     * fire" would be a false answer: the harness exits 2 instead. The shipped phar reports those
     * errors either per file, identified `phpstan.internal`, or in the top-level list.
     */
    #[Test]
    public function anAbandonedAnalysisIsNotAnAnswer(): void
    {
        $perFile  = '{"totals":{"errors":0,"file_errors":1},"files":{"/p/A.php":{"errors":1,"messages":[{"message":"Internal error: Unclosed \'{\' on line 49","line":null,"ignorable":false,"identifier":"phpstan.internal"}]}},"errors":[]}';
        $topLevel = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Internal error: boom from fixture rule while analysing file /p/A.php"]}';

        foreach (['per file' => $perFile, 'top level' => $topLevel] as $shape => $json) {
            try {
                SingleRuleReport::fromJson($json);
                self::fail('an abandoned analysis was read as an answer: ' . $shape);
            } catch (InvalidArgumentException $invalidArgumentException) {
                self::assertStringContainsString('abandoned the analysis', $invalidArgumentException->getMessage(), $shape);
            }
        }
    }

    #[Test]
    public function aGeneralFindingIsStillAnAnswer(): void
    {
        $json = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Ignored error pattern #x# was not matched in reported errors."]}';

        self::assertSame([], SingleRuleReport::fromJson($json)->firingsOf(self::PHPQACI_NESTED_TERNARY));
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

    /**
     * A parallel worker that dies (a segfault, an OOM kill, an exit) leaves only a top-level
     * "Child process error" in the JSON, which reads like any general finding. The PHPStan lane
     * still knows the run reached no verdict, and says so on the qa stderr the harness keeps.
     */
    #[Test]
    public function aRunTheLaneReportedAsNoVerdictIsNotAnAnswer(): void
    {
        $json  = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Child process error (exit code 3):  while running parallel worker"]}';
        $qaLog = "Running Single Tool: phpstan\n" . PhpstanCrash::noVerdictLine(PhpstanCrash::INCOMPLETE_REASON) . "\n";

        $this->expectOutputString('');
        self::assertSame(2, SingleRuleReport::main(self::PHPQACI_NESTED_TERNARY, $json, $qaLog));
    }

    #[Test]
    public function aRunTheLaneReportedAsAVerdictIsAnswered(): void
    {
        $this->expectOutputString("phpqaci.emptyCatchBlock did not fire\n");
        self::assertSame(0, SingleRuleReport::main('phpqaci.emptyCatchBlock', self::JSON, "Running Single Tool: phpstan\n"));
    }
}
