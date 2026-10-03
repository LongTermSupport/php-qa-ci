<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\PHPStan\RuleDocResolver;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a conformance declaration that has drifted from the shape a
 * reader checks it by, or from the repository it describes".
 *
 * composer.json carries extra.defence-before-fix: the version of the method specification
 * this package follows, the version of the toolchain specification the shipped artefact is
 * graded against, and a project object carrying the same keys for the repository's own
 * conformance as a project. Each level carries its own known-gaps list, and every gap names
 * the clause it fails so a reader can find it without re-running the audit.
 *
 * The declaration is a claim about this repository (toolchain specification 9.2), so it is
 * held to the repository rather than to itself:
 *
 *  - the versions are the ones vendored under remote-docs/, so refreshing a specification
 *    fails here until the declaration is re-audited against it;
 *  - every gap names a clause the declared document actually has;
 *  - every gap is either accepted, citing the plan decision that records the Owner's
 *    acceptance, or open, citing the active plan that closes it;
 *  - every accepted gap has a probe showing it is still a gap, so closing one fails here
 *    until the declaration stops claiming it.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class DefenceBeforeFixDeclarationTest extends TestCase
{
    /** The repository the declaration describes. */
    private const string REPO_ROOT = __DIR__ . '/../..';

    /** Where the declaration lives: extra.defence-before-fix. */
    private const string MANIFEST = self::REPO_ROOT . '/composer.json';

    /** The specifications as vendored, verbatim, with provenance. */
    private const string SPEC_DIR = self::REPO_ROOT . '/remote-docs/defence-before-fix.github.io/';

    /** Where the decisions behind accepted gaps, and the plans closing open ones, are kept. */
    private const string PLAN_DIR = self::REPO_ROOT . '/CLAUDE/Plan';

    /** The document a gap names, to the vendored file that carries its clauses. */
    private const array SPEC_FILES = [
        'method'    => 'SPEC.md',
        'detector'  => 'DETECTOR-SPEC.md',
        'toolchain' => 'TOOLING-SPEC.md',
    ];

    /** "toolchain 4.1: " — the document and the clause, then the status. */
    private const string GAP_SHAPE = '/^(method|detector|toolchain) (\d+)\.(\d+): (.*)$/';

    /** An accepted gap opens with this, then cites the decision that recorded it. */
    private const string ACCEPTED = 'accepted by Owner decision';

    /** An open gap opens with this, then cites the plan that closes it. */
    private const string OPEN = 'open';

    /** "(Plan 00010 Decision 5)", "(Plan 00010 Decisions 5 and 6)". */
    private const string DECISION_CITATION = '/\(Plan \d{5} Decisions? \d+(?:(?:, | and )\d+)*\)/';

    /** "(Plan 00010)", for an open gap. */
    private const string PLAN_CITATION = '/\(Plan (\d{5})\)/';

    /** A PHPStan identifier that is not this package's, for the catalogue probe. */
    private const string NATIVE_IDENTIFIER = 'method.notFound';

    public function testTheArtefactLevelDeclaresTheVendoredVersionsAndItsGaps(): void
    {
        $declaration = $this->declaration();

        self::assertSame($this->vendoredVersion('method'), $declaration['method'] ?? null);
        self::assertSame($this->vendoredVersion('toolchain'), $declaration['toolchain'] ?? null);
        self::assertSame([], $this->gapProblems(...$this->gaps($declaration, 'artefact')));
    }

    public function testTheProjectLevelDeclaresTheSameKeysSeparately(): void
    {
        $declaration = $this->declaration();

        self::assertIsArray($declaration['project'] ?? null, 'extra.defence-before-fix.project must be an object');
        $project = $declaration['project'];
        self::assertSame($this->vendoredVersion('method'), $project['method'] ?? null);
        self::assertSame($this->vendoredVersion('toolchain'), $project['toolchain'] ?? null);
        self::assertSame([], $this->gapProblems(...$this->gaps($project, 'project')));
    }

    /**
     * The guard proven to fire, on gaps written to fail each check in turn. A check that
     * has only ever read a clean declaration has not been tested.
     */
    public function testTheGuardReportsEachWayAGapCanDrift(): void
    {
        self::assertSame(
            [
                'no clause: "a gap with no clause"',
                'toolchain 99.9 is not a clause of TOOLING-SPEC.md: "toolchain 99.9: open — (Plan 00010)"',
                'neither accepted nor open: "toolchain 4.1: pending — something"',
                'cites no decision: "toolchain 4.1: accepted by Owner decision — no citation"',
                'Plan 00010 Decision 99 is not recorded: "toolchain 4.1: accepted by Owner decision — x (Plan 00010 Decision 99)"',
                'Plan 00010 Decision 4 has no probe showing the gap is still real: "toolchain 4.1: accepted by Owner decision — x (Plan 00010 Decisions 5 and 4)"',
                'cites no plan: "toolchain 4.1: open — no plan named"',
                'Plan 00001 is not an active plan: "toolchain 4.1: open — closed long ago (Plan 00001)"',
            ],
            $this->gapProblems(
                'a gap with no clause',
                'toolchain 99.9: open — (Plan 00010)',
                'toolchain 4.1: pending — something',
                'toolchain 4.1: accepted by Owner decision — no citation',
                'toolchain 4.1: accepted by Owner decision — x (Plan 00010 Decision 99)',
                'toolchain 4.1: accepted by Owner decision — x (Plan 00010 Decisions 5 and 4)',
                'toolchain 4.1: open — no plan named',
                'toolchain 4.1: open — closed long ago (Plan 00001)',
            ),
        );
    }

    /**
     * Each problem with the given gaps, as a line naming the gap.
     *
     * @return list<string>
     */
    private function gapProblems(string ...$gaps): array
    {
        $problems = [];
        foreach ($gaps as $gap) {
            $problem = $this->gapProblem($gap);
            if (null !== $problem) {
                $problems[] = \sprintf('%s: "%s"', $problem, $gap);
            }
        }

        return $problems;
    }

    private function gapProblem(string $gap): ?string
    {
        $document = $this->group(self::GAP_SHAPE, $gap, 1);
        $major    = $this->group(self::GAP_SHAPE, $gap, 2);
        $minor    = $this->group(self::GAP_SHAPE, $gap, 3);
        $status   = $this->group(self::GAP_SHAPE, $gap, 4);
        if (null === $document || null === $major || null === $minor || null === $status) {
            return 'no clause';
        }

        $file = self::SPEC_FILES[$document] ?? null;
        if (null === $file || !str_contains(\Safe\file_get_contents(self::SPEC_DIR . $file), \sprintf('id="%s%s-', $major, $minor))) {
            return \sprintf('%s %s.%s is not a clause of %s', $document, $major, $minor, $file ?? $document);
        }

        if (str_starts_with($status, self::ACCEPTED)) {
            return $this->acceptedGapProblem($gap);
        }

        if (str_starts_with($status, self::OPEN)) {
            return $this->openGapProblem($gap);
        }

        return 'neither accepted nor open';
    }

    private function acceptedGapProblem(string $gap): ?string
    {
        $citations = $this->matchAll(self::DECISION_CITATION, $gap);
        if ([] === $citations) {
            return 'cites no decision';
        }

        foreach ($citations as $citation) {
            $plan = $this->group('/Plan (\d{5})/', $citation, 1);
            foreach ($this->matchAll('/(?<!Plan )(?<!\d)\d{1,4}(?!\d)/', $citation) as $number) {
                $decision = \sprintf('Plan %s Decision %s', $plan, $number);
                if (null === $plan || !$this->decisionIsRecorded($plan, $number)) {
                    return $decision . ' is not recorded';
                }

                $stillReal = $this->gapIsStillReal($decision);
                if (null === $stillReal) {
                    return $decision . ' has no probe showing the gap is still real';
                }

                if (!$stillReal) {
                    return $decision . '\'s gap is closed; remove it from known-gaps';
                }
            }
        }

        return null;
    }

    private function openGapProblem(string $gap): ?string
    {
        $plan = $this->group(self::PLAN_CITATION, $gap, 1);
        if (null === $plan) {
            return 'cites no plan';
        }

        if ([] === $this->files(self::PLAN_DIR . '/' . $plan . '-*/PLAN.md')) {
            return \sprintf('Plan %s is not an active plan', $plan);
        }

        return null;
    }

    /** A decision is recorded as a "Decision N:" heading in its plan, active or completed. */
    private function decisionIsRecorded(string $plan, string $number): bool
    {
        $files = [
            ...$this->files(self::PLAN_DIR . '/' . $plan . '-*/*.md'),
            ...$this->files(self::PLAN_DIR . '/Completed/' . $plan . '-*/*.md'),
        ];
        foreach ($files as $file) {
            if (null !== $this->group('/^#{2,3} Decision ' . $number . ':/m', \Safe\file_get_contents($file), 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The probe for each decision behind an accepted gap, or null where there is none. A
     * decision cited by a gap and absent here is a claim nobody re-checks.
     */
    private function gapIsStillReal(string $decision): ?bool
    {
        return match ($decision) {
            'Plan 00010 Decision 5' => $this->arkitectRulesCarryNoIdentifier(),
            'Plan 00010 Decision 6' => $this->phpstanCatalogueIsOnlineOnly(),
            default                 => null,
        };
    }

    /**
     * Decision 5: the bundled PHPArkitect tier names no rule individually. The day a tier
     * file carries a phpqaci identifier, the per-rule identity the gap records is arriving.
     */
    private function arkitectRulesCarryNoIdentifier(): bool
    {
        $tiers = $this->files(self::REPO_ROOT . '/configDefaults/generic/phparkitect-rules-*.php');
        self::assertNotSame([], $tiers, 'The bundled PHPArkitect tiers are not where the probe looks.');
        foreach ($tiers as $tier) {
            if (str_contains(\Safe\file_get_contents($tier), 'phpqaci.')) {
                return false;
            }
        }

        return true;
    }

    /** Decision 6: a PHPStan identifier still resolves only to the online catalogue. */
    private function phpstanCatalogueIsOnlineOnly(): bool
    {
        return str_contains(
            new RuleDocResolver(self::REPO_ROOT)->render(self::NATIVE_IDENTIFIER),
            'https://phpstan.org/error-identifiers/' . self::NATIVE_IDENTIFIER,
        );
    }

    /** The "Version: X.Y.Z" a vendored specification states for itself. */
    private function vendoredVersion(string $document): string
    {
        $version = $this->group(
            '#<strong>Version</strong>: (\d+\.\d+\.\d+),#',
            \Safe\file_get_contents(self::SPEC_DIR . self::SPEC_FILES[$document]),
            1,
        );
        self::assertNotNull($version, \sprintf('The vendored %s specification states no version', $document));

        return $version;
    }

    /**
     * @param array<array-key, mixed> $level
     *
     * @return list<string>
     */
    private function gaps(array $level, string $name): array
    {
        $gaps = $level['known-gaps'] ?? null;
        self::assertIsArray($gaps, \sprintf('The %s level must carry a known-gaps list, empty when there are none', $name));
        $strings = [];
        foreach ($gaps as $gap) {
            self::assertIsString($gap);
            $strings[] = $gap;
        }

        return $strings;
    }

    /** @return array<array-key, mixed> */
    private function declaration(): array
    {
        $manifest = \Safe\json_decode(\Safe\file_get_contents(self::MANIFEST), true);
        self::assertIsArray($manifest);
        $extra = $manifest['extra'] ?? null;
        self::assertIsArray($extra, 'composer.json must carry an extra section');
        $declaration = $extra['defence-before-fix'] ?? null;
        self::assertIsArray($declaration, 'composer.json must carry extra.defence-before-fix');

        return $declaration;
    }

    /** One capture group of the first match, or null when the pattern does not match. */
    private function group(string $pattern, string $subject, int $group): ?string
    {
        if (1 !== \Safe\preg_match($pattern, $subject, $matches)) {
            return null;
        }

        $value = $matches[$group] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * Every whole match of the pattern, as strings.
     *
     * @return list<string>
     */
    private function matchAll(string $pattern, string $subject): array
    {
        \Safe\preg_match_all($pattern, $subject, $matches);

        $whole = $matches[0] ?? [];
        if (!\is_array($whole)) {
            return [];
        }

        $strings = [];
        foreach ($whole as $value) {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /** @return list<string> */
    private function files(string $pattern): array
    {
        $files = [];
        foreach (\Safe\glob($pattern) as $file) {
            if (\is_string($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
