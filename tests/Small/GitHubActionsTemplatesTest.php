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

    /** @return list<string> "<file>:<line>: <text>" for every non-comment line matching $pattern */
    private function executedLinesMatching(string $pattern): array
    {
        $hits = [];
        foreach (\Safe\glob(self::TEMPLATES . '/*.yml') as $file) {
            foreach (explode("\n", \Safe\file_get_contents($file)) as $index => $line) {
                if (!str_starts_with(ltrim($line), '#') && 1 === \Safe\preg_match($pattern, $line)) {
                    $hits[] = \sprintf('%s:%d: %s', basename($file), $index + 1, trim($line));
                }
            }
        }

        return $hits;
    }
}
