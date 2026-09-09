<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Characterisation of the PHP entrypoint end-to-end: the real script, the real
 * argument parsing and usage text, over a throwaway consumer project that has
 * php-qa-ci installed under vendor/lts/php-qa-ci. Running against a fixture
 * consumer (never this repository) keeps the test off this repository's run
 * lock, so it cannot collide with a concurrent bin/qa here.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class QaEntrypointTest extends TestCase
{
    private const string USAGE = 'Usage:';

    private const string BIN = '/bin/';

    private const string LIBRARY_ROOT = __DIR__ . '/../../..';

    private const string INSTALLED_LIBRARY = 'vendor/lts/php-qa-ci';

    private const string AUTOLOAD = '/vendor/autoload.php';

    private TempDir $consumer;

    protected function setUp(): void
    {
        $this->consumer = TempDir::create('qa-entrypoint');
        $this->installLibraryIntoConsumer();
        $this->consumer->write('composer.json', <<<'JSON'
            {
              "name": "fixture/consumer",
              "type": "project",
              "require": { "php": "^8.5" },
              "autoload": { "psr-4": { "Fixture\\": "src/" } },
              "config": { "allow-plugins": { "ergebnis/composer-normalize": true } }
            }
            JSON);
        $this->consumer->write('src/Thing.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class Thing {}\n");
        $this->consumer->mkdir('tests');
    }

    protected function tearDown(): void
    {
        $this->consumer->remove();
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

    /**
     * PHP resolves __DIR__ through symlinks, so a symlinked library would find
     * THIS repository's autoloader and treat this repository as the project.
     * The consumer therefore gets a real bin/ directory holding copies of the
     * entrypoint and its bootstrap, a vendor/autoload.php that delegates to the
     * real one, and symlinks for everything else the pipeline reads from the
     * library root (configDefaults, vendor-phar, phive.xml, ...).
     */
    private function installLibraryIntoConsumer(): void
    {
        $installed = $this->consumer->mkdir(self::INSTALLED_LIBRARY);
        $this->consumer->mkdir(self::INSTALLED_LIBRARY . '/bin');
        $libraryRoot = \Safe\realpath(self::LIBRARY_ROOT);

        foreach (['qa', 'bootstrap.php'] as $script) {
            \Safe\copy($libraryRoot . self::BIN . $script, $installed . self::BIN . $script);
            \Safe\chmod($installed . self::BIN . $script, 0o755);
        }

        foreach (\Safe\scandir($libraryRoot) as $entry) {
            if (!\is_string($entry) || \in_array($entry, ['.', '..', 'bin', '.git', 'vendor', 'var', 'untracked'], true)) {
                continue;
            }

            \Safe\symlink($libraryRoot . '/' . $entry, $installed . '/' . $entry);
        }

        $this->consumer->write('vendor/autoload.php', \sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn require %s;\n",
            var_export($libraryRoot . self::AUTOLOAD, true),
        ));
    }

    /** @param array<string, string> $env */
    private function qa(array $env, string ...$args): Process
    {
        $process = new Process(
            ['php', $this->consumer->path . '/' . self::INSTALLED_LIBRARY . '/bin/qa', ...$args],
            $this->consumer->path,
            ['CI' => 'true', ...$env],
            null,
            120,
        );
        $process->run();

        return $process;
    }
}
