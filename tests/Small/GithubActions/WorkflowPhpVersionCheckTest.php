<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\GithubActions;

use LTS\PHPQA\GithubActions\WorkflowPhpVersionCheck;
use LTS\PHPQA\GithubActions\WorkflowPhpVersionDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WorkflowPhpVersionCheck::class)]
#[UsesClass(WorkflowPhpVersionDetector::class)]
#[Small]
final class WorkflowPhpVersionCheckTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../assets/githubActions';

    #[Test]
    public function itReturnsZeroAndPrintsTheExactOkMessageWhenEveryWorkflowCanSelectTheRequiredPhp(): void
    {
        self::expectOutputString('GitHub Actions PHP version detection can select PHP 8.5 in every detecting workflow — OK (3 checked).' . \PHP_EOL);
        self::assertSame(0, new WorkflowPhpVersionCheck()->run(self::FIXTURES . '/current'));
    }

    #[Test]
    public function itReturnsOneAndPrintsTheExactFailureBlockWhenAWorkflowIsStaleOrTheTemplateDrifts(): void
    {
        $expected = \PHP_EOL . 'ERROR — a GitHub Actions workflow cannot select the PHP version composer.json requires' . \PHP_EOL
            . '---------------------------------------------------------------------------------------' . \PHP_EOL
            . 'Required PHP (composer.json): 8.5' . \PHP_EOL . \PHP_EOL
            . '  - templates/github-actions/autofix.yml: the PHP version detection list [8.4, 8.3] cannot select PHP 8.5, which composer.json requires; add 8.5 to the list' . \PHP_EOL
            . '  - templates/github-actions/autofix.yml: the fallback PHP version is 8.3 but composer.json requires 8.5; set the default to 8.5' . \PHP_EOL
            . '  - templates/github-actions/php-qa-ci.yml differs from .github/workflows/qa.yml; the shipped consumer template must stay identical to the workflow this repository runs' . \PHP_EOL
            . \PHP_EOL . '🪪  ' . WorkflowPhpVersionCheck::IDENTIFIER
            . '  (vendor/bin/rule-doc ' . WorkflowPhpVersionCheck::IDENTIFIER . ')' . \PHP_EOL;

        self::expectOutputString($expected);
        self::assertSame(1, new WorkflowPhpVersionCheck()->run(self::FIXTURES . '/stale'));
    }

    #[Test]
    public function itSkipsCleanlyWhenComposerJsonDeclaresNoPhpVersion(): void
    {
        self::expectOutputString('composer.json declares no PHP major.minor requirement — nothing for workflows to detect, skipping.' . \PHP_EOL);
        self::assertSame(0, new WorkflowPhpVersionCheck()->run(self::FIXTURES . '/no-php'));
    }

    #[Test]
    public function mainJudgesThisRepositoryAndItPasses(): void
    {
        $this->expectOutputRegex('#can select PHP \d+\.\d+ in every detecting workflow — OK#');
        self::assertSame(0, WorkflowPhpVersionCheck::main(__DIR__ . '/../../..'));
    }
}
