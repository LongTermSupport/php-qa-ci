<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\ComposerChecks;

/**
 * Whether a project's composer.json gets the protection of Composer 2.11's
 * `config.policy.cooldown`: a Composer that applies it, a non-zero period, the
 * cooldown not switched off, and the project's own vendor exempt with a reason
 * (its own releases must not wait out a cooldown meant for strangers').
 *
 * Decidable from the manifest and the installed Composer's version alone. A
 * shape this check does not recognise is a finding by name, never a guess.
 *
 * @internal
 */
final readonly class CooldownPolicyChecker
{
    private const string MINIMUM_COMPOSER = '2.11';

    private const string WILDCARD = '*';

    private const string COOLDOWN_PATH = 'config.policy.cooldown';

    private const string PERIOD_PATTERN = '/^(\d+\s*(second|minute|hour|day|week)s?|\d+)?$/';

    private const string CORRECT_BLOCK = '"config": {"policy": {"cooldown": {"period": "7 days", "ignore": {"<vendor>/*": "<why>"}}}}';

    private const string PERIOD_FIX = 'TO FIX: set "period": "7 days" (or any positive duration) under config.policy.cooldown.';

    private const string UNRECOGNISED = ' has a shape this check does not recognise; the correct construction is: ';

    private const string OVERRIDE_PATTERN = '~^SECURITY (CVE-\d{4}-\d{4,}|GHSA(?:-[a-z0-9]{4}){3}|PKSA(?:-[a-z0-9]{4}){3}): fixed in (v?\d[0-9A-Za-z.+_-]*); PR (https?://[^\s;]+); remove after (\d{4}-\d{2}-\d{2})$~i';

    private const string OVERRIDE_FIX = 'an override is one exact package with the reason "SECURITY <advisory id>: fixed in <version>; PR <url>; remove after <YYYY-MM-DD>", for example "thirdparty/lib": "SECURITY CVE-2026-1234: fixed in 1.2.3; PR https://example.com/acme/app/pull/7; remove after 2026-11-20".';

    private const string REASON = 'reason';

    private const string ON_BLOCK = 'on-block';

    /**
     * @param array<array-key, mixed> $composerJson
     * @param ?string                 $versionOutput      what `composer --version` printed; null when it could not be run
     * @param string                  $today              `Y-m-d`, the day a security override's remove-after date is judged against
     * @param string                  ...$internalVendors vendors besides the root package's own that must be exempted by a glob
     */
    public function check(array $composerJson, ?string $versionOutput, string $today, string ...$internalVendors): CooldownReport
    {
        $vendors = array_values(array_unique([...array_filter([$this->ownVendor($composerJson)]), ...$internalVendors]));

        return $this->versionReport($versionOutput)->merge($this->policyReport($composerJson, $vendors, $today));
    }

    private function versionReport(?string $versionOutput): CooldownReport
    {
        $found = null === $versionOutput ? 0 : \Safe\preg_match('/Composer version (\d+\.\d+(?:\.\d+)?)/', $versionOutput, $matches);
        if (0 === $found) {
            return new CooldownReport(['could not read the installed Composer version from `composer --version`, so it is not known whether the cooldown is applied. TO FIX: make `composer --version` run, and use Composer ' . self::MINIMUM_COMPOSER . ' or newer.']);
        }

        $version = $matches[1];
        if (version_compare($version, self::MINIMUM_COMPOSER, '<')) {
            return new CooldownReport([\sprintf('Composer %s is older than %s, the first release that applies config.policy.cooldown: an older Composer ignores it silently. TO FIX: upgrade Composer to %s or newer wherever dependencies are resolved.', $version, self::MINIMUM_COMPOSER, self::MINIMUM_COMPOSER)]);
        }

        return new CooldownReport();
    }

    /**
     * @param array<array-key, mixed> $composerJson
     * @param list<string>            $vendors
     */
    private function policyReport(array $composerJson, array $vendors, string $today): CooldownReport
    {
        $config = $composerJson['config'] ?? null;
        if (null === $config) {
            return $this->noCooldown();
        }

        if (!\is_array($config)) {
            return $this->unrecognised('config');
        }

        $policy = $config['policy'] ?? null;
        if (null === $policy || true === $policy) {
            return $this->noCooldown();
        }

        if (false === $policy) {
            return $this->switchedOff('config.policy is false');
        }

        if (!\is_array($policy)) {
            return $this->unrecognised('config.policy');
        }

        $cooldown = $policy['cooldown'] ?? null;
        if (null === $cooldown) {
            return $this->noCooldown();
        }

        if (false === $cooldown) {
            return $this->switchedOff(self::COOLDOWN_PATH . ' is false');
        }

        if (true === $cooldown) {
            return new CooldownReport([self::COOLDOWN_PATH . ' is true but its period is not set, so nothing is withheld. ' . self::PERIOD_FIX]);
        }

        if (!\is_array($cooldown)) {
            return $this->unrecognised(self::COOLDOWN_PATH);
        }

        return $this->cooldownReport($cooldown, $vendors, $today);
    }

    /**
     * @param array<array-key, mixed> $cooldown
     * @param list<string>            $vendors
     */
    private function cooldownReport(array $cooldown, array $vendors, string $today): CooldownReport
    {
        $report = new CooldownReport();
        if (false === ($cooldown['block'] ?? null)) {
            $report = $this->switchedOff(self::COOLDOWN_PATH . '.block is false');
        }

        return $report->merge($this->periodReport($cooldown['period'] ?? null))->merge($this->ignoreReport($cooldown['ignore'] ?? null, $vendors, $today));
    }

    private function periodReport(mixed $period): CooldownReport
    {
        $path = self::COOLDOWN_PATH . '.period';
        if (null === $period || '' === $period) {
            return new CooldownReport([$path . ' is not set, so nothing is withheld. ' . self::PERIOD_FIX]);
        }

        if (\is_string($period)) {
            $period = trim($period);
            if (0 === \Safe\preg_match(self::PERIOD_PATTERN, $period)) {
                return $this->malformedPeriod($path);
            }

            \Safe\preg_match('/^\d*/', $period, $digits);
            $period = '' === $digits[0] ? 0 : (int)$digits[0];
        }

        if (!\is_int($period) || $period < 0) {
            return $this->malformedPeriod($path);
        }

        return 0 === $period
            ? new CooldownReport([$path . ' is zero, so nothing is withheld. ' . self::PERIOD_FIX])
            : new CooldownReport();
    }

    private function malformedPeriod(string $path): CooldownReport
    {
        return new CooldownReport([$path . ' is not a form Composer accepts (a string such as "7 days" or "1 week", or a non-negative integer of seconds). ' . self::PERIOD_FIX]);
    }

    /**
     * @param list<string> $vendors internal vendors, each exempted by a `<vendor>/*` glob; every other entry is a security override
     */
    private function ignoreReport(mixed $ignore, array $vendors, string $today): CooldownReport
    {
        $path = self::COOLDOWN_PATH . '.ignore';
        if (null !== $ignore && !\is_array($ignore)) {
            return $this->unrecognised($path);
        }

        $globs  = array_map(static fn (string $vendor): string => $vendor . '/' . self::WILDCARD, $vendors);
        $report = new CooldownReport();
        $seen   = [];
        foreach ($ignore ?? [] as $key => $value) {
            [$pattern, $entry] = \is_int($key) ? [$value, null] : [$key, $value];
            if (!\is_string($pattern)) {
                $report = $report->merge(new CooldownReport(['an ' . $path . ' entry' . self::UNRECOGNISED . '"ignore": {"<pattern>": "<why>"}']));

                continue;
            }

            if (\in_array($pattern, $globs, true)) {
                $seen[]  = $pattern;
                $problem = $this->ownEntryProblem($pattern, $entry);
                $report  = null === $problem ? $report : $report->merge(new CooldownReport([$problem]));

                continue;
            }

            $report = $report->merge($this->overrideReport($pattern, $entry, $today));
        }

        foreach (array_diff($globs, $seen) as $missing) {
            $report = $report->merge(new CooldownReport([\sprintf('%s has no "%s" entry: that vendor\'s own releases would wait out the cooldown too. TO FIX: add "ignore": {"%s": "<why>"} under config.policy.cooldown.', $path, $missing, $missing)]));
        }

        return $report;
    }

    /**
     * An ignore entry outside the internal vendors is a security override:
     * one exact package, with a reason in the one form a reviewer can audit.
     * It is listed whatever its verdict.
     */
    private function overrideReport(string $package, mixed $entry, string $today): CooldownReport
    {
        $quoted = \sprintf('the ignore entry "%s"', $package);
        $reason = $this->overrideReason($entry);
        if (false === $reason) {
            return new CooldownReport([$quoted . ' has a shape this check does not recognise; ' . self::OVERRIDE_FIX], [$package . ': (shape not recognised)']);
        }

        $problems = [];
        if (str_contains($package, self::WILDCARD)) {
            $problems[] = $quoted . ' is a glob, and only an internal vendor may be exempted by a glob. TO FIX: name the one package exactly; ' . self::OVERRIDE_FIX;
        }

        $parts = null === $reason ? 0 : \Safe\preg_match(self::OVERRIDE_PATTERN, $reason, $matches);
        if (0 === $parts) {
            $problems[] = $quoted . ' is an override outside the internal vendors and its reason is not in the required form. TO FIX: ' . self::OVERRIDE_FIX;

            return new CooldownReport($problems, [\sprintf('%s: (reason not in the required form) %s', $package, $reason ?? 'no reason')]);
        }

        [, $advisory, $fixedIn, , $removeAfter] = $matches;
        $listing                                = \sprintf('%s: %s, fixed in %s, remove after %s', $package, $advisory, $fixedIn, $removeAfter);
        $problems                               = [...$problems, ...$this->dateProblems($quoted, $removeAfter, $today)];

        return new CooldownReport($problems, [$listing]);
    }

    /** @return list<string> */
    private function dateProblems(string $quoted, string $removeAfter, string $today): array
    {
        [$year, $month, $day] = array_map(intval(...), explode('-', $removeAfter));
        if (!checkdate($month, $day, $year)) {
            return [\sprintf('%s: the remove-after date %s is not a valid calendar date. TO FIX: use a real date, YYYY-MM-DD.', $quoted, $removeAfter)];
        }

        if ($removeAfter < $today) {
            return [\sprintf('%s: the remove-after date %s has passed. TO FIX: remove the entry (the fixed release is old enough to install), or, if the override is still needed, record why with a new date in the same form.', $quoted, $removeAfter)];
        }

        return [];
    }

    /** The reason text of an override entry: null for none, false for a shape nobody recognises. */
    private function overrideReason(mixed $entry): string|false|null
    {
        if (null === $entry || \is_string($entry)) {
            return $entry;
        }

        if (\is_array($entry) && !array_is_list($entry)) {
            $reason = $entry[self::REASON] ?? null;

            return \is_string($reason) ? $reason : null;
        }

        return false;
    }

    private function ownEntryProblem(string $pattern, mixed $entry): ?string
    {
        $rules = \is_array($entry) && array_is_list($entry) && [] !== $entry ? $entry : [$entry];
        $first = null;
        foreach ($rules as $rule) {
            $diagnosis = $this->ruleDiagnosis($rule);
            if (null === $diagnosis) {
                return null;
            }

            $first ??= $diagnosis;
        }

        return \sprintf('the ignore entry "%s" %s. TO FIX: "ignore": {"%s": "<why>"} with a non-empty reason, no version constraint, and on-block left on.', $pattern, $first, $pattern);
    }

    /** What is wrong with one ignore rule, or null when it exempts with a reason. */
    private function ruleDiagnosis(mixed $rule): ?string
    {
        if (null === $rule || \is_string($rule)) {
            return '' === trim((string)$rule) ? 'has no reason' : null;
        }

        if (!\is_array($rule)) {
            return 'has a shape this check does not recognise';
        }

        $reason = $rule[self::REASON] ?? null;
        if (!\is_string($reason) || '' === trim($reason)) {
            return 'has no reason';
        }

        if (false === ($rule[self::ON_BLOCK] ?? null)) {
            return 'does not apply when versions are blocked (on-block is false)';
        }

        return isset($rule['constraint']) ? 'is limited to a version constraint, so other versions still wait out the cooldown' : null;
    }

    /**
     * @param array<array-key, mixed> $composerJson
     */
    private function ownVendor(array $composerJson): ?string
    {
        $name = $composerJson['name'] ?? null;
        if (!\is_string($name) || !str_contains($name, '/')) {
            return null;
        }

        return explode('/', $name, 2)[0];
    }

    private function noCooldown(): CooldownReport
    {
        return new CooldownReport(['no cooldown configured: dependencies are installed the moment they are published. TO FIX: ' . self::CORRECT_BLOCK]);
    }

    private function switchedOff(string $what): CooldownReport
    {
        return new CooldownReport([$what . ', which switches the cooldown off. TO FIX: remove that setting and use ' . self::CORRECT_BLOCK]);
    }

    private function unrecognised(string $path): CooldownReport
    {
        return new CooldownReport([$path . self::UNRECOGNISED . self::CORRECT_BLOCK]);
    }
}
