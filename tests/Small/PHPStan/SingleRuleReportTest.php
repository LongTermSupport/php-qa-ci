<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use InvalidArgumentException;
use JsonException;
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

    private const string PHPQACI_EMPTY_CATCH_BLOCK = 'phpqaci.emptyCatchBlock';

    private const string NO_FILES_KEY = 'Expected PHPStan --error-format=json output with a "files" key';

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
        self::assertSame([], SingleRuleReport::fromJson(self::JSON)->firingsOf(self::PHPQACI_EMPTY_CATCH_BLOCK));
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

        foreach ([
            "PHPStan abandoned the analysis on internal errors, so this run cannot say whether the rule fired: Internal error: Unclosed '{' on line 49"                              => $perFile,
            'PHPStan abandoned the analysis on internal errors, so this run cannot say whether the rule fired: Internal error: boom from fixture rule while analysing file /p/A.php' => $topLevel,
        ] as $expected => $json) {
            try {
                SingleRuleReport::fromJson($json);
                self::fail('an abandoned analysis was read as an answer: ' . $expected);
            } catch (InvalidArgumentException $invalidArgumentException) {
                self::assertSame($expected, $invalidArgumentException->getMessage());
            }
        }
    }

    #[Test]
    public function aRuleIsFoundAfterAnotherRulesMessageInTheSameFile(): void
    {
        self::assertSame(['/repo/src/A.php:12 Something else'], SingleRuleReport::fromJson(self::JSON)->firingsOf('argument.type'));
    }

    #[Test]
    public function aDocumentThatIsNotAnObjectIsRefused(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(self::NO_FILES_KEY));

        SingleRuleReport::fromJson('"just a string"');
    }

    #[Test]
    public function aFilesValueThatIsNotAnObjectIsRefused(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(self::NO_FILES_KEY));

        SingleRuleReport::fromJson('{"files": "nope", "errors": []}');
    }

    #[Test]
    public function aMissingFilesKeyIsRefused(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(self::NO_FILES_KEY));

        SingleRuleReport::fromJson('{"errors": []}');
    }

    #[Test]
    public function aGeneralFindingIsStillAnAnswer(): void
    {
        $json = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Ignored error pattern #x# was not matched in reported errors."]}';

        self::assertSame([], SingleRuleReport::fromJson($json)->firingsOf(self::PHPQACI_NESTED_TERNARY));
    }

    #[Test]
    public function outputThatIsNotPhpstanJsonIsAnErrorThatKeepsTheParseFailure(): void
    {
        try {
            SingleRuleReport::fromJson('not json');
            self::fail('output that is not JSON was read as an answer');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertSame('Expected PHPStan --error-format=json output: Syntax error', $invalidArgumentException->getMessage());
            self::assertSame(0, $invalidArgumentException->getCode());
            self::assertInstanceOf(JsonException::class, $invalidArgumentException->getPrevious());
        }
    }

    #[Test]
    public function renderingSaysFiredWithLocationsOrDidNotFire(): void
    {
        $report = SingleRuleReport::fromJson(self::JSON);

        $fired = $report->render(self::PHPQACI_NESTED_TERNARY);
        self::assertStringStartsWith('phpqaci.nestedTernary FIRED (2)', $fired);
        self::assertStringContainsString('/repo/src/B.php:3', $fired);

        self::assertSame("phpqaci.emptyCatchBlock did not fire\n", $report->render(self::PHPQACI_EMPTY_CATCH_BLOCK));
    }

    /**
     * A parallel worker that dies (a segfault, an OOM kill, an exit) leaves only a top-level
     * "Child process error" in the JSON, which reads like any general finding. The PHPStan lane
     * still knows the run reached no verdict, and says so on the qa stderr the harness keeps.
     */
    #[Test]
    public function aRunTheLaneReportedAsNoVerdictIsNotAnAnswer(): void
    {
        $json   = '{"totals":{"errors":1,"file_errors":0},"files":{},"errors":["Child process error (exit code 3):  while running parallel worker"]}';
        $qaLog  = "Running Single Tool: phpstan\n" . PhpstanCrash::noVerdictLine(PhpstanCrash::INCOMPLETE_REASON) . "\n";
        $stderr = $this->stream();

        $this->expectOutputString('');
        self::assertSame(2, SingleRuleReport::main(self::PHPQACI_NESTED_TERNARY, $json, $stderr, $qaLog));
        self::assertSame(
            "PHPStan abandoned the analysis on internal errors (result incomplete), so this run cannot say whether the rule fired\n",
            $this->written($stderr),
        );
    }

    #[Test]
    public function aRunTheLaneReportedAsAVerdictIsAnswered(): void
    {
        $stderr = $this->stream();

        $this->expectOutputString("phpqaci.emptyCatchBlock did not fire\n");
        self::assertSame(0, SingleRuleReport::main(self::PHPQACI_EMPTY_CATCH_BLOCK, self::JSON, $stderr, "Running Single Tool: phpstan\n"));
        self::assertSame('', $this->written($stderr));
    }

    #[Test]
    public function aRuleThatFiredExitsOne(): void
    {
        $stderr = $this->stream();

        $this->expectOutputString("phpqaci.nestedTernary FIRED (2)\n/repo/src/A.php:10 No nested ternary\n/repo/src/B.php:3 No nested ternary\n");
        self::assertSame(1, SingleRuleReport::main(self::PHPQACI_NESTED_TERNARY, self::JSON, $stderr));
        self::assertSame('', $this->written($stderr));
    }

    #[Test]
    public function inputThatIsNotPhpstanJsonIsRefusedOnStderr(): void
    {
        $stderr = $this->stream();

        $this->expectOutputString('');
        self::assertSame(2, SingleRuleReport::main(self::PHPQACI_NESTED_TERNARY, 'not json', $stderr));
        self::assertSame("Expected PHPStan --error-format=json output: Syntax error\n", $this->written($stderr));
    }

    /** @return resource */
    private function stream()
    {
        return \Safe\fopen('php://memory', 'w+');
    }

    /** @param resource $stream */
    private function written($stream): string
    {
        \Safe\rewind($stream);

        return \Safe\stream_get_contents($stream);
    }
}
