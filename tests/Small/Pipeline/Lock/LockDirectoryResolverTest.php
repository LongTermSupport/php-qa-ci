<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lock;

use LTS\PHPQA\Pipeline\Lock\LockDirectoryResolver;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(LockDirectoryResolver::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[Small]
final class LockDirectoryResolverTest extends TestCase
{
    private const string PROJECT = '/tmp';

    private const string FALLBACK = '/tmp/qaConfig/.qa-lock';

    #[Test]
    public function aWorkTreeRootLocksInsideTheCommonGitDirectory(): void
    {
        $runner = new FakeProcessRunner()->willSucceed("/tmp\n/srv/repo/.git\n");

        $dir = new LockDirectoryResolver($runner)->resolve(self::PROJECT);

        self::assertSame('/srv/repo/.git/php-qa-ci-lock', $dir);
        self::assertSame(
            [
                'env', '-u', 'GIT_DIR', '-u', 'GIT_WORK_TREE', '-u', 'GIT_COMMON_DIR', '-u', 'GIT_INDEX_FILE', '-u', 'GIT_PREFIX',
                'git', 'rev-parse', '--path-format=absolute', '--show-toplevel', '--git-common-dir',
            ],
            $runner->lastSpec()->command,
            "git runs with the caller's repository-selecting variables removed, so the project root alone decides",
        );
        self::assertSame(self::PROJECT, $runner->lastSpec()->cwd);
    }

    #[Test]
    public function aProjectOutsideGitKeepsItsOwnQaConfigLockDirectory(): void
    {
        $runner = new FakeProcessRunner()->willFail(128, 'fatal: not a git repository');

        self::assertSame(self::FALLBACK, new LockDirectoryResolver($runner)->resolve(self::PROJECT));
    }

    #[Test]
    public function aProjectInsideSomeoneElsesWorkTreeKeepsItsOwnLockDirectory(): void
    {
        $runner = new FakeProcessRunner()->willSucceed("/srv/repo\n/srv/repo/.git\n");

        self::assertSame(self::FALLBACK, new LockDirectoryResolver($runner)->resolve(self::PROJECT));
    }

    #[Test]
    public function aRelativeCommonDirectoryFallsBack(): void
    {
        $runner = new FakeProcessRunner()->willReturn(new ProcessResultDto(0, "/tmp\n.git\n", "/tmp\n.git\n"));

        self::assertSame(self::FALLBACK, new LockDirectoryResolver($runner)->resolve(self::PROJECT));
    }
}
