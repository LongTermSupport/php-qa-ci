<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a workflow change stops reporting a status check the branch requires".
 *
 * Branch protection on php8.4 requires the checks named below, by their exact job name. A job that
 * is renamed, removed or turned into a matrix (whose legs report as "<name> (<value>)") leaves the
 * required check pending for ever, so every pull request is blocked from merging while CI is green.
 * The names are the repository's required contexts; a change to them is an Owner setting, and this
 * list moves with it.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class CiReportsTheRequiredStatusChecksTest extends TestCase
{
    private const string WORKFLOW = __DIR__ . '/../../.github/workflows/ci.yml';

    /** The status checks php8.4's branch protection requires. */
    private const array REQUIRED_CHECKS = ['QA Pipeline', 'ShellCheck (severity=warning)'];

    #[Test]
    public function everyRequiredCheckIsTheLiteralNameOfAJob(): void
    {
        $jobNames = $this->jobNames(\Safe\file_get_contents(self::WORKFLOW));

        foreach (self::REQUIRED_CHECKS as $check) {
            self::assertContains($check, $jobNames, \sprintf('No job in ci.yml reports the required status check "%s"; a pull request to php8.4 could never merge', $check));
        }
    }

    #[Test]
    public function aMatrixJobDoesNotReportItsBareName(): void
    {
        $workflow = "jobs:\n  qa:\n    name: QA Pipeline (PHP \${{ matrix.php }})\n    strategy:\n      matrix:\n        php: ['8.4']\n";

        self::assertNotContains('QA Pipeline', $this->jobNames($workflow));
    }

    /** @return list<string> the job names, at a job's own indentation, as GitHub reports them */
    private function jobNames(string $workflow): array
    {
        \Safe\preg_match_all('/^ {4}name: (.+)$/m', $workflow, $matches);

        return array_values(array_map(trim(...), $matches[1]));
    }
}
