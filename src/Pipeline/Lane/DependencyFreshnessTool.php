<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\DependencyFreshnessPatterns;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\BranchOnlyProject;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\ComposerLock;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\StaleDependencyDto;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Tracking;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\UpdateDryRunOutput;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use RuntimeException;
use Safe\Exceptions\SafeExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Whether the locked packages matching the freshness patterns (lts/php-qa-ci
 * always, plus the project's own) are what `composer update` would install.
 * One `composer update <packages> --with-dependencies --dry-run` answers it
 * under the project's own constraints, so a pin leaves nothing newer to
 * install. What a package is judged against is what the project's composer
 * configuration makes it follow ({@see Tracking}): a package that follows a
 * branch counts a newer commit on it; a package that follows release tags is
 * asked with `--prefer-stable` and counts only a newer tag within its
 * constraint, never a branch head (Owner ruling on issue #345).
 *
 * Stale fails with every package listed. Up to date passes in silence, but
 * only on Composer's own word for it: exit 0 alone is no answer, as a
 * `composer` that is a shell script exits 0 having printed its source. When
 * Composer cannot say (no network, a source gone, an answer from its cache,
 * no answer in time, no recognisable answer), the lane is skipped with a
 * named line saying the lock was not verified: never failed, never passed in
 * silence (Owner decision on issue #292). The pipeline runs it first in a
 * full run.
 *
 * @internal
 */
final readonly class DependencyFreshnessTool implements ToolInterface
{
    /** Printed with a failure; `rule-doc` resolves it to docs/tools/dependencyFreshness.md. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.dependencyFreshness';

    /** Long enough for a slow mirror, short enough that an unanswered DNS lookup or a hung git cannot hold the run. */
    public const int TIMEOUT_SECONDS = 180;

    /** The registry name, also the name the skip line gives. */
    private const string NAME = 'dependencyFreshness';

    /** The executable looked up on the PATH when no path was given. */
    private const string COMPOSER = 'composer';

    /** No prompt from Composer or from the git it runs: a credential prompt nobody sees would hang the run. */
    private const array ENVIRONMENT = ['COMPOSER_NO_INTERACTION' => '1', 'GIT_TERMINAL_PROMPT' => '0'];

    /** A dry run changes nothing; no scripts, no audit and no progress, so the only output is the answer. */
    private const array DRY_RUN_OPTIONS = ['--with-dependencies', '--dry-run', '--no-interaction', '--no-ansi', '--no-scripts', '--no-progress', '--no-audit'];

    /** What a package that follows release tags is asked with: the newest tag its constraint allows, not a branch it also admits. */
    private const string PREFER_STABLE = '--prefer-stable';

    public function __construct(
        private ?string $composerPath = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root = $context->config->paths->projectRoot;
        $lock = $root . '/composer.lock';
        if (!is_file($lock)) {
            return $this->skipped($context, 'there is no composer.lock');
        }

        try {
            $locked      = ComposerLock::packageNames($lock);
            $versions    = ComposerLock::versions($lock);
            $constraints = Tracking::constraints($root . '/composer.json');
        } catch (RuntimeException $runtimeException) {
            $context->writeln('ERROR: ' . $runtimeException->getMessage());
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed($runtimeException->getMessage());
        }

        $patterns = new DependencyFreshnessPatterns(...$context->config->dependencyFreshnessPatterns);
        $checked  = array_values(array_filter($locked, $patterns->matches(...)));
        if ([] === $checked) {
            return ToolResultDto::passed();
        }

        $branches = [];
        $mixed    = [];
        $tags     = [];
        foreach ($checked as $name) {
            $constraint = $constraints[strtolower($name)] ?? null;
            if (!Tracking::followsBranch($constraint, $versions[$name] ?? null)) {
                $tags[] = $name;
            } elseif (null !== Tracking::branchAlternatives($constraint)) {
                $mixed[strtolower($name)] = Tracking::branchAlternatives($constraint);
            } else {
                $branches[] = $name;
            }
        }

        $branchStale = [];
        $tagStale    = [];
        $skip        = null;
        if ([] !== $branches) {
            $answered    = $this->ask($context, $root, false, null, ...$branches);
            $branchStale = \is_array($answered) ? $answered : [];
            $skip        = $answered instanceof ToolResultDto ? $answered : null;
        }

        $mixedStale = [];
        if ([] !== $mixed) {
            $answered   = $this->askAsBranches($context, $root, $mixed);
            $mixedStale = \is_array($answered) ? $answered : [];
            $skip       ??= $answered instanceof ToolResultDto ? $answered : null;
        }

        if ([] !== $tags) {
            $answered = $this->ask($context, $root, true, null, ...$tags);
            $tagStale = \is_array($answered) ? array_values(array_filter($answered, static fn (StaleDependencyDto $package): bool => !Tracking::isBranchVersion($package->available))) : [];
            $skip     ??= $answered instanceof ToolResultDto ? $answered : null;
        }

        if ([] !== $branchStale || [] !== $mixedStale || [] !== $tagStale) {
            return $this->stale($context, $root, $branchStale, $mixed, $mixedStale, ...$tagStale);
        }

        return $skip ?? ToolResultDto::passed();
    }

    /**
     * Composer's word on packages locked on a branch under a constraint that
     * also admits tags, asked under their branch alternatives alone: Composer
     * prefers a tag to a branch head, which would answer with a tag and move
     * the lock backwards.
     *
     * @param array<array-key, string> $branchConstraints lower-case package name => branch alternatives
     *
     * @return list<StaleDependencyDto>|ToolResultDto
     */
    private function askAsBranches(ToolContext $context, string $root, array $branchConstraints): array|ToolResultDto
    {
        $names = array_map(strval(...), array_keys($branchConstraints));

        try {
            $copy = BranchOnlyProject::create($root, $branchConstraints);
        } catch (RuntimeException|SafeExceptionInterface $exception) {
            return $this->skipped($context, 'could not prepare the branch-only copy of composer.json: ' . $exception->getMessage(), ...$names);
        }

        try {
            return $this->ask($context, $root, false, $copy->jsonFile, ...$names);
        } finally {
            $copy->remove($root);
        }
    }

    /**
     * Composer's word on $packages: the upgrades it would make, or the skip
     * result when it could not say. A package that follows release tags is
     * asked with `--prefer-stable`, so the answer is the newest tag within
     * its constraint and not a branch the constraint also admits.
     *
     * @return list<StaleDependencyDto>|ToolResultDto
     */
    private function ask(ToolContext $context, string $root, bool $preferStable, ?string $composerFile, string ...$packages): array|ToolResultDto
    {
        $options     = $preferStable ? [...self::DRY_RUN_OPTIONS, self::PREFER_STABLE] : [...self::DRY_RUN_OPTIONS];
        $environment = null === $composerFile ? self::ENVIRONMENT : [...self::ENVIRONMENT, 'COMPOSER' => $composerFile];
        $spec        = $context->php->specWithoutXdebug($this->composer(), ['update', ...array_values($packages), ...$options], $root, $environment, streamOutput: false);

        try {
            $result = $context->processes->run(new ProcessSpecDto($spec->command, $spec->cwd, $spec->env, self::TIMEOUT_SECONDS, streamOutput: false));
        } catch (ProcessTimedOutException $processTimedOutException) {
            return $this->skipped($context, \sprintf('composer did not answer within %d seconds', (int)$processTimedOutException->getExceededTimeout()), ...$packages);
        }

        $answer = new UpdateDryRunOutput($result->output);
        if (!$result->succeeded()) {
            return $this->skipped($context, \sprintf('composer failed (%s): %s', $result->describeExit(), $answer->failureReason()), ...$packages);
        }

        $stale = $answer->staleAmong(...$packages);
        if ([] !== $stale) {
            return $stale;
        }

        $unreachable = $answer->unreachableSource();
        if (null !== $unreachable) {
            return $this->skipped($context, $unreachable, ...$packages);
        }

        if (!$answer->composerAnswered()) {
            return $this->skipped($context, \sprintf('composer gave no recognisable answer (it printed: %s)', $answer->printedSummary()), ...$packages);
        }

        return [];
    }

    /**
     * @param list<StaleDependencyDto> $branchStale packages that follow a branch, behind its head
     * @param array<array-key, string> $mixed       lower-case package name => branch alternatives, for the packages locked on a branch under a constraint that also admits tags
     * @param list<StaleDependencyDto> $mixedStale  those of $mixed that are behind the branch head
     * @param StaleDependencyDto       ...$tagStale packages that follow release tags, behind the newest tag their constraint allows
     */
    private function stale(ToolContext $context, string $root, array $branchStale, array $mixed, array $mixedStale, StaleDependencyDto ...$tagStale): ToolResultDto
    {
        $stale = [...$branchStale, ...$mixedStale, ...$tagStale];
        $context->writeln('');
        $context->writeln('Dependencies are out of date. `composer update` would install newer versions of:');
        $context->writeln('');
        foreach ($stale as $package) {
            $context->writeln('  - ' . $package->describe());
        }

        $context->writeln('');
        if ([] !== $mixedStale) {
            $this->mixedRemedy($context, $root, $mixed, ...$mixedStale);
        }

        if ([] === $tagStale && [] === $mixedStale) {
            $context->writeln('Run `composer update`, then run QA again. There is no point checking code with');
            $context->writeln('an out-of-date toolchain, or against out-of-date copies of your own packages.');
        } else {
            if ([] !== $branchStale) {
                $context->writeln(\sprintf('For the packages that follow a branch, run `composer update %s`.', $this->names(...$branchStale)));
            }

            if ([] !== $tagStale) {
                $context->writeln(\sprintf('For the packages that follow release tags, run `composer update %s --with-dependencies --prefer-stable`.', $this->names(...$tagStale)));
                $context->writeln('The newest tag within your constraint is what counts, and `--prefer-stable` keeps');
                $context->writeln('Composer from choosing a branch your constraint also admits.');
            }

            $context->writeln('Then run QA again. There is no point checking code with an out-of-date toolchain,');
            $context->writeln('or against out-of-date copies of your own packages.');
        }

        $context->writeln('');
        $context->writeln('Do NOT pin a package in composer.json to make this check pass. A pin to a tag or');
        $context->writeln('a commit leaves `composer update` nothing newer to install, so it is the only way');
        $context->writeln('past this check, and it is for a compelling reason only, such as a known');
        $context->writeln('regression in the newest version. Record that reason and its issue link with the');
        $context->writeln('pin, and remove the pin as soon as the reason is gone.');
        $context->writeIdentifier(self::IDENTIFIER);

        $names = array_map(static fn (StaleDependencyDto $package): string => $package->name, $stale);

        return ToolResultDto::failed(\sprintf(
            '%d locked %s behind what composer update would install: %s',
            \count($names),
            1 === \count($names) ? 'package is' : 'packages are',
            implode(', ', $names),
        ));
    }

    /**
     * Freshness could not be determined, which is not a verdict on the lock:
     * the run carries on, and says by name that the lock was not verified.
     */
    private function skipped(ToolContext $context, string $reason, string ...$unverified): ToolResultDto
    {
        $line = \sprintf('SKIPPED %s (%s): could not determine whether the locked dependencies are up to date: %s.', self::NAME, self::IDENTIFIER, $reason);
        if ([] !== $unverified) {
            $line .= \sprintf(' composer.lock was NOT verified for: %s.', implode(', ', $unverified));
        }

        $context->writeln($line);

        return ToolResultDto::skipped($reason);
    }

    /**
     * `composer update` cannot move a branch lock when the constraint also
     * admits tags (Composer would choose the tag, which may be older than the
     * lock), so the remedy requires the branch alone.
     *
     * @param array<array-key, string> $mixed lower-case package name => branch alternatives
     */
    private function mixedRemedy(ToolContext $context, string $root, array $mixed, StaleDependencyDto ...$mixedStale): void
    {
        $dev = Tracking::devOnlyPackages($root . '/composer.json');
        $context->writeln('These packages are locked on a branch, but their constraint also admits release tags,');
        $context->writeln('and Composer prefers a tag to a branch head, so `composer update` would move the lock');
        $context->writeln('to a tag. To follow the branch, require it alone:');
        foreach ($mixedStale as $package) {
            $name = strtolower($package->name);
            if (!isset($mixed[$name])) {
                continue;
            }

            $context->writeln(\sprintf(
                '  composer require %s\'%s:%s\' --with-dependencies',
                \in_array($name, $dev, true) ? '--dev ' : '',
                $package->name,
                $mixed[$name],
            ));
        }

        $context->writeln('To follow release tags instead, require a tag range such as `^1.0` and run');
        $context->writeln('`composer update <package> --with-dependencies --prefer-stable`.');
        $context->writeln('');
    }

    private function names(StaleDependencyDto ...$stale): string
    {
        return implode(' ', array_map(static fn (StaleDependencyDto $package): string => $package->name, array_values($stale)));
    }

    private function composer(): string
    {
        if (null !== $this->composerPath) {
            return $this->composerPath;
        }

        $found = new ExecutableFinder()->find(self::COMPOSER, self::COMPOSER);

        return null === $found ? self::COMPOSER : $found;
    }
}
