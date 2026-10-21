<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PhpstanDocs;

use LTS\PHPQA\PhpstanDocs\GitPhpstanDocsFetcher;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The fetcher against a local repository laid out as phpstan/phpstan is, so
 * the sparse checkout is exercised for real without the network.
 *
 * @internal
 */
#[CoversClass(GitPhpstanDocsFetcher::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\ComposerPlugin\ChildExit::class)]
#[Medium]
final class GitPhpstanDocsFetcherTest extends TestCase
{
    private const string BRANCH = '2.3.x';

    private TempDir $work;

    protected function setUp(): void
    {
        $this->work = TempDir::create('phpqa-phpstan-docs');
    }

    protected function tearDown(): void
    {
        $this->work->remove();
    }

    #[Test]
    public function itChecksOutThePagesTheIdentifierListAndTheLicenceAtTheBranchTip(): void
    {
        $out = $this->work->path . '/out';

        $fetched = $this->fetcher()->fetch($out);

        self::assertMatchesRegularExpression('/^' . self::BRANCH . '@[0-9a-f]{12}$/', $fetched);
        self::assertFileExists($out . '/website/errors/argument.type.md');
        self::assertFileExists($out . '/website/src/errorsIdentifiers.json');
        self::assertFileExists($out . '/LICENSE');
        self::assertFileDoesNotExist($out . '/src/Analyser.php', 'only the documentation is checked out');
    }

    #[Test]
    public function aRepositoryThatCannotBeReachedFailsWithTheCause(): void
    {
        $missing = 'file://' . $this->work->path . '/nowhere';

        try {
            new GitPhpstanDocsFetcher($missing)->fetch($this->work->path . '/out');
            self::fail('an unreachable repository must fail');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString($missing, $runtimeException->getMessage());
        }
    }

    /** A git a signal kills is named, with the signal, rather than as Symfony's bare "signaled". */
    #[Test]
    public function aGitKilledByASignalFailsNamingTheCommandAndTheSignal(): void
    {
        $path = getenv('PATH');
        self::assertIsString($path);
        // A stand-in git that kills itself with SIGTERM; the executable is looked up on this process's PATH.
        \Safe\chmod($this->work->write('bin/git', "#!/bin/sh\nkill -TERM \$\$\n"), 0o755);
        \Safe\putenv('PATH=' . $this->work->path . '/bin:' . $path);

        try {
            new GitPhpstanDocsFetcher('file:///nowhere')->fetch($this->work->path . '/fetched');
            self::fail('a signalled git must fail');
        } catch (RuntimeException $runtimeException) {
            self::assertStringStartsWith('could not fetch the PHPStan identifier pages from file:///nowhere: `', $runtimeException->getMessage());
            self::assertStringEndsWith('` ended with exit code 143, killed by signal 15 (SIGTERM)', $runtimeException->getMessage());
        } finally {
            \Safe\putenv('PATH=' . $path);
        }
    }

    private function fetcher(): GitPhpstanDocsFetcher
    {
        $this->work->write('upstream/website/errors/argument.type.md', "# argument.type\n");
        $this->work->write('upstream/website/src/errorsIdentifiers.json', "{\"argument.type\": {}}\n");
        $this->work->write('upstream/LICENSE', "MIT\n");
        $this->work->write('upstream/src/Analyser.php', "<?php\n");

        // The host's own git config (signing, hooks, default branch) stays out of the fixture.
        $upstream    = $this->work->path . '/upstream';
        $environment = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];
        foreach ([
            ['init', '--quiet', '--initial-branch', self::BRANCH],
            ['add', '.'],
            ['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-m', 'fixture'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $upstream, $environment)->mustRun();
        }

        return new GitPhpstanDocsFetcher('file://' . $upstream);
    }
}
