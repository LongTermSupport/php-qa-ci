<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use Nette\Neon\Neon;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "the page that lists the optional rules is a hand-kept copy of the bundles".
 *
 * docs/tools/phpstan.md has a table of every rule in rules-optional.neon and
 * rules-optional-symfony.neon, and a reader takes it as the list. Each rule merged left it one
 * row short, and nothing noticed. This reads the bundles as PHPStan does, `rules:` plus every
 * service tagged `phpstan.rules.rule`, and holds the table to them: a rule missing from the table,
 * under the wrong file, or in no bundle at all is reported.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
#[\PHPUnit\Framework\Attributes\Small]
final class OptionalRulesPageTest extends TestCase
{
    /** The package itself: this guard measures what php-qa-ci actually publishes. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** The page whose table is held to the bundles. */
    private const string PAGE = self::REPO_ROOT . '/docs/tools/phpstan.md';

    /** The tag PHPStan registers a service as a rule by. */
    private const string RULE_TAG = 'phpstan.rules.rule';

    /** The generic bundle, which the Symfony one includes. */
    private const string GENERIC = 'rules-optional.neon';

    /** The Symfony bundle, which contributes only the rules it declares itself. */
    private const string SYMFONY = 'rules-optional-symfony.neon';

    public function testTheTableListsEveryOptionalRuleUnderItsFile(): void
    {
        $drift = $this->drift($this->declaredRules(), $this->tableRows(\Safe\file_get_contents(self::PAGE)));

        self::assertSame(
            [],
            $drift,
            "The Available optional rules table in docs/tools/phpstan.md differs from the bundles:\n"
            . implode("\n", $drift)
            . "\n\nA reader takes the table as the list of what the bundles enable.",
        );
    }

    /**
     * The guard proven to fire: a table with one rule missing, one under the wrong file and one
     * that no bundle declares. A check only ever run against a clean page has not been tested.
     */
    public function testTheGuardReportsADriftedTable(): void
    {
        $page = implode("\n", [
            '| Rule | File | What it catches |',
            '| --- | --- | --- |',
            '| `FirstRule` | `rules-optional.neon` | first |',
            '| `SecondRule` | `rules-optional-symfony.neon` | second |',
            '| `GhostRule` | `rules-optional.neon` | ghost |',
        ]);

        self::assertSame(
            [
                'SecondRule: listed under rules-optional-symfony.neon, declared in rules-optional.neon',
                'GhostRule: listed in the table, declared in no bundle',
                'ThirdRule: missing from the table, declared in rules-optional.neon',
            ],
            $this->drift(
                [
                    ['rule' => 'FirstRule', 'file' => self::GENERIC],
                    ['rule' => 'SecondRule', 'file' => self::GENERIC],
                    ['rule' => 'ThirdRule', 'file' => self::GENERIC],
                ],
                $this->tableRows($page),
            ),
        );
    }

    /**
     * @param array<int, array{rule: string, file: string}> $declared each rule a bundle registers, with the bundle
     * @param array<int, array{rule: string, file: string}> $listed   each rule the table names, with the file it names
     *
     * @return list<string>
     */
    private function drift(array $declared, array $listed): array
    {
        $drift = [];

        foreach ($listed as $row) {
            $home = $this->fileOf($declared, $row['rule']);
            if (null === $home) {
                $drift[] = \sprintf('%s: listed in the table, declared in no bundle', $row['rule']);
            } elseif ($home !== $row['file']) {
                $drift[] = \sprintf('%s: listed under %s, declared in %s', $row['rule'], $row['file'], $home);
            }
        }

        foreach ($declared as $row) {
            if (null === $this->fileOf($listed, $row['rule'])) {
                $drift[] = \sprintf('%s: missing from the table, declared in %s', $row['rule'], $row['file']);
            }
        }

        return $drift;
    }

    /**
     * @param array<int, array{rule: string, file: string}> $rows
     */
    private function fileOf(array $rows, string $rule): ?string
    {
        return array_find($rows, static fn (array $row): bool => $row['rule'] === $rule)['file'] ?? null;
    }

    /**
     * @return list<array{rule: string, file: string}>
     */
    private function tableRows(string $page): array
    {
        \Safe\preg_match_all('/^\| `([A-Za-z]+Rule)`\s*\| `(rules-optional[a-z-]*\.neon)`/m', $page, $matches, PREG_SET_ORDER);

        $rows = [];
        foreach ($matches ?? [] as $match) {
            if (\is_array($match) && \is_string($match[1] ?? null) && \is_string($match[2] ?? null)) {
                $rows[] = ['rule' => $match[1], 'file' => $match[2]];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{rule: string, file: string}>
     */
    private function declaredRules(): array
    {
        $declared = [];

        foreach ([self::GENERIC, self::SYMFONY] as $bundle) {
            $neon = Neon::decode(\Safe\file_get_contents(self::REPO_ROOT . '/' . $bundle));
            self::assertIsArray($neon, $bundle . ' does not decode to a map');

            foreach ($this->ruleClasses($neon) as $class) {
                $declared[] = ['rule' => substr($class, (int)strrpos($class, '\\') + 1), 'file' => $bundle];
            }
        }

        return $declared;
    }

    /**
     * @param array<array-key, mixed> $neon
     *
     * @return list<string>
     */
    private function ruleClasses(array $neon): array
    {
        $classes = [];

        foreach ((array)($neon['rules'] ?? []) as $class) {
            if (\is_string($class)) {
                $classes[] = $class;
            }
        }

        foreach ((array)($neon['services'] ?? []) as $service) {
            if (\is_array($service) && \in_array(self::RULE_TAG, (array)($service['tags'] ?? []), true) && \is_string($service['class'] ?? null)) {
                $classes[] = $service['class'];
            }
        }

        return $classes;
    }
}
