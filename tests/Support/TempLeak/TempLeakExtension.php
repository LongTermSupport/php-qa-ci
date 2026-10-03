<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support\TempLeak;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use RuntimeException;

/**
 * Defence for the class "a test leaves files in the system temp directory".
 *
 * Each test process gets a temp directory of its own (TMPDIR, set before
 * anything has resolved sys_get_temp_dir()), so a leak is attributable even
 * when other processes write to the shared one. After every test the directory
 * must be empty again; when the run ends, every test that left something is
 * listed on stderr and the process exits 1.
 *
 * An extension cannot fail a test through PHPUnit's public API, so the verdict
 * is the exit code, given from a shutdown function after PHPUnit's own report.
 */
final readonly class TempLeakExtension implements Extension
{
    /**
     * PHPStan's RuleTestCase compiles its container into this directory once and reuses it
     * for every rule test; sweeping it would recompile the container per test.
     */
    private const string PHPSTAN_RULE_TEST_CACHE = 'phpstan-tests';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $tmpdir    = getenv('TMPDIR');
        $shared    = rtrim(\is_string($tmpdir) && '' !== $tmpdir ? $tmpdir : '/tmp', '/');
        $directory = $shared . '/php-qa-ci-tests-' . \Safe\getmypid() . '-' . bin2hex(random_bytes(4));
        \Safe\mkdir($directory, 0o700);
        \Safe\putenv('TMPDIR=' . $directory);
        $_ENV['TMPDIR'] = $directory;
        if (sys_get_temp_dir() !== $directory) {
            throw new RuntimeException(\sprintf('TempLeakExtension could not redirect the temp directory: sys_get_temp_dir() was resolved as %s before the extension ran', sys_get_temp_dir()));
        }

        $ledger = new TempLeakLedger($directory, self::PHPSTAN_RULE_TEST_CACHE);
        $facade->registerSubscriber(new TempLeakSubscriber($ledger));
        register_shutdown_function(static function () use ($ledger, $directory): void {
            $ledger->sweep('the run, outside any test');
            $ledger->clear();
            \Safe\rmdir($directory);
            if ([] === $ledger->leaks()) {
                return;
            }

            \Safe\fwrite(\STDERR, \PHP_EOL . 'Tests left files in the temp directory (remove them in tearDown()):' . \PHP_EOL . '  ' . implode(\PHP_EOL . '  ', $ledger->leaks()) . \PHP_EOL);

            exit(1);
        });
    }
}
