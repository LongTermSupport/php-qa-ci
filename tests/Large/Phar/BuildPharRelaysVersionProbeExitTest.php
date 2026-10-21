<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Phar;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * scripts/build-phar.bash proves a phar it built runs by asking its --version. A phar that crashes
 * there prints nothing about it, so the build line names how it ended, never only "non-zero".
 *
 * The script runs from a copy of the repository's scripts/ and bin/ with a stand-in Box that
 * "builds" a phar killing itself with SIGTERM, standing for every signal (a real SIGSEGV would
 * leave a core dump on the host), and a stand-in composer that installs nothing.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class BuildPharRelaysVersionProbeExitTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    #[Test]
    public function aBuiltPharKilledOnItsVersionCheckIsNamedByItsSignal(): void
    {
        $temp = TempDir::create('phpqaci-build-phar-exit');

        try {
            $repo = \Safe\realpath(self::REPO_ROOT);
            foreach (\Safe\scandir($repo . '/bin') as $entry) {
                if (\is_string($entry) && is_file($repo . '/bin/' . $entry)) {
                    $temp->write('bin/' . $entry, \Safe\file_get_contents($repo . '/bin/' . $entry));
                }
            }

            \Safe\symlink($repo . '/vendor', $temp->path . '/vendor');
            $temp->write('scripts/build-phar.bash', \Safe\file_get_contents($repo . '/scripts/build-phar.bash'));
            $temp->write('build/doomed/composer.json', '{}');
            $temp->write('build/doomed/box.json.dist', '{}');
            $box = $temp->write('box.php', "<?php\nfile_put_contents(getenv('BUILD_DIR') . '/doomed.phar', \"<?php\\nposix_kill(getmypid(), SIGTERM);\\n\");\n");
            \Safe\chmod($temp->write('shims/composer', "#!/usr/bin/env bash\nexit 0\n"), 0o755);

            $path = getenv('PATH');
            self::assertIsString($path);
            $process = new Process(['bash', $temp->path . '/scripts/build-phar.bash', 'doomed'], $temp->path, [
                'BOX_PHAR' => $box,
                'PATH'     => $temp->path . '/shims:' . $path,
            ]);
            $process->run();
        } finally {
            $temp->remove();
        }

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString(
            'unknown: php doomed.phar --version failed with exit code 143, killed by signal 15 (SIGTERM)',
            $process->getOutput(),
        );
    }
}
