<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\DeadCode;

use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\DeadCode\AnalysedFiles;
use LTS\PHPQA\Pipeline\Lane\DeadCode\BinEntryPointScripts;
use LTS\PHPQA\Pipeline\Lane\DeadCode\Dto\BinScanDto;
use LTS\PHPQA\Pipeline\Lane\DeadCode\GitIgnoredFiles;
use LTS\PHPQA\Tests\Support\TempDir;
use LTS\PHPQA\Tests\Support\UnremovableTree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A PHP script under bin/ has no `.php` suffix, so the dead-code detector sees it only when
 * it is declared with withDeadCodeEntryPoints(). One left off the list is analysed by nothing,
 * and every member only it reaches is reported dead (#277).
 *
 * @internal
 */
#[CoversClass(BinEntryPointScripts::class)]
#[UsesClass(IgnoredPaths::class)]
#[UsesClass(AnalysedFiles::class)]
#[UsesClass(BinScanDto::class)]
#[UsesClass(GitIgnoredFiles::class)]
#[Small]
final class BinEntryPointScriptsTest extends TestCase
{
    private const string PHP_SCRIPT = "#!/usr/bin/env php\n<?php\n\ndeclare(strict_types=1);\n\necho 'hi';\n";

    private const string CONSOLE = 'bin/console';

    private const string WORKER = 'bin/worker';

    private const string TOOL = 'bin/tool';

    private const string APP = 'bin/app';

    private const string VENDOR_TOOL = 'vendor/acme/tool/bin/tool';

    private const string VENDOR_TOOL_RELATIVE = '../vendor/acme/tool/bin/tool';

    private const string WORKER_TARGET = 'scripts/worker';

    private const string WORKER_RELATIVE = '../scripts/worker';

    private const string DOT_PHPUNIT = 'bin/.phpunit';

    private const string TOOLS = 'bin/tools';

    private const string SIMPLE_PHPUNIT = 'bin/.phpunit/phpunit-9.6/phpunit';

    private TempDir $project;

    /** @var list<string> withIgnoredPaths() entries, project-relative */
    private array $ignoredPaths = [];

    private GitIgnoredFiles $gitIgnored;

    private ?string $unremovable = null;

    protected function setUp(): void
    {
        $this->project    = TempDir::create('phpqa-bin-entry-points-test');
        $this->gitIgnored = GitIgnoredFiles::none();
    }

    protected function tearDown(): void
    {
        if (null !== $this->unremovable) {
            UnremovableTree::remove($this->unremovable);
        }

        $this->project->remove();
    }

    /** A file git ignores is never committed, so CI never has it: by its bin/ path or its target's. */
    #[Test]
    public function aFileGitIgnoresIsNotRequired(): void
    {
        $this->project->write(self::SIMPLE_PHPUNIT, self::PHP_SCRIPT);
        $this->project->write('bin/local.php', self::PHP_SCRIPT);
        $this->project->write(self::WORKER_TARGET, self::PHP_SCRIPT);
        $this->link(self::WORKER_RELATIVE, self::WORKER);
        $this->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->gitIgnored = GitIgnoredFiles::fromListing("bin/.phpunit/\0bin/local.php\0scripts/\0");

        self::assertSame([self::CONSOLE], $this->undeclared());
    }

    /** A file listed but not resolvable is reported as unreadable, never passed unseen. */
    #[Test]
    public function aPathThatCannotBeReadIsReported(): void
    {
        $this->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->unremovable = $this->project->path . '/bin/deep';
        UnremovableTree::plant($this->unremovable);

        $scan = $this->scan();

        self::assertSame([self::CONSOLE], $scan->undeclared);
        self::assertCount(1, $scan->unreadable);
        self::assertStringStartsWith('bin/deep/' . str_repeat('d', 200) . '/', $scan->unreadable[0]);
    }

    /**
     * A directory that is not the project's, or that the project ignores either way, is not
     * entered at all, so nothing in it, even a tree that cannot be read, is reached.
     */
    #[Test]
    #[DataProvider('treesNotEntered')]
    public function aTreeThatIsNotTheProjectsIsNotEntered(string $tree, ?string $linkedFrom, ?string $ignored, ?string $gitIgnored): void
    {
        $elsewhere = TempDir::create('phpqa-bin-entry-points-elsewhere');
        $planted   = str_starts_with($tree, '/') ? $elsewhere->path . $tree : $this->project->path . '/' . $tree;

        try {
            UnremovableTree::plant($planted . '/deep');
            \Safe\file_put_contents($planted . '/tool', self::PHP_SCRIPT);
            if (null !== $linkedFrom) {
                $this->link($planted, $linkedFrom);
            }

            $this->ignoredPaths = null === $ignored ? [] : [$ignored];
            $this->gitIgnored   = null === $gitIgnored ? GitIgnoredFiles::none() : GitIgnoredFiles::fromListing($gitIgnored . "/\0");

            self::assertEquals(new BinScanDto([], []), $this->scan());
        } finally {
            UnremovableTree::remove($planted . '/deep');
            $elsewhere->remove();
        }
    }

    /** @return iterable<string, array{string, ?string, ?string, ?string}> */
    public static function treesNotEntered(): iterable
    {
        yield 'a linked directory out of the project' => ['/tree', 'bin/linked', null, null];
        yield 'a linked directory in the vendor dir' => ['vendor/acme/tree', 'bin/linked', null, null];
        yield 'an ignored directory under bin/' => [self::DOT_PHPUNIT, null, self::DOT_PHPUNIT, null];
        yield 'a git-ignored directory under bin/' => [self::DOT_PHPUNIT, null, null, self::DOT_PHPUNIT];
        yield 'a linked directory whose target git ignores' => ['build/tools', self::TOOLS, null, 'build'];
    }

    /** A link git ignores by its own bin/ path is not required, whatever it reaches. */
    #[Test]
    public function aLinkGitIgnoresByItsBinPathIsNotRequired(): void
    {
        $this->project->write(self::WORKER_TARGET, self::PHP_SCRIPT);
        $this->link(self::WORKER_RELATIVE, self::WORKER);
        $this->gitIgnored = GitIgnoredFiles::fromListing(self::WORKER . "\0");

        self::assertSame([], $this->undeclared());
    }

    /** Reported paths sort as strings, across directories: `bin/a.php` before `bin/a/job`. */
    #[Test]
    public function undeclaredScriptsSortAcrossDirectories(): void
    {
        $this->project->write('bin/a/job', self::PHP_SCRIPT);
        $this->project->write('bin/a.php', self::PHP_SCRIPT);

        self::assertSame(['bin/a.php', 'bin/a/job'], $this->undeclared());
    }

    /** A link to the project root is walked once; the vendor dir inside it is still not entered. */
    #[Test]
    public function aLinkToTheProjectRootIsWalkedWithoutTheVendorDir(): void
    {
        $this->project->write(self::VENDOR_TOOL, self::PHP_SCRIPT);
        $this->project->write(self::WORKER_TARGET, self::PHP_SCRIPT);
        $this->link('..', 'bin/root');

        self::assertSame(['bin/root/' . self::WORKER_TARGET], $this->undeclared());
    }

    #[Test]
    public function aPhpScriptNotDeclaredIsReportedByItsProjectRelativePath(): void
    {
        $this->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->project->write('bin/worker', self::PHP_SCRIPT);

        self::assertSame(
            ['bin/worker'],
            $this->undeclared($this->project->path . '/' . self::CONSOLE),
        );
    }

    #[Test]
    public function everyUndeclaredScriptIsReportedInNameOrder(): void
    {
        $this->project->write('bin/zeta', self::PHP_SCRIPT);
        $this->project->write('bin/alpha', self::PHP_SCRIPT);

        self::assertSame(['bin/alpha', 'bin/zeta'], $this->undeclared());
    }

    /** Recognised by its shebang or its open tag, never by its name. */
    #[Test]
    #[DataProvider('phpScripts')]
    public function aPhpScriptIsRecognisedByItsContent(string $contents): void
    {
        $this->project->write(self::CONSOLE, $contents);

        self::assertSame([self::CONSOLE], $this->undeclared());
    }

    /** @return iterable<string, array{string}> */
    public static function phpScripts(): iterable
    {
        yield 'env php' => [self::PHP_SCRIPT];
        yield 'absolute php' => ["#!/usr/bin/php\n<?php\n"];
        yield 'versioned php' => ["#!/usr/local/bin/php8.5\n<?php\n"];
        yield 'env -S with flags' => ["#!/usr/bin/env -S php -d memory_limit=1G\n<?php\n"];
        yield 'open tag, no shebang' => ["<?php\n\ndeclare(strict_types=1);\n"];
        yield 'open tag on the shebang line' => ["#!/usr/bin/env php\n<?php declare(strict_types=1);\n"];
        yield 'shebang only, no open tag' => ["#!/usr/bin/env php\nplain text a PHP interpreter echoes\n"];
        yield 'BOM before the open tag' => ["\u{FEFF}<?php\n\necho 'hi';\n"];
        yield 'BOM before the shebang' => ["\u{FEFF}#!/usr/bin/env php\n<?php\n"];
        yield 'upper-case open tag' => ["<?PHP\n\necho 'hi';\n"];
        yield 'mixed-case open tag' => ["<?Php echo 'hi';\n"];
        yield 'short echo tag' => ["<?= 'hi' ?>\n"];
        yield 'blank lines before the open tag' => ["\n\n<?php\n"];
        yield 'env with an assignment' => ["#!/usr/bin/env FOO=1 php\n<?php\n"];
        yield 'env -S with an assignment' => ["#!/usr/bin/env -S FOO=1 php -d memory_limit=1G\n<?php\n"];
        yield 'env -u NAME' => ["#!/usr/bin/env -u XDEBUG_MODE php\n<?php\n"];
        yield 'phpdbg' => ["#!/usr/bin/phpdbg -qrr\n<?php\n"];
        yield 'versioned phpdbg through env' => ["#!/usr/bin/env phpdbg8.5\n<?php\n"];
        yield 'php-cgi' => ["#!/usr/bin/php-cgi\n<?php\n"];
        yield 'versioned php-cgi' => ["#!/usr/bin/php-cgi8.5 -q\n<?php\n"];
        yield 'CRLF shebang' => ["#!/usr/bin/env php\r\n<?php\r\n"];
    }

    /** A Bash script is not analysed by PHPStan, so declaring it would be wrong. */
    #[Test]
    #[DataProvider('otherScripts')]
    public function aScriptInAnotherLanguageIsNotRequired(string $contents): void
    {
        $this->project->write(self::TOOL, $contents);

        self::assertSame([], $this->undeclared());
    }

    /** @return iterable<string, array{string}> */
    public static function otherScripts(): iterable
    {
        yield 'bash' => ["#!/usr/bin/env bash\nset -euo pipefail\necho '<?php'\n"];
        yield 'sh' => ["#!/bin/sh\nexec php \"\$0.php\" \"\$@\"\n"];
        yield 'an interpreter whose name only starts with php' => ["#!/usr/bin/env phpdbg-wrapper\n"];
        yield 'python' => ["#!/usr/bin/env python3\nprint('<?php')\n"];
        yield 'no shebang, no open tag' => ["source me\n"];
        yield 'empty' => [''];
        yield 'php only in an env assignment' => ["#!/usr/bin/env PHP=php bash\necho hi\n"];
        yield 'php only as the argument of env -u' => ["#!/usr/bin/env -u php bash\necho hi\n"];
        yield 'php-fpm, which runs no script' => ["#!/usr/sbin/php-fpm\n"];
        yield 'an XML declaration' => ["<?xml version=\"1.0\"?>\n<root/>\n"];
    }

    /** `.php` in the name decides nothing: the content does. */
    #[Test]
    public function aDotPhpNameDoesNotMakeAFilePhp(): void
    {
        $this->project->write('bin/notes.php', "#!/usr/bin/env bash\necho hi\n");

        self::assertSame([], $this->undeclared());
    }

    /**
     * With Composer's bin-dir set to bin/, Composer writes a PHP proxy there for each package
     * binary. It holds no code of the project's own: it includes the package's script.
     */
    #[Test]
    public function aComposerBinaryProxyIsNotRequired(): void
    {
        $this->project->write(self::VENDOR_TOOL, self::PHP_SCRIPT);
        $this->project->write(self::TOOL, self::composerProxy(self::VENDOR_TOOL_RELATIVE));

        self::assertSame([], $this->undeclared());
    }

    /** Composer writes the path it includes relative to the proxy, or absolute when no relative one exists. */
    #[Test]
    public function aComposerBinaryProxyWithAnAbsoluteTargetIsNotRequired(): void
    {
        $target = $this->project->write(self::VENDOR_TOOL, self::PHP_SCRIPT);
        $this->project->write(self::TOOL, self::composerProxy($target));

        self::assertSame([], $this->undeclared());
    }

    /**
     * A path-repository package is installed as a link in the vendor dir, so its proxy includes
     * the package's script through that link. The proxy is still Composer's, with no code of its
     * own, and declaring it would analyse nothing but its include.
     */
    #[Test]
    public function aComposerBinaryProxyForAPathRepositoryPackageIsNotRequired(): void
    {
        $this->project->write('packages/tool/bin/tool', self::PHP_SCRIPT);
        $this->project->mkdir('vendor/acme');
        \Safe\symlink('../../packages/tool', $this->project->path . '/vendor/acme/tool');
        $this->project->write(self::TOOL, self::composerProxy(self::VENDOR_TOOL_RELATIVE));

        self::assertSame([], $this->undeclared());
    }

    /**
     * A file in Composer's proxy template, start and end, is one Composer wrote: it holds no code
     * of its own whatever it includes, and Composer rewrites it on every install.
     */
    #[Test]
    #[DataProvider('proxyTargets')]
    public function aComposerBinaryProxyIsNotRequiredWhateverItIncludes(string $included, ?string $writtenAt): void
    {
        if (null !== $writtenAt) {
            $this->project->write($writtenAt, self::PHP_SCRIPT);
        }

        $this->project->write(self::TOOL, self::composerProxy($included));

        self::assertSame([], $this->undeclared());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function proxyTargets(): iterable
    {
        yield 'a script outside the vendor dir' => [self::WORKER_RELATIVE, self::WORKER_TARGET];
        yield 'a package not installed yet' => [self::VENDOR_TOOL_RELATIVE, null];
    }

    /** Composer's proxy header quoted by the project's own script does not make it a proxy. */
    #[Test]
    #[DataProvider('scriptsQuotingTheProxyHeader')]
    public function aFirstPartyScriptQuotingTheProxyHeaderIsRequired(string $contents): void
    {
        $this->project->write(self::VENDOR_TOOL, self::PHP_SCRIPT);
        $this->project->write(self::TOOL, $contents);

        self::assertSame([self::TOOL], $this->undeclared());
    }

    /** @return iterable<string, array{string}> */
    public static function scriptsQuotingTheProxyHeader(): iterable
    {
        yield 'in a docblock' => ["#!/usr/bin/env php\n<?php\n\n/**\n * Rebuilds a Proxy PHP file generated by Composer when the bin-dir moves.\n */\n\necho 'hi';\n"];
        yield 'as a string' => ["#!/usr/bin/env php\n<?php\n\nconst HEADER = 'Proxy PHP file generated by Composer';\n"];
        yield 'with the whole header, then code of its own' => [self::composerProxy(self::VENDOR_TOOL_RELATIVE) . "\nfunction ownCode(): void {}\n"];
    }

    /**
     * A link to the project's own script elsewhere: that script has no `.php` suffix either, so
     * nothing analyses it unless the link is declared.
     */
    #[Test]
    public function aSymlinkToAFirstPartyPhpScriptIsRequired(): void
    {
        $this->project->write(self::WORKER_TARGET, self::PHP_SCRIPT);
        $this->link(self::WORKER_RELATIVE, self::WORKER);

        self::assertSame([self::WORKER], $this->undeclared());
    }

    /** The detector is given the declared path and reads the file through the link. */
    #[Test]
    public function aSymlinkIsDeclaredByItsBinPath(): void
    {
        $this->project->write(self::WORKER_TARGET, self::PHP_SCRIPT);
        $this->link(self::WORKER_RELATIVE, self::WORKER);

        self::assertSame([], $this->undeclared($this->project->path . '/' . self::WORKER));
    }

    #[Test]
    public function aSymlinkIsDeclaredByItsTargetPath(): void
    {
        $this->project->write(self::WORKER_TARGET, self::PHP_SCRIPT);
        $this->link(self::WORKER_RELATIVE, self::WORKER);

        self::assertSame([], $this->undeclared($this->project->path . '/' . self::WORKER_TARGET));
    }

    /** A link is judged by its target's content, exactly as a regular file is. */
    #[Test]
    public function aSymlinkToAFirstPartyBashScriptIsNotRequired(): void
    {
        $this->project->write(self::WORKER_TARGET, "#!/usr/bin/env bash\necho hi\n");
        $this->link(self::WORKER_RELATIVE, self::WORKER);

        self::assertSame([], $this->undeclared());
    }

    /** Composer links a package binary into bin/ this way; the consumer cannot change that code. */
    #[Test]
    public function aSymlinkIntoTheVendorDirIsNotRequired(): void
    {
        $target = $this->project->write(self::VENDOR_TOOL, self::PHP_SCRIPT);
        $this->link($target, self::TOOL);

        self::assertSame([], $this->undeclared());
    }

    /** The vendor dir is the one composer.json configures, not any directory named vendor. */
    #[Test]
    public function aConfiguredVendorDirIsTheOneSkipped(): void
    {
        $this->project->write('composer.json', '{"config": {"vendor-dir": "lib/deps"}}');
        $this->project->write('lib/deps/acme/tool/bin/tool', self::PHP_SCRIPT);
        $this->project->write('vendor/own/worker', self::PHP_SCRIPT);
        $this->link('../lib/deps/acme/tool/bin/tool', self::TOOL);
        $this->link('../vendor/own/worker', self::WORKER);

        self::assertSame([self::WORKER], $this->undeclared());
    }

    /** Code outside the project is not the project's to change, nor its to analyse. */
    #[Test]
    public function aSymlinkOutOfTheProjectIsNotRequired(): void
    {
        $elsewhere = TempDir::create('phpqa-bin-entry-points-elsewhere');

        try {
            $this->link($elsewhere->write('tool', self::PHP_SCRIPT), self::TOOL);

            self::assertSame([], $this->undeclared());
        } finally {
            $elsewhere->remove();
        }
    }

    /** A dangling link holds no code, so nothing goes unanalysed: there is nothing to declare. */
    #[Test]
    public function aDanglingSymlinkIsNotRequired(): void
    {
        $this->link('../scripts/gone', self::WORKER);

        self::assertEquals(new BinScanDto([], []), $this->scan(), 'a dangling link is not an unreadable path either');
    }

    /** The vendor dir is matched as a directory, not as a prefix of a sibling's name. */
    #[Test]
    public function aDirectoryBesideTheVendorDirIsTheProjects(): void
    {
        $this->project->mkdir('vendor');
        $this->project->write('vendorExtra/worker', self::PHP_SCRIPT);
        $this->link('../vendorExtra/worker', self::WORKER);

        self::assertSame([self::WORKER], $this->undeclared());
    }

    #[Test]
    public function aSymlinkToAnEmptyDirectoryHoldsNothingToDeclare(): void
    {
        $this->project->mkdir('scripts/tools');
        $this->link('../scripts/tools', self::TOOLS);

        self::assertSame([], $this->undeclared());
    }

    /** A linked directory is read like a real one: each script in it is judged by its path through bin/. */
    #[Test]
    public function aScriptInALinkedDirectoryIsRequired(): void
    {
        $this->project->write('scripts/tools/sync', self::PHP_SCRIPT);
        $this->link('../scripts/tools', self::TOOLS);

        self::assertSame(['bin/tools/sync'], $this->undeclared());
    }

    /** A link back up the tree is read once, not followed for ever. */
    #[Test]
    public function aLinkLoopIsReadOnce(): void
    {
        $this->project->write(self::CONSOLE, self::PHP_SCRIPT);
        $this->link('.', 'bin/again');

        self::assertSame([self::CONSOLE], $this->undeclared());
    }

    /** A first-party script in a subdirectory is analysed by nothing exactly as one directly under bin/ is. */
    #[Test]
    #[DataProvider('scriptsInSubdirectories')]
    public function aScriptInASubdirectoryOfBinIsRequired(string $script): void
    {
        $this->project->write($script, self::PHP_SCRIPT);

        self::assertSame([$script], $this->undeclared());
    }

    /** @return iterable<string, array{string}> */
    public static function scriptsInSubdirectories(): iterable
    {
        yield 'one level' => ['bin/cron/job'];
        yield 'two levels' => ['bin/cron/nightly/report'];
        yield 'a dot directory' => [self::SIMPLE_PHPUNIT];
    }

    #[Test]
    public function aScriptInASubdirectoryIsDeclaredByItsPath(): void
    {
        $this->project->write('bin/cron/job', self::PHP_SCRIPT);

        self::assertSame([], $this->undeclared($this->project->path . '/bin/cron/job'));
    }

    /** withIgnoredPaths() is the project's own statement that a tree is not analysed. */
    #[Test]
    public function aScriptUnderAnIgnoredPathIsNotRequired(): void
    {
        $this->project->write(self::SIMPLE_PHPUNIT, self::PHP_SCRIPT);
        $this->project->write(self::CONSOLE, self::PHP_SCRIPT);

        $this->ignoredPaths = [self::DOT_PHPUNIT];

        self::assertSame([self::CONSOLE], $this->undeclared());
    }

    /** The ignored path may name the link's target rather than its bin/ path. */
    #[Test]
    public function aLinkToAnIgnoredPathIsNotRequired(): void
    {
        $this->project->write(self::WORKER_TARGET, self::PHP_SCRIPT);
        $this->link(self::WORKER_RELATIVE, self::WORKER);
        $this->ignoredPaths = ['scripts'];

        self::assertSame([], $this->undeclared());
    }

    /** A vendor dir configured inside bin/ holds other packages' code, as it does anywhere else. */
    #[Test]
    public function aVendorDirInsideBinIsNotRequired(): void
    {
        $this->project->write('composer.json', '{"config": {"vendor-dir": "bin/vendor"}}');
        $this->project->write('bin/vendor/acme/tool/bin/tool', self::PHP_SCRIPT);

        self::assertSame([], $this->undeclared());
    }

    /**
     * A `.php` file under src/ or tests/ is already analysed by the lane's own run, so a link to
     * it hides nothing.
     */
    #[Test]
    #[DataProvider('analysedTargets')]
    public function aSymlinkToAFileTheLaneAlreadyAnalysesIsNotRequired(string $target): void
    {
        $this->project->write($target, self::PHP_SCRIPT);
        $this->link('../' . $target, self::APP);

        self::assertSame([], $this->undeclared());
    }

    /** @return iterable<string, array{string}> */
    public static function analysedTargets(): iterable
    {
        yield 'under src/' => ['src/app.php'];
        yield 'under tests/' => ['tests/Support/fixture.php'];
    }

    /** PHPStan reads only `.php` files from a directory it scans, so an extensionless target there is unanalysed. */
    #[Test]
    #[DataProvider('unanalysedTargets')]
    public function aSymlinkToAFileTheLaneDoesNotAnalyseIsRequired(string $target): void
    {
        $this->project->write($target, self::PHP_SCRIPT);
        $this->link('../' . $target, self::APP);

        self::assertSame([self::APP], $this->undeclared());
    }

    /** @return iterable<string, array{string}> */
    public static function unanalysedTargets(): iterable
    {
        yield 'extensionless under src/' => ['src/app'];
        yield 'extensionless under tests/' => ['tests/app'];
        yield '.php outside src/ and tests/' => ['scripts/app.php'];
        yield 'a directory merely named like src/' => ['srcExtra/app.php'];
    }

    /** A declared directory is scanned the same way: its `.php` files are analysed, nothing else in it is. */
    #[Test]
    public function aDeclaredDirectoryCoversItsPhpFilesOnly(): void
    {
        $this->project->write('bin/tool.php', self::PHP_SCRIPT);
        $this->project->write(self::TOOL, self::PHP_SCRIPT);

        self::assertSame([self::TOOL], $this->undeclared($this->project->path . '/bin'));
    }

    #[Test]
    public function aProjectWithNoBinDirectoryHasNothingToDeclare(): void
    {
        self::assertEquals(new BinScanDto([], []), $this->scan());
    }

    /** A declaration names the same file through any spelling of its path. */
    #[Test]
    public function aDeclarationMatchesTheScriptThroughAnUnnormalisedPath(): void
    {
        $this->project->write(self::CONSOLE, self::PHP_SCRIPT);

        self::assertSame([], $this->undeclared($this->project->path . '/bin/../bin/console'));
    }

    /** A declaration of a file that does not exist matches nothing and is not an error here. */
    #[Test]
    public function aDeclarationOfAMissingFileDeclaresNothing(): void
    {
        $this->project->write(self::CONSOLE, self::PHP_SCRIPT);

        self::assertSame([self::CONSOLE], $this->undeclared($this->project->path . '/bin/gone'));
    }

    /** What Composer's BinaryInstaller writes for a package binary whose script starts `#!`. */
    private static function composerProxy(string $binPath): string
    {
        $exported = str_starts_with($binPath, '/') ? var_export($binPath, true) : "__DIR__ . '/" . $binPath . "'";

        return "#!/usr/bin/env php\n<?php\n\n/**\n * Proxy PHP file generated by Composer\n *\n"
            . ' * This file includes the referenced bin path (' . $binPath . ")\n"
            . " * using a stream wrapper to prevent the shebang from being output on PHP<8\n *\n * @generated\n */\n\n"
            . "namespace Composer;\n\n\$GLOBALS['_composer_bin_dir'] = __DIR__;\n\n"
            . "if (PHP_VERSION_ID < 80000) {\n    return include(\"phpvfscomposer://\" . " . $exported . ");\n}\n\n"
            . 'return include ' . $exported . ";\n";
    }

    /** Creates bin/<name> as a link to $target, which may be relative to bin/. */
    private function link(string $target, string $link): void
    {
        $this->project->mkdir('bin');
        \Safe\symlink($target, $this->project->path . '/' . $link);
    }

    /**
     * The lane's own analysed paths are src/, tests/ and the declared entry points, as
     * DeadCodeTool passes them with PHPStan's default parameters.
     *
     * @return list<string>
     */
    private function undeclared(string ...$declared): array
    {
        return $this->scan(...$declared)->undeclared;
    }

    private function scan(string ...$declared): BinScanDto
    {
        $root = $this->project->path;

        return new BinEntryPointScripts(
            new IgnoredPaths($root, ...$this->ignoredPaths),
            new AnalysedFiles([$root . '/src', $root . '/tests', ...array_values($declared)], [], ['php']),
            $this->gitIgnored,
        )->scan($root);
    }
}
