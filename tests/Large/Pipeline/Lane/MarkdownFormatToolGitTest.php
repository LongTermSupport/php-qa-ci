<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lane;

use LTS\PHPQA\HooksDaemon\HooksDaemonCliLocator;
use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Config\QaConfigBuilder;
use LTS\PHPQA\Pipeline\Lane\MarkdownFormatTool;
use LTS\PHPQA\Pipeline\Process\LogArchiver;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\GitSandbox;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The lane's gitignore probe against real git, with a stub daemon that records
 * the paths it is handed. The Small tests pin how the lane reads git's answer;
 * these prove git answers in that shape, including for a path git would quote.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class MarkdownFormatToolGitTest extends TestCase
{
    /** A tracked path the daemon must be handed. */
    private const string README = 'README.md';

    /** An ignored path git would quote under its default core.quotePath. */
    private const string IGNORED = 'gën';

    /** Appends each call's last argument, the path, to calls.log beside itself. */
    private const string STUB_DAEMON = "#!/bin/bash\nprintf '%s\\n' \"\${!#}\" >> \"\$(dirname \"\$0\")/calls.log\"\n";

    #[Test]
    public function aNonAsciiPathGitIgnoresIsDroppedNotHandedToTheDaemon(): void
    {
        $sandbox = GitSandbox::create([self::README => "# Readme\n", '.gitignore' => self::IGNORED . "/\n"]);
        try {
            $sandbox->root->write('work/' . self::IGNORED . '/a.md', "# Generated\n");
            $output = new BufferedOutput();

            $result  = new MarkdownFormatTool()->run($this->context($sandbox->work, $output));
            $printed = $output->fetch();

            self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $printed);
            self::assertSame([self::README], $this->daemonCalls($sandbox->work));
            self::assertStringContainsString('Gitignored, skipped: ' . self::IGNORED, $printed);
        } finally {
            $sandbox->remove();
        }
    }

    #[Test]
    public function outsideAGitWorkTreeEveryPresentPathIsFormatted(): void
    {
        $project = TempDir::create('phpqa-mdf-nogit');
        try {
            $project->write(self::README, "# Readme\n");
            $project->write(self::IGNORED . '/a.md', "# Generated\n");
            $output = new BufferedOutput();

            $result = new MarkdownFormatTool()->run($this->context($project->path, $output));

            self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $output->fetch());
            self::assertSame([self::README, self::IGNORED], $this->daemonCalls($project->path));
        } finally {
            $project->remove();
        }
    }

    private function context(string $root, BufferedOutput $output): ToolContext
    {
        $cli = $root . '/' . HooksDaemonCliLocator::CLI;
        \Safe\mkdir(\dirname($cli), 0o755, true);
        \Safe\file_put_contents($cli, self::STUB_DAEMON);
        \Safe\chmod($cli, 0o755);

        $library = \dirname(__DIR__, 4);
        $paths   = new ProjectPathsDto(
            projectRoot: $root,
            libraryRoot: $library,
            binDir: $root . '/vendor/bin',
            srcDir: $root . '/src',
            testsDir: $root . '/tests',
            projectConfigDir: $root . '/qaConfig',
            varDir: $root . '/var/qa',
            cacheDir: $root . '/var/qa/cache',
            pharDir: $library . '/vendor-phar',
            configDefaultsDir: $library . '/configDefaults',
        );
        $config = QaConfigBuilder::defaults(
            paths: $paths,
            platform: PlatformEnum::Generic,
            env: new EnvironmentReader([]),
            phpBinPath: \PHP_BINARY,
            xdebugEnabled: false,
            halfCpuThreads: 1,
            ci: true,
            readOnly: true,
            aggregate: true,
            jsonOutput: false,
            agentMode: false,
            singleTool: null,
            specifiedPath: null,
        )->withMarkdownFormatPaths(self::README, self::IGNORED)->build();
        $processes = new SymfonyProcessRunner($output);

        return new ToolContext(
            config: $config,
            configPaths: new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, $config->platform),
            processes: $processes,
            php: new PhpInvoker($processes, $config->phpBinPath, $config->memoryLimit, $paths->varDir),
            logs: new LogArchiver($output),
            output: $output,
            stdout: $output,
        );
    }

    /** @return list<string> the path of each daemon call, in order */
    private function daemonCalls(string $root): array
    {
        $log = \dirname($root . '/' . HooksDaemonCliLocator::CLI) . '/calls.log';
        if (!is_file($log)) {
            return [];
        }

        return array_values(array_filter(explode("\n", \Safe\file_get_contents($log)), static fn (string $line): bool => '' !== $line));
    }
}
