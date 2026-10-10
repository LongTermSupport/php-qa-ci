<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support\ProjectTreeLeak;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Defence for the class "a test writes into the checkout under test" (issue #132).
 *
 * A mutant that turns a sandboxed path into a relative one sends a test's
 * writes into the working directory, which under Infection is the project
 * root; the test's own assertions can still pass, so the mutant escapes and
 * the checkout keeps the debris. ProjectTreeLedger reads the tree before the
 * first test and after every test, and moves whatever appeared out of it into
 * var/qa/project-tree-leaks/<pid>/. When the run ends, every test that wrote
 * into, or deleted from, the tree is listed on stderr and the process exits 1,
 * which Infection counts as the mutant killed.
 *
 * The project root is this repository's, wherever the run's working directory
 * is. An extension cannot fail a test through PHPUnit's public API, so the
 * verdict is the exit code, as for TempLeakExtension. This extension is listed
 * before that one in phpunit.xml so its shutdown function runs first, and it
 * defers its own exit to the end of shutdown so TempLeakExtension still
 * reports and removes its directory.
 */
final readonly class ProjectTreeLeakExtension implements Extension
{
    /**
     * The suite's own output, Composer's, git's, and scratch space other
     * processes (agents, their worktrees) write to while the suite runs.
     *
     * @var list<string>
     */
    private const array UNWATCHED = ['var', 'vendor', '.git', 'untracked'];

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $root   = \dirname(__DIR__, 3);
        $ledger = new ProjectTreeLedger($root, $root . '/var/qa/project-tree-leaks/' . \Safe\getmypid(), ...self::UNWATCHED);
        $facade->registerSubscriber(new ProjectTreeLeakSubscriber($ledger));
        register_shutdown_function(static function () use ($ledger): void {
            $ledger->sweep('the run, outside any test');
            if ([] === $ledger->leaks()) {
                return;
            }

            \Safe\fwrite(\STDERR, \PHP_EOL . 'Tests wrote into the project tree (write under sys_get_temp_dir(), never a relative path):' . \PHP_EOL . '  ' . implode(\PHP_EOL . '  ', $ledger->leaks()) . \PHP_EOL);
            register_shutdown_function(static function (): never {
                exit(1);
            });
        });
    }
}
