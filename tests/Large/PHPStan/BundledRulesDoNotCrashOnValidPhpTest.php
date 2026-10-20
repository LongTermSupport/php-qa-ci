<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\PHPStan;

use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Tests\Support\PhpstanJsonRunFailure;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Safe\Exceptions\JsonException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Defence for the class "a bundled PHPStan rule crashes PHPStan on valid PHP".
 *
 * A rule that throws turns its finding into an internal error: PHPStan abandons
 * the file, every other rule's result for it is lost, and a consumer's whole
 * phpstan lane fails on code that is correct. A unit test of one rule runs the
 * shapes its author thought of; this runs every rule and collector a consumer
 * can include (rules-optional-symfony.neon includes rules-optional.neon) through
 * the shipped phar over the parser shapes most often missed, first-class
 * callables above all, whose getArgs() is not a list of arguments.
 *
 * The fixture is analysed as a library's src/ and, under a Tests namespace, as
 * its tests/, so rules that skip test code, and rules that only judge it, both
 * run on it. Findings are expected and ignored; an internal error fails.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class BundledRulesDoNotCrashOnValidPhpTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string FIXTURE = self::REPO_ROOT . '/tests/assets/PHPStan/BundledRulesOnValidPhp/ValidPhp.php';

    private const string NAMESPACE = 'BundledRulesOnValidPhp';

    /** @var list<string> every bundled rule set; the Symfony one includes rules-optional.neon */
    private const array BUNDLED_RULE_SETS = [
        'rules-default.neon',
        'rules-optional-symfony.neon',
    ];

    /** @var list<string> one identifier from each of rules-default.neon and rules-optional.neon */
    private const array PROOF_THE_RULES_RAN = [
        'phpqaci.emptyLanguageConstruct',
        'phpqaci.nullCoalescingEmptyString',
    ];

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('bundled-rules-valid-php');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function everyBundledRuleAnalysesValidPhpWithoutAnInternalError(): void
    {
        $source = \Safe\file_get_contents(self::FIXTURE);
        $this->project->write('src/ValidPhp.php', $source);
        $this->project->write('tests/ValidPhp.php', str_replace(
            'namespace ' . self::NAMESPACE . ';',
            'namespace ' . self::NAMESPACE . '\Tests;',
            $source,
        ));
        $this->project->write('composer.json', \Safe\json_encode([
            'name'         => 'fixture/bundled-rules-valid-php',
            'type'         => 'library',
            'autoload'     => ['psr-4' => [self::NAMESPACE . '\\' => 'src/']],
            'autoload-dev' => ['psr-4' => [self::NAMESPACE . '\Tests\\' => 'tests/']],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        $neon = $this->project->write('phpstan.neon', $this->neon());

        $result = $this->analyse($neon);

        self::assertNull(PhpstanJsonRunFailure::reason($result), $this->internalErrors($result));
        foreach (self::PROOF_THE_RULES_RAN as $identifier) {
            self::assertStringContainsString('"' . $identifier . '"', $result->stdout, 'the bundled rule sets were not loaded: ' . $result->output);
        }
    }

    private function neon(): string
    {
        $includes = '';
        foreach (self::BUNDLED_RULE_SETS as $ruleSet) {
            $includes .= '    - ' . \Safe\realpath(self::REPO_ROOT . '/' . $ruleSet) . "\n";
        }

        return "includes:\n" . $includes
            . "parameters:\n    level: max\n    paths:\n        - src\n        - tests\n"
            . '    tmpDir: ' . $this->project->path . "/cache\n";
    }

    private function analyse(string $neon): ProcessResultDto
    {
        $php = new PhpInvoker(new SymfonyProcessRunner(new BufferedOutput()), \PHP_BINARY, '4G', self::REPO_ROOT . '/var/qa');

        return $php->withoutXdebug(
            self::REPO_ROOT . '/vendor-phar/phpstan.phar',
            ['analyse', '-c', $neon, '--no-progress', '--error-format=json', '-a', self::REPO_ROOT . '/vendor/autoload.php'],
            $this->project->path,
            ['TMPDIR' => $this->project->path],
            streamOutput: false,
        );
    }

    /**
     * The internal errors PHPStan reported, one per line, for the failure message.
     */
    private function internalErrors(ProcessResultDto $result): string
    {
        try {
            $report = \Safe\json_decode($result->stdout, true);
        } catch (JsonException $jsonException) {
            return $result->output . "\n" . $jsonException->getMessage();
        }

        if (!\is_array($report) || !\is_array($report['errors'] ?? null)) {
            return $result->output;
        }

        return 'A bundled rule crashed PHPStan on valid PHP (tests/assets/PHPStan/BundledRulesOnValidPhp/ValidPhp.php):' . "\n"
            . implode("\n", array_map(strval(...), array_filter($report['errors'], \is_string(...))));
    }
}
