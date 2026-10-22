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

    /**
     * The bundles a project can include. The Symfony one includes the generic one, so each file
     * contributes only the rules it declares itself.
     *
     * @var list<string>
     */
    private const array BUNDLES = ['rules-optional.neon', 'rules-optional-symfony.neon'];

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
                ['FirstRule' => 'rules-optional.neon', 'SecondRule' => 'rules-optional.neon', 'ThirdRule' => 'rules-optional.neon'],
                $this->tableRows($page),
            ),
        );
    }

    /**
     * @param array<string, string> $declared rule short name => bundle file
     * @param array<string, string> $listed   rule short name => file named in the table
     *
     * @return list<string>
     */
    private function drift(array $declared, array $listed): array
    {
        $drift = [];

        foreach ($listed as $rule => $file) {
            if (!isset($declared[$rule])) {
                $drift[] = \sprintf('%s: listed in the table, declared in no bundle', $rule);

                continue;
            }

            if ($declared[$rule] !== $file) {
                $drift[] = \sprintf('%s: listed under %s, declared in %s', $rule, $file, $declared[$rule]);
            }
        }

        foreach ($declared as $rule => $file) {
            if (!isset($listed[$rule])) {
                $drift[] = \sprintf('%s: missing from the table, declared in %s', $rule, $file);
            }
        }

        return $drift;
    }

    /**
     * @return array<string, string> rule short name => the file the table names
     */
    private function tableRows(string $page): array
    {
        \Safe\preg_match_all('/^\| `([A-Za-z]+Rule)`\s*\| `(rules-optional[a-z-]*\.neon)`/m', $page, $matches);

        $rows = [];
        foreach ($matches[1] as $position => $rule) {
            $rows[$rule] = $matches[2][$position];
        }

        return $rows;
    }

    /**
     * @return array<string, string> rule short name => the bundle that declares it
     */
    private function declaredRules(): array
    {
        $declared = [];

        foreach (self::BUNDLES as $bundle) {
            $neon = Neon::decode(\Safe\file_get_contents(self::REPO_ROOT . '/' . $bundle));
            self::assertIsArray($neon, $bundle . ' does not decode to a map');

            foreach ($this->ruleClasses($neon) as $class) {
                $declared[substr($class, (int)strrpos($class, '\\') + 1)] = $bundle;
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
