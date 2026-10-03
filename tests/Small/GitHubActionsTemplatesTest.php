<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\BranchNamePolicyDecision;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a shipped workflow template that only works in the
 * project layout its author had".
 *
 * A consumer copies these files unchanged, so whatever they assume about the
 * project is assumed for every consumer. They hard-coded `vendor/bin/qa`,
 * which fails on any project with a custom composer bin-dir — php-qa-ci's own
 * is `bin` — and fetched PHARs with PHIVE into `vendor/lts/php-qa-ci`, which
 * the package already ships and which a cache could overwrite with stale
 * copies. This repository runs the pipeline template on itself, and that run
 * failed on every push for exactly this reason without failing anything else.
 *
 * Comment lines are skipped: they explain, they do not execute.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class GitHubActionsTemplatesTest extends TestCase
{
    private const string TEMPLATES = __DIR__ . '/../../templates/github-actions';

    private const string PIPELINE_TEMPLATE = self::TEMPLATES . '/php-qa-ci.yml';

    private const string RELEASE_TEMPLATE = self::TEMPLATES . '/release.yml';

    private const string APPROVE_TEMPLATE = self::TEMPLATES . '/approve-held-ci/action.yml';

    private const string REPOSITORY = __DIR__ . '/../..';

    #[Test]
    public function everyTemplateFindsTheQaEntryPointThroughTheComposerBinDir(): void
    {
        self::assertSame(
            [],
            $this->executedLinesMatching('#vendor/bin/#'),
            'Run qa as "$(composer config bin-dir)/qa": composer.json may set config.bin-dir, and php-qa-ci sets it to bin.',
        );
    }

    #[Test]
    public function noTemplateFetchesOrCachesTheShippedTools(): void
    {
        self::assertSame(
            [],
            $this->executedLinesMatching('#phive|vendor-phar#'),
            'The package ships its PHARs in vendor-phar/; nothing is fetched at run time, and a cache restored over them can only make them stale.',
        );
    }

    #[Test]
    public function noTemplateSetsAVariableTheBashPipelineRead(): void
    {
        self::assertSame([], $this->executedLinesMatching('#skipUncommittedChangesCheck#'));
    }

    #[Test]
    public function thePipelineTemplateRunsOnEveryBranchPrefixThePolicyAllows(): void
    {
        $template = \Safe\file_get_contents(self::PIPELINE_TEMPLATE);

        $missing = array_values(array_filter(
            BranchNamePolicyDecision::DEFAULT_PREFIXES,
            static fn (string $prefix): bool => !str_contains($template, \sprintf("- '%s**'", $prefix)),
        ));

        self::assertSame([], $missing, 'php-qa-ci.yml must trigger on a push to every branch prefix branchNamePolicy allows.');
    }

    #[Test]
    public function autoCommittingFixesMakesTheRunWritable(): void
    {
        self::assertMatchesRegularExpression(
            "#^\\s+QA_READONLY: \\\$\\{\\{ env\\.AUTO_COMMIT_FIXES == 'true' && '0' \\|\\| '1' \\}\\}\$#m",
            \Safe\file_get_contents(self::PIPELINE_TEMPLATE),
            'A run on GitHub Actions is read-only by default, so the fixers write nothing and AUTO_COMMIT_FIXES has nothing to commit unless the QA step is writable.',
        );
    }

    /**
     * php-qa-ci releases itself through the workflow it ships, so every
     * php-qa-ci release proves the template; a fix made to one copy only
     * would leave consumers on the version that was not proven.
     */
    #[Test]
    public function thisRepositoryReleasesThroughTheShippedReleaseWorkflow(): void
    {
        self::assertSame(
            \Safe\file_get_contents(self::RELEASE_TEMPLATE),
            \Safe\file_get_contents(self::REPOSITORY . '/.github/workflows/release.yml'),
            '.github/workflows/release.yml must be an identical copy of templates/github-actions/release.yml: edit the template and copy it.',
        );
    }

    #[Test]
    public function thisRepositoryApprovesHeldRunsWithTheShippedAction(): void
    {
        self::assertSame(
            \Safe\file_get_contents(self::APPROVE_TEMPLATE),
            \Safe\file_get_contents(self::REPOSITORY . '/.github/actions/approve-held-ci/action.yml'),
            '.github/actions/approve-held-ci/action.yml must be an identical copy of templates/github-actions/approve-held-ci/action.yml: edit the template and copy it.',
        );
    }

    #[Test]
    public function theReleaseTemplateNamesNoBranchOfItsOwn(): void
    {
        self::assertSame([], $this->executedLinesIn(self::RELEASE_TEMPLATE, '#php8\.\d|\b(?:main|master)\b#'), 'The release branch is the repository default branch, read from the event, so the template copies unchanged into any repository.');
    }

    #[Test]
    public function theReleaseTemplateRunsTheReleaseCliFromTheComposerBinDir(): void
    {
        $template = \Safe\file_get_contents(self::RELEASE_TEMPLATE);

        self::assertSame([], $this->executedLinesIn(self::RELEASE_TEMPLATE, '#\bbin/changelog-release#'), 'Run the release CLI from "$(composer config bin-dir)": a consumer has it in vendor/bin, php-qa-ci in bin.');
        self::assertStringContainsString('"$(composer config bin-dir)/changelog-release"', $template);
    }

    #[Test]
    public function theReleaseTemplateApprovesTheHeldRunOfTheWorkflowThatGatedIt(): void
    {
        $template = \Safe\file_get_contents(self::RELEASE_TEMPLATE);

        self::assertStringContainsString('uses: ./.github/actions/approve-held-ci', $template);
        self::assertStringContainsString('workflow: ${{ github.event.workflow_run.path }}', $template);
        self::assertSame([], $this->executedLinesIn(self::APPROVE_TEMPLATE, '#ci\.yml#'), 'The action is told which workflow to approve; it must not assume one.');
    }

    /** @return list<string> "<line>: <text>" for every non-comment line of $file matching $pattern */
    private function executedLinesIn(string $file, string $pattern): array
    {
        $hits = [];
        foreach (explode("\n", \Safe\file_get_contents($file)) as $index => $line) {
            if (!str_starts_with(ltrim($line), '#') && 1 === \Safe\preg_match($pattern, $line)) {
                $hits[] = \sprintf('%d: %s', $index + 1, trim($line));
            }
        }

        return $hits;
    }

    /** @return list<string> "<file>:<line>: <text>" for every non-comment line matching $pattern */
    private function executedLinesMatching(string $pattern): array
    {
        $hits = [];
        foreach (\Safe\glob(self::TEMPLATES . '/*.yml') as $file) {
            if (!\is_string($file)) {
                continue;
            }

            foreach (explode("\n", \Safe\file_get_contents($file)) as $index => $line) {
                if (!str_starts_with(ltrim($line), '#') && 1 === \Safe\preg_match($pattern, $line)) {
                    $hits[] = \sprintf('%s:%d: %s', basename($file), $index + 1, trim($line));
                }
            }
        }

        return $hits;
    }
}
