<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\SingleRuleReport;
use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Defence for the class "an exit 1 from PHPStan read as findings without evidence of findings".
 *
 * PHPStan exits 1 for findings, for an analysis it abandoned on internal errors, and for a config
 * error before any analysis. PhpstanCrash and SingleRuleReport tell them apart by what the phar
 * prints, so this runs the shipped phar on each case, invoked as the lanes invoke it. A phar
 * update that changes those lines fails here rather than misreporting runs in the pipeline.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpstanOutcomesFromTheShippedPharTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string FIXTURE = self::REPO_ROOT . '/tests/assets/phpstanOutcomes';

    private const string JSON_FORMAT = '--error-format=json';

    private TempDir $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('phpstan-outcomes');
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    #[Test]
    public function findingsAreAVerdictInBothFormats(): void
    {
        $neon = $this->neon('findings.neon', '');

        $text = $this->analyse($neon);
        self::assertSame(1, $text->exitCode, $text->output);
        self::assertNull(PhpstanCrash::reason($text), $text->output);

        $json = $this->analyse($neon, self::JSON_FORMAT);
        self::assertSame(1, $json->exitCode, $json->output);
        self::assertNull(PhpstanCrash::jsonReason($json), $json->output);
        self::assertSame([], SingleRuleReport::fromJson($json->stdout)->firingsOf('phpqaci.nestedTernary'));
    }

    #[Test]
    public function aConfigErrorIsACrashInBothFormats(): void
    {
        $neon = $this->neon('config-error.neon', "    notARealParameter: true\n");

        $text = $this->analyse($neon);
        self::assertSame(1, $text->exitCode, $text->output);
        self::assertSame(PhpstanCrash::NO_REPORT_REASON, PhpstanCrash::reason($text), $text->output);

        $json = $this->analyse($neon, self::JSON_FORMAT);
        self::assertSame(1, $json->exitCode, $json->output);
        self::assertSame(PhpstanCrash::NO_REPORT_REASON, PhpstanCrash::jsonReason($json), $json->output);
    }

    #[Test]
    public function anAbandonedAnalysisIsACrashInBothFormatsAndNotAnAnswer(): void
    {
        $neon = $this->neon('internal-error.neon', '', "rules:\n    - PhpstanOutcomes\\ThrowingRule\n");
        $rule = ['-a', self::FIXTURE . '/ThrowingRule.php'];

        $text = $this->analyse($neon, ...$rule);
        self::assertSame(1, $text->exitCode, $text->output);
        self::assertSame(PhpstanCrash::INCOMPLETE_REASON, PhpstanCrash::reason($text), $text->output);

        $json = $this->analyse($neon, self::JSON_FORMAT, ...$rule);
        self::assertSame(1, $json->exitCode, $json->output);
        self::assertSame(PhpstanCrash::INCOMPLETE_REASON, PhpstanCrash::jsonReason($json), $json->output);

        $this->expectException(InvalidArgumentException::class);
        SingleRuleReport::fromJson($json->stdout);
    }

    /** Past its budget of 1000 the table says "Found 1000+ errors", which is still a findings run. */
    #[Test]
    public function moreFindingsThanTheTableShowsAreAVerdict(): void
    {
        $src  = \dirname($this->tmp->write('many/Many.php', "<?php\n\nfunction phpstan_outcomes_many(): void\n{\n" . str_repeat("    phpstan_outcomes_no_such_function();\n", 1001) . "}\n"));
        $neon = $this->neon('many.neon', '', '', $src);

        $text = $this->analyse($neon, PhpstanCrash::TABLE_FORMAT);
        self::assertSame(1, $text->exitCode, $text->output);
        self::assertStringContainsString('Found 1000+ errors', $text->output, 'the shape under test');
        self::assertNull(PhpstanCrash::reason($text), $text->output);
    }

    /**
     * A project's parameters.errorFormat replaces the table, and its summary line with it, unless
     * the format is named on the command line, as the table-reading lanes name it.
     */
    #[Test]
    public function aProjectErrorFormatCannotHideTheVerdictFromTheLanes(): void
    {
        $neon = $this->neon('raw.neon', "    errorFormat: raw\n");

        $unnamed = $this->analyse($neon);
        self::assertSame(1, $unnamed->exitCode, $unnamed->output);
        self::assertSame(PhpstanCrash::NO_REPORT_REASON, PhpstanCrash::reason($unnamed), 'the hazard: without the format named, there is no summary line');

        $named = $this->analyse($neon, PhpstanCrash::TABLE_FORMAT);
        self::assertSame(1, $named->exitCode, $named->output);
        self::assertNull(PhpstanCrash::reason($named), $named->output);
    }

    /**
     * A worker that dies leaves a top-level "Child process error" with no "Internal error" prefix,
     * so the JSON alone reads like a general finding. The run is still an abandoned analysis, and
     * the lane says so on the line bin/phpstan-rule reads.
     */
    #[Test]
    public function aDeadWorkerIsAnAbandonedAnalysis(): void
    {
        $neon = $this->neon('exiting.neon', '', "rules:\n    - PhpstanOutcomes\\ExitingRule\n");

        $json = $this->analyse($neon, self::JSON_FORMAT, '-a', self::FIXTURE . '/ExitingRule.php');
        self::assertSame(1, $json->exitCode, $json->output);
        self::assertStringContainsString('Child process', $json->stdout, 'the shape under test');
        self::assertSame(PhpstanCrash::INCOMPLETE_REASON, PhpstanCrash::jsonReason($json), $json->output);
    }

    private function neon(string $name, string $extraParameters, string $extra = '', string $src = self::FIXTURE . '/src'): string
    {
        return $this->tmp->write($name, \sprintf(
            "parameters:\n    level: 0\n    paths:\n        - %s\n    tmpDir: %s/cache\n%s%s",
            $src,
            $this->tmp->path,
            $extraParameters,
            $extra,
        ));
    }

    private function analyse(string $neon, string ...$extraArgs): ProcessResultDto
    {
        $php = new PhpInvoker(new SymfonyProcessRunner(new BufferedOutput()), \PHP_BINARY, '4G', self::REPO_ROOT . '/var/qa');

        return $php->withoutXdebug(
            self::REPO_ROOT . '/vendor-phar/phpstan.phar',
            array_values(['analyse', '-c', $neon, '--no-progress', ...$extraArgs]),
            self::FIXTURE,
            ['TMPDIR' => $this->tmp->path],
            streamOutput: false,
        );
    }
}
