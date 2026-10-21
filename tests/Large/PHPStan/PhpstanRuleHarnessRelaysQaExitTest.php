<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\PHPStan;

use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * bin/phpstan-rule refuses an answer when the qa run left no JSON. A qa killed by a signal leaves
 * no output either, so the refusal names how qa ended rather than only quoting what it printed.
 *
 * The harness runs from a copy of bin/ whose qa kills itself with SIGTERM, standing for every
 * signal (a real SIGSEGV would leave a core dump on the host); vendor/ is this repository's.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpstanRuleHarnessRelaysQaExitTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    #[Test]
    public function aQaRunKilledBySignalIsNamedInTheRefusal(): void
    {
        $temp = TempDir::create('phpqaci-phpstan-rule-exit');

        try {
            $repo = \Safe\realpath(self::REPO_ROOT);
            foreach (\Safe\scandir($repo . '/bin') as $entry) {
                if (\is_string($entry) && is_file($repo . '/bin/' . $entry)) {
                    \Safe\chmod($temp->write('bin/' . $entry, \Safe\file_get_contents($repo . '/bin/' . $entry)), 0o755);
                }
            }

            \Safe\chmod($temp->write('bin/qa', "#!/usr/bin/env bash\nkill -TERM \$\$\n"), 0o755);
            \Safe\symlink($repo . '/vendor', $temp->path . '/vendor');
            $target = $temp->write('src/Example.php', "<?php\n");

            $process = new Process([$temp->path . '/bin/phpstan-rule', 'phpqaci.nestedTernary', $target], $temp->path);
            $process->run();
        } finally {
            $temp->remove();
        }

        self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString(
            'PHPStan produced no JSON run for ' . $target . ': qa ended with exit code 143, killed by signal 15 (SIGTERM). The qa output was:',
            $process->getErrorOutput(),
        );
    }
}
