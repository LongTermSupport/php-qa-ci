<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a committed file derived from the locked dependency versions, which
 * the build guards as current, that the job moving those versions does not regenerate".
 *
 * The update-deps workflow moves composer.lock and then runs the full pipeline. Any committed
 * file generated from what the lock installs, and held current by a test, goes stale in that
 * same run, so the pipeline fails and the weekly pull request is never opened — every week, until
 * a person regenerates the file by hand. The active-defences region of CLAUDE.md is one: it
 * counts the rules each installed PHPStan extension registers (AgentContextIsCurrentTest).
 *
 * Each such file is listed here with the command that regenerates it, and the workflow must run
 * that command after the update and before it decides whether anything changed.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class UpdateDepsRegeneratesLockDerivedFilesTest extends TestCase
{
    /** The job that moves the locks. */
    private const string WORKFLOW = __DIR__ . '/../../.github/workflows/update-deps.yml';

    /** The step that moves composer.lock. */
    private const string LOCK_UPDATE = 'composer update --no-interaction';

    /** The step that decides whether there is anything to propose. */
    private const string CHANGE_DETECTION = 'name: Detect changes';

    /**
     * Each lock-derived file the build guards as current, to the command that regenerates it.
     *
     * @var array<string, string>
     */
    private const array LOCK_DERIVED = [
        'CLAUDE.md' => 'php bin/rules . --write-agent-summary=CLAUDE.md',
    ];

    public function testTheUpdateRegeneratesEveryLockDerivedFileBeforeDetectingChanges(): void
    {
        self::assertSame([], $this->unregenerated(\Safe\file_get_contents(self::WORKFLOW)));
    }

    /** The guard proven to fire: a workflow without the step, and one running it too late. */
    public function testTheGuardReportsAMissingOrLateRegeneration(): void
    {
        $update     = "      - run: composer update --no-interaction\n";
        $detect     = "      - name: Detect changes\n";
        $regenerate = "      - run: php bin/rules . --write-agent-summary=CLAUDE.md\n";

        self::assertSame(['CLAUDE.md: not regenerated'], $this->unregenerated($update . $detect));
        self::assertSame(
            ['CLAUDE.md: regenerated outside the window between the lock update and change detection'],
            $this->unregenerated($update . $detect . $regenerate),
        );
        self::assertSame([], $this->unregenerated($update . $regenerate . $detect));
    }

    /** @return list<string> */
    private function unregenerated(string $workflow): array
    {
        $update   = strpos($workflow, self::LOCK_UPDATE);
        $detect   = strpos($workflow, self::CHANGE_DETECTION);
        $problems = [];
        foreach (self::LOCK_DERIVED as $file => $command) {
            $at = strpos($workflow, $command);
            if (false === $at) {
                $problems[] = $file . ': not regenerated';

                continue;
            }

            if (false === $update || false === $detect || $at < $update || $at > $detect) {
                $problems[] = $file . ': regenerated outside the window between the lock update and change detection';
            }
        }

        return $problems;
    }
}
