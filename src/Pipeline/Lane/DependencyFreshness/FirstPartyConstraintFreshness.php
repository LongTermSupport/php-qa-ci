<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DependencyFreshness;

use InvalidArgumentException;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Dto\OutdatedPackageDto;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshnessTool;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use UnexpectedValueException;

/**
 * Whether the project's constraints on its first-party packages leave out a
 * newer release. A constraint such as `^0.2` never admits 0.3.0, so a plain
 * `composer update` can never move to it and the project keeps shipping and
 * testing the old version, which the lock check cannot see because it judges
 * the lock only within the constraints. One `composer outdated --locked
 * --direct --format=json` gives the newest release of each direct dependency;
 * {@see FirstPartyConstraintJudge} sets it against the constraint.
 *
 * A loose constraint that excludes a newer release fails. An exact pin is
 * reported and does not fail, and a branch is the lock check's business.
 * When Composer cannot answer the lane is skipped with a named line saying
 * the constraints were not verified: it never fails for being unable to ask.
 * It is quiet when nothing is wrong, and asks Composer nothing unless the
 * project requires a first-party package directly.
 *
 * @internal
 */
final readonly class FirstPartyConstraintFreshness
{
    private const string NOTE = 'NOTE ' . DependencyFreshnessTool::NAME . ': ';

    private const string COMPOSER_JSON = '/composer.json';

    private const string PADDING = '      ';

    public function run(ToolContext $context, string $composer): ToolResultDto
    {
        $root = $context->config->paths->projectRoot;

        try {
            $vendors     = FirstPartyVendors::fromManifest(Tracking::read($root . self::COMPOSER_JSON));
            $constraints = Tracking::constraints($root . self::COMPOSER_JSON);
        } catch (RuntimeException $runtimeException) {
            $context->writeln('ERROR: ' . $runtimeException->getMessage());
            $context->writeIdentifier(DependencyFreshnessTool::IDENTIFIER);

            return ToolResultDto::failed($runtimeException->getMessage());
        }

        if ([] === $vendors->vendors) {
            $context->writeln(self::NOTE . $vendors->describe() . ', so first-party constraints are not checked.');

            return ToolResultDto::passed();
        }

        $names = [];
        foreach (array_keys($constraints) as $name) {
            if ($vendors->owns((string)$name)) {
                $names[] = (string)$name;
            }
        }

        if ([] === $names) {
            return ToolResultDto::passed();
        }

        if (!is_file($root . '/composer.lock')) {
            return $this->skipped($context, 'there is no composer.lock', ...$names);
        }

        $answered = $this->ask($context, $composer, $root, ...$names);
        if ($answered instanceof ToolResultDto) {
            return $answered;
        }

        return $this->judge($context, $vendors, $constraints, $answered, ...$names);
    }

    /**
     * @param array<array-key, string> $constraints package name => the project's constraint
     * @param list<OutdatedPackageDto> $outdated    what Composer says is outdated
     * @param string                   ...$names    the direct first-party dependencies
     */
    private function judge(ToolContext $context, FirstPartyVendors $vendors, array $constraints, array $outdated, string ...$names): ToolResultDto
    {
        $behind        = [];
        $unjudged      = [];
        $unjudgedNames = [];
        foreach ($outdated as $package) {
            $constraint = $constraints[$package->name] ?? null;
            if (null === $constraint || !\in_array($package->name, $names, true)) {
                continue;
            }

            try {
                $verdict = FirstPartyConstraintJudge::judge($constraint, $package->version, $package->latest);
            } catch (InvalidArgumentException|UnexpectedValueException $exception) {
                $unjudged[]      = $exception->getMessage();
                $unjudgedNames[] = $package->name;
                continue;
            }

            if (FirstPartyVerdictEnum::Behind === $verdict) {
                $behind[] = [$package, $constraint];
            } elseif (FirstPartyVerdictEnum::Pinned === $verdict) {
                $context->writeln(\sprintf('%s%s is pinned exactly to "%s" and a newer release exists (%s): not failed, because an exact pin is deliberate.', self::NOTE, $package->name, $constraint, $package->latest));
            }
        }

        if ([] !== $behind) {
            return $this->behind($context, $vendors, ...$behind);
        }

        if ([] !== $unjudged) {
            return $this->skipped($context, 'a constraint is not one the version parser reads: ' . implode('; ', $unjudged), ...$unjudgedNames);
        }

        return ToolResultDto::passed();
    }

    /**
     * Composer's list of the outdated locked direct dependencies, or the skip
     * result when it could not say.
     *
     * @return list<OutdatedPackageDto>|ToolResultDto
     */
    private function ask(ToolContext $context, string $composer, string $root, string ...$names): array|ToolResultDto
    {
        $spec = $context->php->specWithoutXdebug(
            $composer,
            ['outdated', '--locked', '--direct', '--format=json', '--no-interaction', '--no-ansi', '--no-scripts'],
            $root,
            DependencyFreshnessTool::ENVIRONMENT,
            streamOutput: false,
        );

        try {
            $result = $context->processes->run(new ProcessSpecDto($spec->command, $spec->cwd, $spec->env, DependencyFreshnessTool::TIMEOUT_SECONDS, streamOutput: false));
        } catch (ProcessTimedOutException $processTimedOutException) {
            return $this->skipped($context, \sprintf('composer did not answer within %d seconds', (int)$processTimedOutException->getExceededTimeout()), ...$names);
        }

        $printed = new UpdateDryRunOutput($result->output);
        if (!$result->succeeded()) {
            return $this->skipped($context, \sprintf('composer failed (%s): %s', $result->describeExit(), $printed->failureReason()), ...$names);
        }

        $unreachable = $printed->unreachableSource();
        if (null !== $unreachable) {
            return $this->skipped($context, $unreachable, ...$names);
        }

        $answer = new OutdatedOutput($result->stdout);
        if (!$answer->answered()) {
            return $this->skipped($context, \sprintf('composer gave no recognisable answer (it printed: %s)', $printed->printedSummary()), ...$names);
        }

        return $answer->packages();
    }

    /** @param array{OutdatedPackageDto, string} ...$behind each package with the constraint that excludes its newest release */
    private function behind(ToolContext $context, FirstPartyVendors $vendors, array ...$behind): ToolResultDto
    {
        $context->writeln('');
        $context->writeln('First-party dependency constraints exclude newer releases:');
        $context->writeln('');
        foreach ($behind as [$package, $constraint]) {
            $newest = FirstPartyConstraintJudge::newestLine($package->latest);
            $context->writeln(\sprintf('  - %s: constraint "%s" excludes the newest release %s (locked %s)', $package->name, $constraint, $package->latest, $package->version));
            $context->writeln(\sprintf('%swiden it to "%s", or to "%s || %s" to keep admitting the old line', self::PADDING, $newest, $constraint, $newest));
            $context->writeln(\sprintf('%sor, to stay on purpose, pin it exactly: "%s"', self::PADDING, FirstPartyConstraintJudge::exactPin($package->version)));
        }

        $names = array_map(static fn (array $entry): string => $entry[0]->name, array_values($behind));
        $context->writeln('');
        $context->writeln('`composer update` can never move to a release the constraint excludes, so the project');
        $context->writeln('keeps installing, shipping and testing the old version. Widen each constraint in');
        $context->writeln(\sprintf('composer.json, run `composer update %s --with-dependencies`, then run QA again.', implode(' ', $names)));
        $context->writeln('');
        $context->writeln('If staying on the old release is deliberate, pin it exactly (a constraint naming one');
        $context->writeln('version): an exact pin is reported on every run and does not fail this check. Record');
        $context->writeln('why beside the pin, and remove the pin as soon as the reason is gone.');
        $context->writeln('');
        $context->writeln(\sprintf('First-party vendors: %s.', $vendors->describe()));
        $context->writeln('Name the vendors yourself with extra.php-qa-ci.firstPartyVendors in composer.json, or');
        $context->writeln('switch this check off with ->withFirstPartyConstraintFreshness(false) in qaConfig/qa.php');
        $context->writeln('(useFirstPartyConstraintFreshness=0 for one run).');
        $context->writeIdentifier(DependencyFreshnessTool::IDENTIFIER);

        return ToolResultDto::failed(\sprintf(
            '%d first-party %s a newer release: %s',
            \count($names),
            1 === \count($names) ? 'constraint excludes' : 'constraints exclude',
            implode(', ', $names),
        ));
    }

    /** Whether the constraints are current could not be determined, which is not a verdict on them. */
    private function skipped(ToolContext $context, string $reason, string ...$unverified): ToolResultDto
    {
        $context->writeln(\sprintf(
            'SKIPPED %s (%s): could not determine whether first-party dependency constraints are current: %s. first-party constraints were NOT verified for: %s.',
            DependencyFreshnessTool::NAME,
            DependencyFreshnessTool::IDENTIFIER,
            $reason,
            implode(', ', $unverified),
        ));

        return ToolResultDto::skipped($reason);
    }
}
