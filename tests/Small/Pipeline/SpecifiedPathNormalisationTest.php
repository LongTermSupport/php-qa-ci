<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Pins qaNormaliseSpecifiedPath (includes/functions.inc.bash): the `-p` path
 * must accept BOTH project-root-relative and ABSOLUTE paths. bin/qa composes
 * `$projectRoot/$specifiedPath`, so before this function existed an absolute
 * `-p /project/src/Foo.php` produced `/project//project/src/Foo.php` — "Path
 * does not exist", exit 1. Automated callers (editor/agent lint hooks such as
 * the hooks-daemon's lint_on_edit) pass absolute paths, so this must work.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class SpecifiedPathNormalisationTest extends TestCase
{
    private const string FUNCTIONS = __DIR__ . '/../../../includes/functions.inc.bash';

    public function testRelativePathPassesThroughUnchanged(): void
    {
        [$exitCode, $output] = $this->normalise('src/Some/File.php', '/project/root');

        self::assertSame(0, $exitCode);
        self::assertSame('src/Some/File.php', $output);
    }

    public function testAbsolutePathUnderProjectRootIsRelativised(): void
    {
        [$exitCode, $output] = $this->normalise('/project/root/src/Some/File.php', '/project/root');

        self::assertSame(0, $exitCode);
        self::assertSame('src/Some/File.php', $output);
    }

    public function testProjectRootTrailingSlashIsTolerated(): void
    {
        [$exitCode, $output] = $this->normalise('/project/root/tests/FooTest.php', '/project/root/');

        self::assertSame(0, $exitCode);
        self::assertSame('tests/FooTest.php', $output);
    }

    public function testAbsolutePathOutsideProjectRootFails(): void
    {
        [$exitCode, $output] = $this->normalise('/somewhere/else/File.php', '/project/root');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('outside the project root', $output);
    }

    public function testAbsolutePathPrefixSharingIsNotMistakenForContainment(): void
    {
        // /project/rootbeer shares the string prefix but is NOT inside /project/root.
        [$exitCode, $output] = $this->normalise('/project/rootbeer/File.php', '/project/root');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('outside the project root', $output);
    }

    public function testAbsolutePathEqualToProjectRootFails(): void
    {
        // -p pointing at the root itself is meaningless (that is a full run).
        [$exitCode, $output] = $this->normalise('/project/root', '/project/root');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('project root itself', $output);
    }

    /**
     * @return array{int, string}
     */
    private function normalise(string $path, string $projectRoot): array
    {
        $harness = <<<'BASH'
            set -u
            source "$1"
            qaNormaliseSpecifiedPath "$2" "$3"
            BASH;

        $harnessFile = \Safe\tempnam(sys_get_temp_dir(), 'qaNormPath');
        \Safe\file_put_contents($harnessFile, $harness . "\n");

        $cmd = \sprintf(
            'bash %s %s %s %s 2>&1',
            escapeshellarg($harnessFile),
            escapeshellarg(self::FUNCTIONS),
            escapeshellarg($path),
            escapeshellarg($projectRoot),
        );

        $output   = [];
        $exitCode = 0;
        \Safe\exec($cmd, $output, $exitCode);
        \Safe\unlink($harnessFile);

        $exitCode ??= 0;
        $outputLines = [];
        foreach ($output ?? [] as $line) {
            if (\is_string($line)) {
                $outputLines[] = $line;
            }
        }

        return [$exitCode, implode("\n", $outputLines)];
    }
}
