<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\ShellCheck;

use LTS\PHPQA\Pipeline\Lane\ShellCheck\ShellFileFinder;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ShellFileFinder::class)]
#[Small]
final class ShellFileFinderTest extends TestCase
{
    private const string BASH_SHEBANG = "#!/usr/bin/env bash\ntrue\n";

    private const string BODY = "true\n";

    private const string BUILD = 'scripts/build.bash';

    private const string LEGACY = 'scripts/legacy.sh';

    private const string WRAPPER = 'bin/phpstan';

    private const string GENERATED = 'scripts-generated/build.bash';

    private const string DEPLOY_RUN = 'deploy/run';

    private const string A_BASH = 'a.bash';

    private const string M_BASH = 'm.bash';

    private const string Z_BASH = 'z.bash';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-shellfiles');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function aShellExtensionIsEnoughOnItsOwn(): void
    {
        $this->project->write(self::BUILD, self::BODY);
        $this->project->write(self::LEGACY, self::BODY);

        self::assertSame([self::BUILD, self::LEGACY], $this->find(self::BUILD, self::LEGACY));
    }

    /**
     * The wrappers under bin/ carry no extension, which is exactly why the CI
     * job had to name all four by hand.
     */
    #[Test]
    #[DataProvider('shellShebangs')]
    public function anExtensionlessFileIsFoundByItsShebang(string $shebang): void
    {
        $this->project->write(self::WRAPPER, $shebang . "\ntrue\n");

        self::assertSame([self::WRAPPER], $this->find(self::WRAPPER));
    }

    /** @return iterable<string, array{string}> */
    public static function shellShebangs(): iterable
    {
        yield 'env bash' => ['#!/usr/bin/env bash'];

        yield 'absolute bash' => ['#!/bin/bash'];

        yield 'plain sh' => ['#!/bin/sh'];

        yield 'dash' => ['#!/bin/dash'];

        yield 'ksh' => ['#!/usr/bin/ksh'];

        yield 'flags after the interpreter' => ['#!/bin/bash -eu'];
    }

    #[Test]
    #[DataProvider('nonShellFiles')]
    public function aFileThatIsNeitherIsLeftAlone(string $relative, string $contents): void
    {
        $this->project->write($relative, $contents);

        self::assertSame([], $this->find($relative));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonShellFiles(): iterable
    {
        yield 'a php script' => ['bin/qa', "#!/usr/bin/env php\n<?php\n"];

        yield 'a python script' => ['bin/hook.py', "#!/usr/bin/env python3\nprint(1)\n"];

        yield 'no shebang at all' => ['README.md', "# hello\n"];

        yield 'an empty file' => ['bin/placeholder', ''];

        yield 'a hash comment that is not a shebang' => ['Makefile', "# not a shebang\nall:\n"];
    }

    /**
     * The repository is the contract, not the working tree: an untracked
     * scratch script is never in the tracked list, so it can never be checked.
     */
    #[Test]
    public function onlyTrackedFilesAreEverCandidates(): void
    {
        $this->project->write('untracked/scratch/experiment.bash', self::BODY);
        $this->project->write(self::BUILD, self::BODY);

        self::assertSame([self::BUILD], $this->find(self::BUILD));
    }

    /**
     * git lists what the index holds, which on a dirty tree can name a file
     * that is not on disk. ShellCheck exits 2 on one of those, and exit 2 is a
     * crash, so a dirty tree would look like a broken lane.
     */
    #[Test]
    public function aTrackedFileMissingFromTheWorkingTreeIsSkippedRatherThanHandedToTheChecker(): void
    {
        $this->project->write(self::BUILD, self::BODY);

        self::assertSame([self::BUILD], $this->find(self::BUILD, 'scripts/deleted.bash'));
    }

    #[Test]
    public function ignoredPathsSubtractFromWhateverWasDiscovered(): void
    {
        $this->project->write(self::BUILD, self::BODY);
        $this->project->write('tests/assets/broken.bash', self::BODY);

        $found = new ShellFileFinder($this->project->path)->find(
            [self::BUILD, 'tests/assets/broken.bash'],
            [],
            'tests/assets',
        );

        self::assertSame([self::BUILD], $found);
    }

    #[Test]
    public function anIgnoredPathMatchesOnADirectoryBoundaryNotAStringPrefix(): void
    {
        $this->project->write(self::BUILD, self::BODY);
        $this->project->write(self::GENERATED, self::BODY);

        $found = new ShellFileFinder($this->project->path)->find(
            [self::BUILD, self::GENERATED],
            [],
            'scripts',
        );

        self::assertSame([self::GENERATED], $found);
    }

    /**
     * A glob list replaces discovery outright: the project has said which
     * files it means, so a matched file is checked whatever it looks like.
     */
    #[Test]
    public function anExplicitGlobListReplacesDiscoveryEntirely(): void
    {
        $this->project->write(self::BUILD, self::BODY);
        $this->project->write(self::WRAPPER, self::BASH_SHEBANG);
        $this->project->write(self::DEPLOY_RUN, self::BASH_SHEBANG);

        $found = new ShellFileFinder($this->project->path)->find(
            [self::BUILD, self::WRAPPER, self::DEPLOY_RUN],
            ['deploy/*'],
        );

        self::assertSame([self::DEPLOY_RUN], $found);
    }

    #[Test]
    public function aGlobStillYieldsToTheIgnoredPaths(): void
    {
        $this->project->write(self::BUILD, self::BODY);
        $this->project->write('scripts/vendored/third-party.bash', self::BODY);

        $found = new ShellFileFinder($this->project->path)->find(
            [self::BUILD, 'scripts/vendored/third-party.bash'],
            ['scripts/*.bash', 'scripts/*/*.bash'],
            'scripts/vendored',
        );

        self::assertSame([self::BUILD], $found);
    }

    #[Test]
    public function theResultIsSortedSoTheCommandIsTheSameOnEveryHost(): void
    {
        $this->project->write(self::Z_BASH, self::BODY);
        $this->project->write(self::A_BASH, self::BODY);
        $this->project->write(self::M_BASH, self::BODY);

        self::assertSame(
            [self::A_BASH, self::M_BASH, self::Z_BASH],
            $this->find(self::Z_BASH, self::M_BASH, self::A_BASH),
        );
    }

    /** @return list<string> */
    private function find(string ...$tracked): array
    {
        return new ShellFileFinder($this->project->path)->find(array_values($tracked), []);
    }
}
