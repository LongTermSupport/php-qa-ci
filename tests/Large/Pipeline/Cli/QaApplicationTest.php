<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Cli;

use LTS\PHPQA\Pipeline\Agent\AgentStatusEnum;
use LTS\PHPQA\Pipeline\Cli\QaApplication;
use LTS\PHPQA\Pipeline\Config\Exception\ProjectLayoutException;
use LTS\PHPQA\Pipeline\Lock\RunLock;
use LTS\PHPQA\Pipeline\Runner\Pipeline;
use LTS\PHPQA\Pipeline\Runner\RunInterruptHandler;
use LTS\PHPQA\Tests\Support\FixtureConsumer;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Safe\Exceptions\FilesystemException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

/**
 * QaApplication in-process, over a throwaway consumer project: the real
 * argument parsing, configuration and pipeline, with every stream an in-memory
 * one so each byte of output can be pinned. The consumer's pipeline.php adds a
 * `probe` lane that prints the configuration it was handed, and its PHP binary
 * is a stand-in that answers the start-up probes (Xdebug, JIT, OPcache) from
 * files, so the header's branches are decided by the test rather than by the
 * host.
 *
 * @internal
 */
#[CoversClass(QaApplication::class)]
#[UsesNamespace('LTS\PHPQA\Pipeline')]
#[UsesNamespace('LTS\PHPQA\Changelog')]
#[Large]
final class QaApplicationTest extends TestCase
{
    private const string RULE = '===========================================';

    private const string PROBE = 'probe';

    private const string DASH_T = '-t';

    private const string DASH_P = '-p';

    private const string AGENT_MODE = '--agent-mode';

    private const string XDEBUG_MODE = 'XDEBUG_MODE';

    private const string PATH = 'PATH';

    private const string MEMORY = 'php://memory';

    private const string CPUINFO = '/proc/cpuinfo';

    private const string ANSWER_XDEBUG = 'fakephp/xdebug';

    private const string ANSWER_VERSION = 'fakephp/version';

    private const string ANSWER_JIT = 'fakephp/jit';

    private const string ANSWER_OPCACHE = 'fakephp/opcache';

    private const string CALLS = 'fakephp/calls.log';

    private const string READ_ONLY = 'QA read-only (check) mode: fixers will NOT modify files; a pending change FAILS the run.';

    private const string AGGREGATE = 'QA aggregate mode: all tools run; failures are collected and reported together (not fail-fast).';

    private const string LOCK_LINE = "qa: another QA run holds the lock; nothing was checked (exit 75, not a QA failure).\n";

    private const string PROBE_FAIL = 'probe.fail';

    private const string PROBE_STDOUT = "[probe stdout]\n";

    private const string QA_READONLY = 'QA_READONLY';

    private const string OLD_PHP = '8.4.0';

    private const string NO_JIT = '0||';

    private const string NO_OPCACHE = '0|';

    private const string COMPLETED = 'COMPLETED';

    private const string ENVIRONMENT_DETECTED = 'environment detected';

    private FixtureConsumer $fixture;

    private TempDir $consumer;

    private string $fakePhp;

    private string $hostname;

    private false|string $xdebugMode;

    private false|string $path;

    private string $cwd;

    private string $stdout = '';

    private string $stderr = '';

    protected function setUp(): void
    {
        $this->xdebugMode = getenv(self::XDEBUG_MODE);
        $this->cwd        = \Safe\getcwd();
        $this->hostname   = \Safe\gethostname();
        $this->fixture    = FixtureConsumer::create('qa-application');
        $this->consumer   = $this->fixture->dir;
        $this->path       = getenv(self::PATH);
        $this->fakePhp    = $this->consumer->write('fakephp/php', <<<'SH'
            #!/bin/sh
            dir=$(dirname "$0")
            printf '%s\n' "$2" >> "$dir/calls.log"
            case "$2" in
              *xdebug*) answer=xdebug ;;
              *PHP_VERSION*) answer=version ;;
              *opcache.jit*) answer=jit ;;
              *) answer=opcache ;;
            esac
            cat "$dir/$answer"

            SH);
        \Safe\chmod($this->fakePhp, 0o755);
        \Safe\chmod($this->consumer->write('fakebin/nproc', <<<'SH'
            #!/bin/sh
            answer=$(cat "$(dirname "$0")/answer")
            if [ "$answer" = fail ]; then
              exit 1
            fi
            printf '%s\n' "$answer"

            SH), 0o755);
        $this->answers('0', self::OLD_PHP, self::NO_JIT, self::NO_OPCACHE);
        $this->consumer->write('qaConfig/pipeline.php', <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
            use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
            use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
            use LTS\PHPQA\Pipeline\Tool\ToolContext;
            use LTS\PHPQA\Pipeline\Tool\ToolInterface;

            return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline->withTool(
                new ToolDefinitionDto('probe', ['probe'], 'prints the configuration it was given', 'linting', true, supportsJson: true, supportsAgentMode: true),
                new class implements ToolInterface {
                    public function name(): string { return 'probe'; }
                    public function identifier(): string { return 'fixture.probe'; }
                    public function run(ToolContext $context): ToolResultDto
                    {
                        $c = $context->config;
                        $context->stdout->writeln('[probe stdout]');
                        $context->writeln(sprintf(
                            '[probe] php=%s memory=%s xdebug=%d threads=%d ci=%d readOnly=%d aggregate=%d json=%d agent=%d tool=%s path=%s',
                            $c->phpBinPath, $c->memoryLimit, (int) $c->xdebugEnabled, $c->halfCpuThreads, (int) $c->ci, (int) $c->readOnly,
                            (int) $c->aggregate, (int) $c->jsonOutput, (int) $c->agentMode, $c->singleTool ?? '-', $c->specifiedPath ?? '-',
                        ));
                        $context->writeln(sprintf(
                            '[probe] invoker=%s sigterm=%s',
                            implode(' ', array_slice($context->php->specWithoutXdebug('x.php', [], '.')->command, 0, 3)),
                            get_debug_type(pcntl_signal_get_handler(SIGTERM)),
                        ));

                        return is_file($c->paths->projectRoot . '/probe.fail') ? ToolResultDto::failed('told to fail') : ToolResultDto::passed();
                    }
                },
            );
            PHP_WRAP);
    }

    protected function tearDown(): void
    {
        \Safe\putenv(false === $this->xdebugMode ? self::XDEBUG_MODE : self::XDEBUG_MODE . '=' . $this->xdebugMode);
        \Safe\putenv(false === $this->path ? self::PATH : self::PATH . '=' . $this->path);
        \Safe\chdir($this->cwd);
        $this->fixture->remove();
    }

    #[Test]
    public function helpPrintsOnlyTheUsageToStderr(): void
    {
        self::assertSame(1, $this->qa([], '-h'));
        self::assertSame($this->pipelinePreamble(), $this->stdout);
        self::assertStringStartsWith("Usage:\nvendor/bin/qa [-t tool to run ]", $this->stderr);
        self::assertStringContainsString(self::PROBE, $this->stderr);
    }

    #[Test]
    public function anInvalidToolPrintsTheErrorThenTheUsage(): void
    {
        self::assertSame(1, $this->qa([], self::DASH_T, 'nope'));
        self::assertSame($this->pipelinePreamble(), $this->stdout);
        self::assertStringStartsWith("\nERROR:\nInvalid tool: nope", $this->stderr);
        self::assertStringContainsString("\nUsage:\nvendor/bin/qa ", $this->stderr);
    }

    #[Test]
    public function aPathWithANonPathToolIsRefusedWithoutTheUsage(): void
    {
        self::assertSame(1, $this->qa([], self::DASH_T, 'cr', self::DASH_P, 'src'));
        self::assertStringStartsWith("\nERROR:\nTool 'cr' does not support path-specific execution.", $this->stderr);
        self::assertStringNotContainsString('Usage:', $this->stderr);
        self::assertSame($this->pipelinePreamble(), $this->stdout);
    }

    #[Test]
    public function aPathOutsideTheProjectIsRefused(): void
    {
        self::assertSame(1, $this->qa([], self::DASH_T, self::PROBE, self::DASH_P, '/usr'));
        self::assertSame(\sprintf("\nERROR:\n-p path /usr is outside the project root %s\n\n", $this->consumer->path), $this->stderr);
        self::assertSame($this->pipelinePreamble(), $this->stdout);
    }

    /**
     * In agent mode stdout is the only channel the caller reads, so a refusal goes there.
     *
     * @param array<string, string> $env
     */
    #[Test]
    #[TestWith([[], self::AGENT_MODE, self::DASH_T, 'pt'], 'option')]
    #[TestWith([['PHPQACI_AGENT_MODE' => '1'], self::DASH_T, 'pt'], 'environment')]
    public function anAgentModeRefusalGoesToStdout(array $env, string ...$argv): void
    {
        self::assertSame(1, $this->qa($env, ...$argv));
        self::assertStringStartsWith("\nERROR: --agent-mode is not supported for 'packageType'", $this->stdout);
        self::assertSame('', $this->stderr);
    }

    #[Test]
    public function aPassingRunPrintsTheHeaderAndEndsCompleted(): void
    {
        $umask = umask(0o022);

        try {
            self::assertSame(0, $this->qa([self::QA_READONLY => '1'], self::DASH_T, self::PROBE), $this->stdout);
        } finally {
            umask($umask);
        }

        self::assertStringStartsWith(\sprintf("%s\n%s\n%s qa -t probe\n%s\n\n", $this->pipelinePreamble(), self::RULE, $this->hostname, self::RULE), $this->stdout);
        self::assertStringEndsWith(\sprintf("seconds\n\n%s\n%s qa -t probe COMPLETED\n%s\n", self::RULE, $this->hostname, self::RULE), $this->stdout);
        self::assertStringContainsString(\sprintf("[probe] invoker=%s -d memory_limit=4G sigterm=Closure\n", $this->fakePhp), $this->stdout);
        self::assertSame(0o755, \Safe\fileperms($this->consumer->path . '/var') & 0o7777, 'var/ is created world-traversable, as umask 022 leaves 0777');
        self::assertStringContainsString(self::READ_ONLY . "\n  (override with QA_READONLY=0 to apply fixes)\n", $this->stdout);
        self::assertStringContainsString(self::AGGREGATE . "\n  (override with QA_FAIL_FAST=1 to stop at the first failing tool)\n", $this->stdout);
        self::assertStringContainsString("generic platform detected\n", $this->stdout);
        self::assertStringContainsString("\n\nChecking for Xdebug\n-------------------\nXdebug is not enabled - infection and coverage not available\n", $this->stdout);
        self::assertStringContainsString(self::PROBE_STDOUT, $this->stdout);
        self::assertStringContainsString(
            \sprintf('[probe] php=%s memory=4G xdebug=0 threads=%d ci=1 readOnly=1 aggregate=1 json=0 agent=0 tool=probe path=-', $this->fakePhp, $this->halfCpuThreads()),
            $this->stdout,
        );
        self::assertStringNotContainsString('Only scanning', $this->stdout);
        self::assertSame('', $this->stderr);
    }

    /** The closing banner must not end on a word that reads as success when the run failed. */
    #[Test]
    #[TestWith(['1'], 'read-only, aggregate')]
    #[TestWith(['0'], 'writable, fail-fast')]
    public function aFailingRunEndsFailedWithTheExitCode(string $readOnly): void
    {
        $this->consumer->write(self::PROBE_FAIL, '');

        self::assertSame(1, $this->qa([self::QA_READONLY => $readOnly], self::DASH_T, self::PROBE));

        self::assertStringEndsWith(\sprintf("\n%s\n%s qa -t probe FAILED (exit 1)\n%s\n", self::RULE, $this->hostname, self::RULE), $this->stdout);
        self::assertStringNotContainsString(self::COMPLETED, $this->stdout);
        self::assertStringNotContainsString('would you like to try again', $this->stdout);
    }

    #[Test]
    public function aWritableFailFastRunSaysSoAndOmitsTheAggregateLines(): void
    {
        self::assertSame(0, $this->qa([self::QA_READONLY => '0'], self::DASH_T, self::PROBE));

        self::assertStringContainsString("QA writable mode: fixers (Rector, PHP CS Fixer) may modify files.\n  (override with QA_READONLY=1 for a read-only check run, as GitHub Actions does)\n", $this->stdout);
        self::assertStringNotContainsString('read-only (check)', $this->stdout);
        self::assertStringNotContainsString('aggregate mode', $this->stdout);
        self::assertStringContainsString('readOnly=0 aggregate=0', $this->stdout);
    }

    #[Test]
    public function aReadOnlyFailFastRunOmitsTheAggregateLines(): void
    {
        self::assertSame(0, $this->qa([self::QA_READONLY => '1', 'QA_FAIL_FAST' => '1'], self::DASH_T, self::PROBE));

        self::assertStringContainsString(self::READ_ONLY, $this->stdout);
        self::assertStringNotContainsString('aggregate mode', $this->stdout);
        self::assertStringContainsString('readOnly=1 aggregate=0', $this->stdout);
    }

    /**
     * @param array<string, string> $env
     */
    #[Test]
    #[TestWith([['CLAUDECODE' => '1'], "Claude Code environment detected - enabling CI mode\n"], 'claude code')]
    #[TestWith([[], "Non-interactive environment detected - enabling CI mode\n"], 'no tty')]
    #[TestWith([['CI' => 'true'], ''], 'explicit CI')]
    #[TestWith([['CI' => 'true', 'CLAUDECODE' => '1'], "Claude Code environment detected - enabling CI mode\n"], 'both')]
    public function theCiModeAnnouncementNamesWhyItWasEnabled(array $env, string $announcement): void
    {
        self::assertSame(0, $this->qa([...$env, self::QA_READONLY => '0'], self::DASH_T, self::PROBE));

        self::assertStringContainsString(\sprintf("%s\n\n%sQA writable mode", self::RULE, $announcement), $this->stdout);
        self::assertStringContainsString(' ci=1 ', $this->stdout);
    }

    /** With both streams a terminal and no CI, a failure asks whether to retry and reads the answer from stdin. */
    #[Test]
    public function anInteractiveRunAsksBeforeGivingUpOnAFailure(): void
    {
        $this->consumer->write(self::PROBE_FAIL, '');

        self::assertSame(1, $this->runApp([self::QA_READONLY => '0'], "n\n", true, self::DASH_T, self::PROBE));

        self::assertStringNotContainsString(self::ENVIRONMENT_DETECTED, $this->stdout);
        self::assertStringContainsString(' ci=0 ', $this->stdout);
        self::assertStringContainsString('would you like to try again? (y/n)', $this->stdout);
        self::assertStringContainsString('FAILED (exit 1)', $this->stdout);
    }

    #[Test]
    public function aSymfonyProjectIsAnnounced(): void
    {
        $this->consumer->write('symfony.lock', '{}');

        self::assertSame(0, $this->qa([], self::DASH_T, self::PROBE));
        self::assertStringContainsString("\nsymfony platform detected\n", $this->stdout);
    }

    #[Test]
    public function theEnvironmentPicksThePhpBinaryAndTheMemoryLimit(): void
    {
        self::assertSame(0, $this->qa(['phpqaMemoryLimit' => '2G'], self::DASH_T, self::PROBE));
        self::assertStringContainsString(\sprintf('[probe] php=%s memory=2G ', $this->fakePhp), $this->stdout);
        self::assertStringContainsString(\sprintf('[probe] invoker=%s -d memory_limit=2G ', $this->fakePhp), $this->stdout);
    }

    #[Test]
    public function withoutAnExecutableTheBinaryIsPhpOnThePath(): void
    {
        self::assertSame(0, $this->qa(['PHP_QA_CI_PHP_EXECUTABLE' => ''], self::DASH_T, self::PROBE), $this->stdout);
        self::assertStringContainsString('[probe] php=php memory=4G ', $this->stdout);
    }

    /**
     * Half of what nproc says; when nproc fails or says nothing usable, half the
     * processors /proc/cpuinfo lists; never less than one.
     */
    #[Test]
    #[TestWith(['7', 3])]
    #[TestWith(['1', 1])]
    #[TestWith(['0', null])]
    #[TestWith(['fail', null])]
    public function theParallelismDefaultIsHalfTheCpuThreads(string $nproc, ?int $threads): void
    {
        $this->consumer->write('fakebin/answer', $nproc);
        \Safe\putenv(self::PATH . '=' . $this->consumer->path . '/fakebin:' . (false === $this->path ? '' : $this->path));

        self::assertSame(0, $this->qa([], self::DASH_T, self::PROBE));

        $expected = $threads ?? max(1, intdiv(substr_count(\Safe\file_get_contents(self::CPUINFO), 'processor'), 2));
        self::assertStringContainsString(\sprintf(' threads=%d ', $expected), $this->stdout);
        self::assertStringNotContainsString("\n" . $nproc . "\n", $this->stdout, 'the nproc answer is read, not printed');
    }

    #[Test]
    public function aSpecifiedPathIsAnnouncedAndHandedOnRelativeToTheProject(): void
    {
        self::assertSame(0, $this->qa([], self::DASH_T, self::PROBE, $this->consumer->path . '/src/'));

        self::assertStringContainsString("\nOnly scanning the specified path src\n", $this->stdout);
        self::assertStringContainsString('tool=probe path=src', $this->stdout);
    }

    #[Test]
    public function withoutXdebugTheJitSettingsAreNeverProbed(): void
    {
        $this->answers('0', self::OLD_PHP, '1|tracing|64M', self::NO_OPCACHE);

        self::assertSame(0, $this->qa([], self::DASH_T, self::PROBE));

        self::assertStringNotContainsString('WARNING', $this->stdout);
        self::assertStringNotContainsString('opcache.jit', $this->consumer->read(self::CALLS));
        self::assertStringContainsString('xdebug=0', $this->stdout);
    }

    #[Test]
    public function withXdebugAndJitTheAdvisoryIsPrinted(): void
    {
        $this->answers('1', self::OLD_PHP, '1|tracing|64M', self::NO_OPCACHE);

        self::assertSame(0, $this->qa([], self::DASH_T, self::PROBE));

        self::assertStringContainsString("\nXdebug is enabled in coverage mode\n", $this->stdout);
        self::assertStringContainsString("\nWARNING: Xdebug is loaded and OPcache JIT is configured on (opcache.jit, opcache.jit_buffer_size).\n", $this->stdout);
        self::assertStringContainsString('xdebug=1', $this->stdout);
        self::assertSame('coverage', getenv(self::XDEBUG_MODE));
    }

    /** Debug mode is a developer's choice and is left alone; whatever the process has is what is reported. */
    #[Test]
    #[TestWith(['develop', 'develop'])]
    #[TestWith(['', 'default'])]
    #[TestWith([null, 'default'])]
    public function anXdebugInDebugModeIsLeftInTheModeTheProcessHas(?string $processMode, string $reported): void
    {
        $this->answers('1', self::OLD_PHP, self::NO_JIT, self::NO_OPCACHE);
        \Safe\putenv(null === $processMode ? self::XDEBUG_MODE : self::XDEBUG_MODE . '=' . $processMode);

        self::assertSame(0, $this->qa([self::XDEBUG_MODE => 'debug'], self::DASH_T, self::PROBE));

        self::assertStringContainsString(\sprintf("\nXdebug is enabled in %s mode\n", $reported), $this->stdout);
    }

    #[Test]
    public function anAffectedOpcacheIsWarnedAbout(): void
    {
        $this->answers('0', '8.5.0', self::NO_JIT, '1|');

        self::assertSame(0, $this->qa([], self::DASH_T, self::PROBE));

        self::assertStringContainsString("\nWARNING: PHP 8.5.0 runs the OPcache DFA optimizer pass (opcache.optimization_level=0x7FFEBFFF).\n", $this->stdout);
    }

    #[Test]
    public function uniterateSwitchesPhpunitToIterativeMode(): void
    {
        $this->consumer->write('qaConfig/tools/phpunit.php', <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
            use LTS\PHPQA\Pipeline\Tool\ToolContext;
            use LTS\PHPQA\Pipeline\Tool\ToolInterface;

            return new class implements ToolInterface {
                public function name(): string { return 'phpunit'; }
                public function identifier(): string { return 'fixture.phpunit'; }
                public function run(ToolContext $context): ToolResultDto
                {
                    $context->writeln('[phpunit] iterative=' . (int) $context->config->phpUnit->iterativeMode);

                    return ToolResultDto::passed();
                }
            };
            PHP_WRAP);

        self::assertSame(0, $this->qa([], self::DASH_T, 'uniterate'));
        self::assertStringContainsString('[phpunit] iterative=1', $this->stdout);

        self::assertSame(0, $this->qa([], self::DASH_T, 'phpunit'));
        self::assertStringContainsString('[phpunit] iterative=0', $this->stdout);
    }

    #[Test]
    public function aHeldLockEndsTheRunWithItsOwnExitCodeAndOneStderrLine(): void
    {
        $exit = $this->holdingTheLock(fn (): int => $this->qa([], self::DASH_T, self::PROBE));

        self::assertSame(Pipeline::EXIT_LOCK_CONTENDED, $exit);
        self::assertSame(self::LOCK_LINE, $this->stderr);
        self::assertStringNotContainsString('[probe', $this->stdout);
        self::assertStringNotContainsString(self::COMPLETED, $this->stdout);
        self::assertStringNotContainsString('FAILED', $this->stdout);
    }

    /** --json keeps stdout for the structured output, so every line of decoration goes to stderr. */
    #[Test]
    public function jsonModeSendsTheDecorationToStderr(): void
    {
        self::assertSame(0, $this->qa([], '--json', self::DASH_T, self::PROBE));

        self::assertSame(self::PROBE_STDOUT, $this->stdout);
        self::assertStringStartsWith(\sprintf("%s\n%s\n%s qa --json -t probe\n", $this->pipelinePreamble(), self::RULE, $this->hostname), $this->stderr);
        self::assertStringContainsString(' json=1 ', $this->stderr);
        self::assertStringEndsWith(\sprintf("%s qa --json -t probe COMPLETED\n%s\n", $this->hostname, self::RULE), $this->stderr);
    }

    /** Agent mode throws the decoration away: stdout carries only what the lane writes there. */
    #[Test]
    public function agentModeDiscardsTheDecoration(): void
    {
        self::assertSame(AgentStatusEnum::Clean->exitCode(), $this->qa([], self::AGENT_MODE, self::DASH_T, self::PROBE));

        self::assertSame(self::PROBE_STDOUT, $this->stdout);
        self::assertSame('', $this->stderr);
    }

    #[Test]
    public function agentModeFromTheEnvironmentAlsoDiscardsTheDecoration(): void
    {
        $this->consumer->write(self::PROBE_FAIL, '');

        self::assertSame(AgentStatusEnum::Errors->exitCode(), $this->qa(['PHPQACI_AGENT_MODE' => '1'], self::DASH_T, self::PROBE));

        self::assertSame(self::PROBE_STDOUT, $this->stdout);
        self::assertSame('', $this->stderr);
    }

    #[Test]
    public function agentModeReportsAHeldLockOnStdoutWithItsOwnStatus(): void
    {
        $exit = $this->holdingTheLock(fn (): int => $this->qa([], self::AGENT_MODE, self::DASH_T, self::PROBE));

        self::assertSame(AgentStatusEnum::LockHeld->exitCode(), $exit);
        self::assertStringStartsWith('PROBE AGENT MODE: lock-held — another QA run holds the project lock', $this->stdout);
        self::assertSame('', $this->stderr);
    }

    #[Test]
    public function aProjectThatCannotBeResolvedIsAnErrorBeforeTheHeader(): void
    {
        \Safe\rmdir($this->consumer->path . '/tests');

        self::assertSame(1, $this->qa([], self::DASH_T, self::PROBE));

        self::assertSame('ERROR: ' . ProjectLayoutException::missingDirectory('tests', $this->consumer->path)->getMessage() . "\n", $this->stdout);
        self::assertSame('', $this->stderr);
    }

    #[Test]
    public function aThrowingProjectConfigIsReportedWithItsOrigin(): void
    {
        $qaPhp = $this->consumer->write('qaConfig/qa.php', "<?php\n\ndeclare(strict_types=1);\n\nthrow new RuntimeException('boom from qa.php');\n");

        self::assertSame(1, $this->qa([], self::DASH_T, self::PROBE));

        self::assertStringEndsWith(\sprintf("\n\nERROR: boom from qa.php\n  in %s:5\n", $qaPhp), $this->stdout);
        self::assertStringNotContainsString(self::COMPLETED, $this->stdout);
        self::assertSame('', $this->stderr);
    }

    /** The run installs signal handlers for its whole length and must leave the process as it found it. */
    #[Test]
    public function theSignalDispositionIsRestoredAfterTheRun(): void
    {
        $before = $this->dispositions();
        $async  = pcntl_async_signals();

        self::assertSame(0, $this->qa([], self::DASH_T, self::PROBE));

        self::assertSame($before, $this->dispositions());
        self::assertSame($async, pcntl_async_signals());
    }

    /** What reading qaConfig/pipeline.php prints, before the arguments are parsed. */
    private function pipelinePreamble(): string
    {
        return \sprintf("\nFound project pipeline at %s/qaConfig/pipeline.php\nProject pipeline applied\n", $this->consumer->path);
    }

    /** @return array<int, mixed> */
    private function dispositions(): array
    {
        $handlers = [];
        foreach (RunInterruptHandler::SIGNALS as $signal) {
            $handlers[$signal] = pcntl_signal_get_handler($signal);
        }

        return $handlers;
    }

    /** @param callable(): int $run */
    private function holdingTheLock(callable $run): int
    {
        $lock = RunLock::forProject($this->consumer->path, new NullOutput());
        self::assertTrue($lock->acquire('test', 'whole project', $this->hostname, 1));

        try {
            if (!$this->flockIsExclusive($lock->lockFile())) {
                self::markTestSkipped("flock is not exclusive in this process (Infection's include interceptor replaces the file stream wrapper and takes every lock shared), so a held lock cannot be observed in-process");
            }

            return $run();
        } finally {
            $lock->release(0);
        }
    }

    /** Whether a second handle on a file this process has flocked is refused, as it is by the kernel. */
    private function flockIsExclusive(string $file): bool
    {
        $probe      = \Safe\fopen($file, 'r');
        $wouldBlock = 0;

        try {
            \Safe\flock($probe, \LOCK_EX | \LOCK_NB, $wouldBlock);
        } catch (FilesystemException $filesystemException) {
            if (1 === $wouldBlock) {
                return true;
            }

            throw $filesystemException;
        } finally {
            \Safe\fclose($probe);
        }

        return false;
    }

    private function answers(string $xdebug, string $version, string $jit, string $opcache): void
    {
        $this->consumer->write(self::ANSWER_XDEBUG, $xdebug);
        $this->consumer->write(self::ANSWER_VERSION, $version);
        $this->consumer->write(self::ANSWER_JIT, $jit);
        $this->consumer->write(self::ANSWER_OPCACHE, $opcache);
    }

    private function halfCpuThreads(): int
    {
        $nproc = new Process(['nproc']);
        $nproc->mustRun();

        return max(1, intdiv((int)trim($nproc->getOutput()), 2));
    }

    /**
     * @param array<string, string> $env
     */
    private function qa(array $env, string ...$argv): int
    {
        return $this->runApp($env, '', false, ...$argv);
    }

    /**
     * @param array<string, string> $env
     */
    private function runApp(array $env, string $stdin, bool $tty, string ...$argv): int
    {
        $out   = \Safe\fopen(self::MEMORY, 'w+');
        $err   = \Safe\fopen(self::MEMORY, 'w+');
        $input = \Safe\fopen(self::MEMORY, 'w+');
        \Safe\fwrite($input, $stdin);
        \Safe\rewind($input);

        $exit = new QaApplication(
            argv: array_values($argv),
            env: ['PHP_QA_CI_PHP_EXECUTABLE' => $this->fakePhp, ...$env],
            projectRoot: $this->consumer->path,
            libraryRoot: \Safe\realpath(__DIR__ . '/../../../..'),
            stdoutStream: $out,
            stderrStream: $err,
            stdinStream: $input,
            stdinIsTty: $tty,
            stdoutIsTty: $tty,
        )->run();

        \Safe\rewind($out);
        \Safe\rewind($err);
        $this->stdout = \Safe\stream_get_contents($out);
        $this->stderr = \Safe\stream_get_contents($err);

        return $exit;
    }
}
