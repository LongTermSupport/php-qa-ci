<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use Exception;
use LTS\PHPQA\Helper;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Lane\ComposerChecks\CooldownPolicyChecker;
use LTS\PHPQA\Pipeline\Lane\ComposerChecks\CooldownReport;
use LTS\PHPQA\Pipeline\Lane\ComposerChecks\NestedManifestSelector;
use LTS\PHPQA\Pipeline\Lane\ComposerChecks\RedundantSuggestDetector;
use LTS\PHPQA\Pipeline\Lock\ClockInterface;
use LTS\PHPQA\Pipeline\Lock\SystemClock;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Composer hygiene, in six steps: `composer diagnose` (informational, never
 * fails), the cooldown policy check (config.policy.cooldown and the installed
 * Composer; report-only until withComposerCooldownEnforced(true); it covers every
 * nested composer.json the project's git file list holds, bar ignored and
 * unanalysed paths), `composer audit` against the lock file (a known advisory fails the
 * lane; abandoned packages are reported; off with withComposerAudit(false)),
 * no `suggest` entry may name a package the project already requires, the
 * composer.json must be normalised by the shipped composer-normalize PHAR (a
 * read-only run dry-runs and fails on a pending change; a writable run applies
 * it), and the autoloader is dumped. The dump only regenerates files under
 * vendor/, so it runs in every mode. The PHAR bundles Composer and the
 * normalize command, so the consumer needs no plugin and no allow-plugins entry.
 *
 * @internal
 */
final readonly class ComposerChecksTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.composerChecks';

    private const string RULE      = '---------------------';

    private const string COMPOSER = 'composer';

    private const string COMPOSER_JSON = '/composer.json';

    private const string UNREADABLE = 'composer.json could not be read: ';

    private const string LIST_ITEM = '  - ';

    private const string NESTED = 'Nested composer.json files';

    private const string NESTED_UNLISTED = "Nested composer.json files could not be checked: git could not list the project's files (the project is not a git work tree, or git failed), and the cooldown policy is enforced, so they cannot be shown to be protected. Run the lane from a git checkout, or say why the project is exempt with withoutComposerCooldownCheck('<reason>').";

    private const string NORMALIZE_PHAR = 'composer-normalize.phar';

    public function __construct(
        private ?string $composerPath = null,
        private RedundantSuggestDetector $redundantSuggests = new RedundantSuggestDetector(),
        private CooldownPolicyChecker $cooldown = new CooldownPolicyChecker(),
        private ClockInterface $clock = new SystemClock(),
        private ProjectFilesInterface $files = new GitProjectFiles(),
        private NestedManifestSelector $nestedManifests = new NestedManifestSelector(),
    ) {
    }

    public function name(): string
    {
        return 'composerChecks';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root     = $context->config->paths->projectRoot;
        $composer = $this->composer();

        $context->writeln('');
        $context->writeln('');
        $context->writeln('Checking Composer Issues - Fix Any Red Stuff (wont fail the process)');
        $context->writeln(self::RULE);
        $context->writeln('');
        if (!$context->php->withoutXdebug($composer, ['diagnose'], $root)->succeeded()) {
            $context->writeln('composer diagnose reported issues (shown above) — informational only, not failing the pipeline.');
        }

        $pending = $this->cooldownStep($context, $composer);

        $context->writeln('');
        $context->writeln('');
        $context->writeln('Auditing Dependencies For Known Advisories');
        $context->writeln(self::RULE);
        $context->writeln('');
        if ($context->config->useComposerAudit) {
            $audit = $context->php->withoutXdebug($composer, ['audit', '--locked', '--abandoned=report', '--no-interaction'], $root);
            if (!$audit->succeeded()) {
                $this->auditFailedGuidance($context);

                return $this->failed($context, $pending, \sprintf('composer audit found advisories or could not run (%s)', $audit->describeExit()));
            }
        } else {
            $context->writeln('composer audit disabled for this project (withComposerAudit(false) / useComposerAudit=0) — skipping.');
        }

        $manifest = $this->readManifest($root . self::COMPOSER_JSON);
        if (\is_string($manifest)) {
            $context->writeln('');
            $context->writeln('Skipping the redundant-suggest check: ' . self::UNREADABLE . $manifest);
        } else {
            $redundant = $this->redundantSuggests->check($manifest);
            if ([] !== $redundant) {
                $this->redundantSuggestGuidance($context, ...$redundant);

                return $this->failed($context, $pending, \sprintf('%d suggest entry/entries duplicate a required package', \count($redundant)));
            }
        }

        $normalizePhar = $context->config->paths->pharDir . '/' . self::NORMALIZE_PHAR;
        $composerJson  = $root . self::COMPOSER_JSON;
        if ($context->config->readOnly) {
            $context->writeln('');
            $context->writeln('');
            $context->writeln('Checking Composer Normalisation (read-only)');
            $context->writeln(self::RULE);
            $context->writeln('');
            $normalize = $context->php->withoutXdebug($normalizePhar, ['--dry-run', $composerJson], $root);
            if (!$normalize->succeeded()) {
                ReadOnlyGuidance::wouldModify($context, 'Composer Normalize', 'com');

                return $this->failed($context, $pending, 'composer.json is not normalised (read-only run)');
            }
        } else {
            $context->writeln('');
            $context->writeln('');
            $context->writeln('Running Composer Normalise');
            $context->writeln(self::RULE);
            $context->writeln('');
            $normalize = $context->php->withoutXdebug($normalizePhar, [$composerJson], $root);
            if (!$normalize->succeeded()) {
                return $this->failed($context, $pending, \sprintf('composer-normalize failed (%s)', $normalize->describeExit()));
            }
        }

        $context->writeln('');
        $context->writeln('');
        $context->writeln('Dumping Composer Autoloader');
        $context->writeln(self::RULE);
        $context->writeln('');

        $dump = $context->php->withoutXdebug($composer, ['dump-autoload'], $root);
        if (!$dump->succeeded()) {
            return $this->failed($context, $pending, \sprintf('composer dump-autoload failed (%s)', $dump->describeExit()));
        }

        if (null !== $pending) {
            return $this->failed($context, null, $pending);
        }

        return ToolResultDto::passed();
    }

    /**
     * A failing step's summary, with a cooldown finding that was held back so
     * the later steps could still run.
     */
    private function failed(ToolContext $context, ?string $pending, string $summary): ToolResultDto
    {
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(null === $pending ? $summary : $summary . '; ' . $pending);
    }

    /**
     * Whether the project gets the protection of Composer's cooldown policy.
     * Findings are printed; when enforcement is on, the summary that fails the
     * lane is returned so the later steps still run and report.
     */
    private function cooldownStep(ToolContext $context, string $composer): ?string
    {
        $context->writeln('');
        $context->writeln('');
        $context->writeln('Checking Composer Cooldown Policy');
        $context->writeln(self::RULE);
        $context->writeln('');

        $optOut = $context->config->composerCooldownOptOutReason;
        if (null !== $optOut) {
            $context->writeln('Composer cooldown check skipped for this project (withoutComposerCooldownCheck): ' . $optOut);

            return null;
        }

        $root     = $context->config->paths->projectRoot;
        $today    = gmdate('Y-m-d', $this->clock->now());
        $manifest = $this->readManifest($root . self::COMPOSER_JSON);
        if (\is_string($manifest)) {
            $context->writeln(self::UNREADABLE . $manifest);
            $nested = $this->nestedStep($context, $today);

            return $this->enforcementOutcome(
                $context,
                0 === $nested ? self::UNREADABLE . $manifest : \sprintf('%d Composer cooldown finding(s)', 1 + $nested),
            );
        }

        $probe  = $context->php->withoutXdebug($composer, ['--version', '--no-ansi'], $root, streamOutput: false);
        $report = $this->cooldown->check(
            $manifest,
            $probe->succeeded() ? $probe->stdout : null,
            $today,
            ...$context->config->composerCooldownInternalVendors,
        );

        $this->printCooldownReport($context, $report);
        $findings = \count($report->problems) + $this->nestedStep($context, $today);
        if (0 === $findings) {
            return null;
        }

        return $this->enforcementOutcome($context, \sprintf('%d Composer cooldown finding(s)', $findings));
    }

    /**
     * The same policy check over every other composer.json the project holds: a repository can
     * hold more than one Composer project, an application beside a tool or a docs build. The
     * files are listed as the analysedPaths lane lists them, bar those under an ignored path, a
     * path declared unanalysed or a default exclusion. Each finding names its file. When git cannot
     * list the files, a report-only project is told they were not checked and a project that
     * enforces the policy gets one finding, since it asked for protection the lane cannot give.
     *
     * @return int how many findings the nested manifests add
     */
    private function nestedStep(ToolContext $context, string $today): int
    {
        $files = $this->files->files($context);
        if (null === $files) {
            $context->writeln('');
            if ($context->config->enforceComposerCooldown) {
                $context->writeln(self::NESTED_UNLISTED);

                return 1;
            }

            $context->writeln(self::NESTED . ' not checked: the project is not a git work tree, so there is no file list to read them from.');

            return 0;
        }

        $manifests = $this->nestedManifests->select($context->config, ...$files);
        if ([] === $manifests) {
            return 0;
        }

        $root      = $context->config->paths->projectRoot;
        $problems  = [];
        $overrides = [];
        foreach ($manifests as $relative) {
            $manifest = $this->readManifest($root . '/' . $relative);
            if (\is_string($manifest)) {
                $problems[] = $relative . ': ' . self::UNREADABLE . $manifest;

                continue;
            }

            $report = $this->cooldown->checkPolicy($manifest, $today, ...$context->config->composerCooldownInternalVendors);
            foreach ($report->problems as $problem) {
                $problems[] = $relative . ': ' . $problem;
            }

            foreach ($report->overrides as $override) {
                $overrides[] = $relative . ': ' . $override;
            }
        }

        $found = new CooldownReport($problems, $overrides);
        $this->printNested($context, \count($manifests), $found);

        return \count($found->problems);
    }

    /** What the nested manifests said, each line naming its file. */
    private function printNested(ToolContext $context, int $checked, CooldownReport $found): void
    {
        $context->writeln('');
        if (!$found->hasProblems()) {
            $context->writeln(\sprintf('%s (%d checked): the cooldown policy is in place in each.', self::NESTED, $checked));
        } else {
            $context->writeln(\sprintf('%s (%d checked) have %d finding(s):', self::NESTED, $checked, \count($found->problems)));
            $context->writeln('');
            foreach ($found->problems as $problem) {
                $context->writeln(self::LIST_ITEM . $problem);
            }
        }

        if ([] === $found->overrides) {
            return;
        }

        $context->writeln('');
        $context->writeln('Security overrides in force in those files (config.policy.cooldown.ignore):');
        foreach ($found->overrides as $override) {
            $context->writeln(self::LIST_ITEM . $override);
        }
    }

    /**
     * @return ?string $summary when enforcement is on, otherwise null after saying the finding does not fail the lane
     */
    private function enforcementOutcome(ToolContext $context, string $summary): ?string
    {
        if ($context->config->enforceComposerCooldown) {
            return $summary;
        }

        $context->writeln('');
        $context->writeln('Report-only: these findings do not fail the lane. withComposerCooldownEnforced(true) in');
        $context->writeln('qaConfig/qa.php (or enforceComposerCooldown=1 for one run) makes them fail it; a project that');
        $context->writeln("must not be held to the cooldown says why with withoutComposerCooldownCheck('<reason>').");

        return null;
    }

    /**
     * @return array<array-key, mixed>|string the decoded manifest, or why it could not be read
     */
    private function readManifest(string $path): array|string
    {
        if (!is_file($path)) {
            return 'there is no such file: ' . $path;
        }

        try {
            return Helper::getComposerJsonDecoded($path);
        } catch (Exception $exception) {
            return $exception->getMessage();
        }
    }

    private function printCooldownReport(ToolContext $context, CooldownReport $report): void
    {
        if ($report->hasProblems()) {
            $context->writeln(\sprintf('Composer cooldown policy has %d finding(s):', \count($report->problems)));
            $context->writeln('');
            foreach ($report->problems as $problem) {
                $context->writeln(self::LIST_ITEM . $problem);
            }
        }

        if ([] !== $report->overrides) {
            $context->writeln('');
            $context->writeln('Security overrides in force (config.policy.cooldown.ignore):');
            foreach ($report->overrides as $override) {
                $context->writeln(self::LIST_ITEM . $override);
            }
        }

        if (!$report->hasProblems()) {
            $context->writeln('');
            $context->writeln('Composer cooldown policy is in place for the root composer.json.');
        }
    }

    private function composer(): string
    {
        if (null !== $this->composerPath) {
            return $this->composerPath;
        }

        $found = new ExecutableFinder()->find(self::COMPOSER, self::COMPOSER);

        return null === $found ? self::COMPOSER : $found;
    }

    private function redundantSuggestGuidance(ToolContext $context, string ...$redundant): void
    {
        $context->writeln('');
        $context->writeln('ERROR: composer.json suggests a package it already requires:');
        $context->writeln('');
        foreach ($redundant as $entry) {
            $context->writeln(self::LIST_ITEM . $entry);
        }

        $context->writeln('');
        $context->writeln('A suggestion is advice to install something the consumer does not already');
        $context->writeln('get. Advice they cannot act on is noise, and noise in that block trains the');
        $context->writeln('reader to skip the rest of it.');
        $context->writeln('');
        $context->writeln('TO FIX: delete the entry from "suggest". If the package should be optional');
        $context->writeln('rather than required, move it out of "require"/"require-dev" instead.');
    }

    private function auditFailedGuidance(ToolContext $context): void
    {
        $context->writeln('');
        $context->writeln('composer audit did not pass (output above). Either a locked dependency has a');
        $context->writeln('published security advisory, or the advisory database could not be reached.');
        $context->writeln('');
        $context->writeln('TO FIX');
        $context->writeln('  - Update the affected package to a patched release and commit composer.lock:');
        $context->writeln('        composer update <vendor/package> --with-all-dependencies');
        $context->writeln('  - If no patched release exists yet, pin an explicit exception in composer.json');
        $context->writeln('    under config.audit.ignore with the advisory id and a comment saying why.');
        $context->writeln('  - An abandoned package is reported, not failed: plan its replacement.');
        $context->writeln('  - Offline builds: ->withComposerAudit(false) in qaConfig/qa.php, or');
        $context->writeln('    useComposerAudit=0 for one run.');
    }
}
