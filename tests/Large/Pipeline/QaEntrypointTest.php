<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline;

use LTS\PHPQA\Tests\Support\FixtureConsumer;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Characterisation of the PHP entrypoint end-to-end: the real script, the real
 * argument parsing and usage text, over a throwaway consumer project
 * (FixtureConsumer) that has php-qa-ci installed under vendor/lts/php-qa-ci.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class QaEntrypointTest extends TestCase
{
    private const string USAGE = 'Usage:';

    private const string LIBRARY_ROOT = __DIR__ . '/../../..';

    private const string INSTALLED_LIBRARY = FixtureConsumer::INSTALLED_LIBRARY;

    private const string AUTOLOAD = FixtureConsumer::AUTOLOAD;

    private FixtureConsumer $fixture;

    private TempDir $consumer;

    protected function setUp(): void
    {
        $this->fixture  = FixtureConsumer::create('qa-entrypoint');
        $this->consumer = $this->fixture->dir;
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    #[Test]
    public function helpPrintsTheUsageToStderrAndExitsOne(): void
    {
        $process = $this->qa([], '-h');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString(self::USAGE, $process->getErrorOutput());
        self::assertStringContainsString('stan|phpstan', $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }

    #[Test]
    public function anInvalidToolPrintsTheErrorAndTheUsage(): void
    {
        $process = $this->qa([], '-t', 'nope');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Invalid tool: nope', $process->getErrorOutput());
        self::assertStringContainsString(self::USAGE, $process->getErrorOutput());
    }

    #[Test]
    public function aPathWithANonPathToolIsRefusedWithoutTheUsage(): void
    {
        $process = $this->qa([], '-t', 'cr', '-p', 'src');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString("Tool 'cr' does not support path-specific execution", $process->getErrorOutput());
        self::assertStringNotContainsString(self::USAGE, $process->getErrorOutput());
    }

    #[Test]
    public function aPathOutsideTheProjectIsRefused(): void
    {
        $process = $this->qa([], '-t', 'stan', '-p', '/usr');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('is outside the project root', $process->getErrorOutput());
    }

    #[Test]
    public function theRunHeaderReportsModesPlatformAndXdebugThenRunsTheTool(): void
    {
        $process = $this->qa(['QA_READONLY' => '1'], '-t', 'pt');

        $out = $process->getOutput();
        self::assertStringContainsString('QA read-only (check) mode', $out);
        self::assertStringContainsString('QA aggregate mode', $out);
        self::assertStringContainsString('generic platform detected', $out);
        self::assertStringContainsString('Checking for Xdebug', $out);
        self::assertSame(0, $process->getExitCode(), $out . $process->getErrorOutput());
        self::assertMatchesRegularExpression('/ qa -t pt COMPLETED\n=+\n$/', $out);
    }

    /**
     * The closing banner is the line a reader skims for the verdict, so a
     * failed run must not end on a word that reads as success. Both modes: the
     * aggregate run keeps going past the failure, the fail-fast one stops.
     */
    #[Test]
    #[TestWith(['1'], 'read-only, aggregate')]
    #[TestWith(['0'], 'writable, fail-fast')]
    public function aFailedRunEndsWithAFailureBannerNotCompleted(string $readOnly): void
    {
        $this->consumer->write('composer.json', '{"name": "fixture/consumer", "autoload": {"psr-4": {"Fixture\\\\": "src/"}}}');

        $process = $this->qa(['QA_READONLY' => $readOnly], '-t', 'pt');

        $out = $process->getOutput();
        self::assertSame(1, $process->getExitCode(), $out . $process->getErrorOutput());
        self::assertMatchesRegularExpression('/ qa -t pt FAILED \(exit 1\)\n=+\n$/', $out);
        self::assertStringNotContainsString('COMPLETED', $out);
    }

    #[Test]
    public function aProjectPipelinePhpAddsAPhaseAndAToolThatDashTCanSelect(): void
    {
        $this->consumer->write('qaConfig/pipeline.php', <<<'PHP_WRAP'
            <?php
            use LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto;
            use LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto;
            use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
            use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
            use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
            use LTS\PHPQA\Pipeline\Tool\ToolContext;
            use LTS\PHPQA\Pipeline\Tool\ToolInterface;

            return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline
                ->withPhase(new PhaseDto('security', 'Running All Security Tools', 'allSecurityTools', ['allSec'], 'all security tools'), before: PhaseEnum::Testing->value)
                ->withTool(
                    new ToolDefinitionDto('securityAudit', ['sa'], 'security audit', 'security', false, banner: 'Auditing Security'),
                    new class implements ToolInterface {
                        public function name(): string { return 'securityAudit'; }
                        public function identifier(): string { return 'project.securityAudit'; }
                        public function run(ToolContext $context): ToolResultDto
                        {
                            $context->writeln('[securityAudit ran]');

                            return ToolResultDto::passed();
                        }
                    },
                );
            PHP_WRAP);

        $single = $this->qa(['QA_READONLY' => '1'], '-t', 'sa');
        self::assertSame(0, $single->getExitCode(), $single->getOutput() . $single->getErrorOutput());
        self::assertStringContainsString('Found project pipeline at', $single->getOutput());
        self::assertStringContainsString('[securityAudit ran]', $single->getOutput());

        $phase = $this->qa(['QA_READONLY' => '1'], '-t', 'allSec');
        self::assertSame(0, $phase->getExitCode(), $phase->getOutput() . $phase->getErrorOutput());
        self::assertStringContainsString('Auditing Security', $phase->getOutput());
        self::assertStringContainsString('[securityAudit ran]', $phase->getOutput());

        $help = $this->qa([], '-h');
        self::assertStringContainsString('allSec', $help->getErrorOutput());
        self::assertStringContainsString('security audit', $help->getErrorOutput());
    }

    #[Test]
    public function aBashEraQaConfigIsRefusedWithMigrationGuidance(): void
    {
        $this->consumer->write('qaConfig/qaConfig.inc.bash', "export useInfection=0\n");

        $process = $this->qa(['QA_READONLY' => '1'], '-t', 'pt');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('qaConfig.inc.bash is no longer read', $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('docs/upgrading-to-8.5.md', $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * A vendored php-qa-ci that has run its own composer install holds two
     * autoloaders: the consumer's, three levels up, and its own. Which one is
     * the project is decided by where the command is run from, so
     * "cd vendor/lts/php-qa-ci && bin/qa" validates php-qa-ci itself while the
     * consumer's vendor/bin/qa still validates the consumer.
     */
    #[Test]
    public function theBootstrapPicksTheProjectByTheWorkingDirectoryWhenBothAutoloadersExist(): void
    {
        $installed = $this->consumer->path . '/' . self::INSTALLED_LIBRARY;
        $this->consumer->write(self::INSTALLED_LIBRARY . self::AUTOLOAD, "<?php\n\ndeclare(strict_types=1);\n\nreturn require " . var_export(\Safe\realpath(self::LIBRARY_ROOT) . self::AUTOLOAD, true) . ";\n");
        $probe     = $this->consumer->write('probe.php', "<?php\n\ndeclare(strict_types=1);\n\nrequire \$argv[1];\necho \$phpQaCiBootstrapAutoloadPath;\n");
        $bootstrap = $installed . '/bin/bootstrap.php';

        self::assertSame(\Safe\realpath($this->consumer->path . self::AUTOLOAD), $this->probe($probe, $bootstrap, $this->consumer->path));
        self::assertSame(\Safe\realpath($installed . self::AUTOLOAD), $this->probe($probe, $bootstrap, $installed));
        self::assertSame(\Safe\realpath($installed . self::AUTOLOAD), $this->probe($probe, $bootstrap, $installed . '/bin'));
    }

    private function probe(string $probe, string $bootstrap, string $cwd): string
    {
        $process = new Process(['php', $probe, $bootstrap], $cwd, ['XDEBUG_MODE' => 'off'], null, 60);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @param array<string, string> $env */
    private function qa(array $env, string ...$args): Process
    {
        $process = $this->fixture->qa($env, ...$args);
        $process->run();

        return $process;
    }
}
