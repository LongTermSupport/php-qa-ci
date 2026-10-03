<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Defence for the class "a workflow this repository runs or ships pins an action to a major whose
 * runtime GitHub has deprecated".
 *
 * GitHub forces a deprecated Node runtime forward and prints an annotation on every run, which
 * fails nothing until the old runtime is removed and the action stops running at all. The
 * templates under templates/github-actions are copied into consuming projects, so a stale pin
 * there spreads.
 *
 * Every action used is listed with the lowest major that runs on a supported runtime. An action
 * missing from the list fails too, so a new one is classified when it is added rather than
 * discovered at the next deprecation.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class WorkflowActionRuntimeTest extends TestCase
{
    /** The workflows this repository runs, and the ones it ships. */
    private const array WORKFLOW_DIRS = [
        __DIR__ . '/../../.github',
        __DIR__ . '/../../templates/github-actions',
    ];

    /**
     * Each action to the lowest major on Node 24, from its release notes; 0 for an action that
     * runs as a container or a composite, where the Node runtime does not apply.
     *
     * @var array<string, int>
     */
    private const array MINIMUM_MAJOR = [
        'actions/cache'                         => 5,
        'actions/checkout'                      => 5,
        'actions/download-artifact'             => 7,
        'actions/upload-artifact'               => 6,
        'irongut/CodeCoverageSummary'           => 0,
        'marocchino/sticky-pull-request-comment' => 3,
        'peter-evans/create-pull-request'       => 8,
        'shivammathur/setup-php'                => 2,
    ];

    /** `uses: owner/repo@vN`, the shape every remote action reference takes here. */
    private const string USES = '/^\s*-?\s*uses:\s*([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)@v(\d+)/';

    public function testEveryActionIsPinnedToASupportedRuntime(): void
    {
        $problems = [];
        foreach ($this->workflowFiles() as $file) {
            foreach ($this->stalePins(\Safe\file_get_contents($file)) as $problem) {
                $problems[] = basename(\dirname($file)) . '/' . basename($file) . ': ' . $problem;
            }
        }

        self::assertSame([], $problems);
    }

    /** The guard proven to fire on an old major and on an action nobody has classified. */
    public function testTheGuardReportsAStaleMajorAndAnUnclassifiedAction(): void
    {
        self::assertSame(
            ['actions/checkout@v4 is below v5', 'some/new-action@v1 is not classified'],
            $this->stalePins("      - uses: actions/checkout@v4\n      - uses: some/new-action@v1\n      - uses: actions/checkout@v7\n"),
        );
    }

    /** @return list<string> */
    private function stalePins(string $workflow): array
    {
        $problems = [];
        foreach (explode("\n", $workflow) as $line) {
            if (1 !== \Safe\preg_match(self::USES, $line, $match) || !isset($match[1], $match[2])) {
                continue;
            }

            $action = $match[1];
            $major  = (int)$match[2];
            $floor  = self::MINIMUM_MAJOR[$action] ?? null;
            if (null === $floor) {
                $problems[] = \sprintf('%s@v%d is not classified', $action, $major);
            } elseif ($major < $floor) {
                $problems[] = \sprintf('%s@v%d is below v%d', $action, $major, $floor);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $files = [];
        foreach (self::WORKFLOW_DIRS as $dir) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (\in_array($file->getExtension(), ['yml', 'yaml'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);
        self::assertNotSame([], $files, 'No workflow files found — the directory paths are wrong.');

        return $files;
    }
}
