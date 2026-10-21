<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\DependencyFreshnessPatterns;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\ComposerLock;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\StaleDependencyDto;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\UpdateDryRunOutput;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Whether the locked packages matching the freshness patterns (lts/php-qa-ci
 * always, plus the project's own) are what `composer update` would install.
 * One `composer update <packages> --with-dependencies --dry-run` answers it
 * under the project's own constraints, so a branch constraint counts a newer
 * commit and a pin leaves nothing newer to install.
 *
 * Stale fails with every package listed. Up to date passes in silence. When
 * Composer cannot say (no network, a source gone, an answer from its cache,
 * no answer in time), the lane is skipped with a named line saying the lock
 * was not verified: never failed, never passed in silence (Owner decision on
 * issue #292). The pipeline runs it first in a full run.
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
            $locked = ComposerLock::packageNames($lock);
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

        $spec = $context->php->specWithoutXdebug($this->composer(), ['update', ...$checked, ...self::DRY_RUN_OPTIONS], $root, self::ENVIRONMENT, streamOutput: false);

        try {
            $result = $context->processes->run(new ProcessSpecDto($spec->command, $spec->cwd, $spec->env, self::TIMEOUT_SECONDS, streamOutput: false));
        } catch (ProcessTimedOutException $processTimedOutException) {
            return $this->skipped($context, \sprintf('composer did not answer within %d seconds', (int)$processTimedOutException->getExceededTimeout()), ...$checked);
        }

        $answer = new UpdateDryRunOutput($result->output);
        if (!$result->succeeded()) {
            return $this->skipped($context, \sprintf('composer exited %d: %s', $result->exitCode, $answer->failureReason()), ...$checked);
        }

        $stale = $answer->staleAmong(...$checked);
        if ([] !== $stale) {
            return $this->stale($context, ...$stale);
        }

        $unreachable = $answer->unreachableSource();
        if (null !== $unreachable) {
            return $this->skipped($context, $unreachable, ...$checked);
        }

        return ToolResultDto::passed();
    }

    private function stale(ToolContext $context, StaleDependencyDto ...$stale): ToolResultDto
    {
        $context->writeln('');
        $context->writeln('Dependencies are out of date. `composer update` would install newer versions of:');
        $context->writeln('');
        foreach ($stale as $package) {
            $context->writeln('  - ' . $package->describe());
        }

        $context->writeln('');
        $context->writeln('Run `composer update`, then run QA again. There is no point checking code with');
        $context->writeln('an out-of-date toolchain, or against out-of-date copies of your own packages.');
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

    private function composer(): string
    {
        if (null !== $this->composerPath) {
            return $this->composerPath;
        }

        $found = new ExecutableFinder()->find(self::COMPOSER, self::COMPOSER);

        return null === $found ? self::COMPOSER : $found;
    }
}
