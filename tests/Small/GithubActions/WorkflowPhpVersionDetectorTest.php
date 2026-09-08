<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\GithubActions;

use LTS\PHPQA\GithubActions\WorkflowPhpVersionDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WorkflowPhpVersionDetector::class)]
#[Small]
final class WorkflowPhpVersionDetectorTest extends TestCase
{
    private const string LOOP_CURRENT = "          PHP_VERSION=8.5\n          for V in 8.5 8.4 8.3; do\n            if [[ \"\$CONSTRAINT\" == *\"\$V\"* ]]; then PHP_VERSION=\"\$V\"; break; fi\n          done\n";

    private const string LOOP_STALE = "          PHP_VERSION=8.3\n          for V in 8.4 8.3 8.2; do\n            if [[ \"\$CONSTRAINT\" == *\"\$V\"* ]]; then PHP_VERSION=\"\$V\"; break; fi\n          done\n";

    private const string CHAIN_CURRENT = "            if [[ \"\$PHP_CONSTRAINT\" == *\"8.5\"* ]]; then\n              PHP_VERSION=\"8.5\"\n            elif [[ \"\$PHP_CONSTRAINT\" == *\"8.4\"* ]]; then\n              PHP_VERSION=\"8.4\"\n            else\n              PHP_VERSION=\"8.5\"\n            fi\n";

    private const string CHAIN_STALE = "            if [[ \"\$PHP_CONSTRAINT\" == *\"8.4\"* ]]; then\n              PHP_VERSION=\"8.4\"\n            elif [[ \"\$PHP_CONSTRAINT\" == *\"8.3\"* ]]; then\n              PHP_VERSION=\"8.3\"\n            else\n              PHP_VERSION=\"8.4\"\n            fi\n";

    private WorkflowPhpVersionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new WorkflowPhpVersionDetector();
    }

    #[Test]
    public function aLoopThatListsTheRequiredVersionAndDefaultsToItPasses(): void
    {
        self::assertSame([], $this->detector->check(self::LOOP_CURRENT, '8.5'));
    }

    #[Test]
    public function aLoopMissingTheRequiredVersionIsReportedWithTheListAndTheDefault(): void
    {
        self::assertSame(
            [
                'the PHP version detection list [8.4, 8.3, 8.2] cannot select PHP 8.5, which composer.json requires; add 8.5 to the list',
                'the fallback PHP version is 8.3 but composer.json requires 8.5; set the default to 8.5',
            ],
            $this->detector->check(self::LOOP_STALE, '8.5'),
        );
    }

    #[Test]
    public function anIfElifChainThatCoversTheRequiredVersionPasses(): void
    {
        self::assertSame([], $this->detector->check(self::CHAIN_CURRENT, '8.5'));
    }

    #[Test]
    public function anIfElifChainOnlyCountsTheElseBranchAsTheDefault(): void
    {
        $problems = $this->detector->check(self::CHAIN_STALE, '8.5');

        self::assertSame(
            [
                'the PHP version detection list [8.4, 8.3] cannot select PHP 8.5, which composer.json requires; add 8.5 to the list',
                'the fallback PHP version is 8.4 but composer.json requires 8.5; set the default to 8.5',
            ],
            $problems,
        );
    }

    #[Test]
    public function everyLoopInAFileIsJudged(): void
    {
        self::assertCount(4, $this->detector->check(self::LOOP_STALE . self::LOOP_STALE, '8.5'));
    }

    #[Test]
    public function aWorkflowWithNoDetectionHasNothingToReportAndIsNotADetectingWorkflow(): void
    {
        $yaml = "      - uses: shivammathur/setup-php@v2\n        with:\n          php-version: '8.5'\n";

        self::assertSame([], $this->detector->check($yaml, '8.5'));
        self::assertFalse($this->detector->detectsPhpVersion($yaml));
        self::assertTrue($this->detector->detectsPhpVersion(self::LOOP_CURRENT));
        self::assertTrue($this->detector->detectsPhpVersion(self::CHAIN_CURRENT));
    }
}
