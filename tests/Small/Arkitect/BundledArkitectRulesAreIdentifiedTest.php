<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Arkitect;

use LTS\PHPQA\PHPStan\RuleDocResolver;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Detector specification 4.3 and 6.1 to 6.3, for the PHPArkitect rules this
 * package bundles. Arkitect prints a rule's `because` clause, unaltered, with
 * every violation, in its text and its JSON output alike; the clause is
 * written once by the rule's author, here. So each bundled rule ends its
 * clause with its identifier in brackets, `[phpqaci.interfaceSuffix]`, which
 * is then printed with every finding, selects the rule for
 * `arkitect-rule <identifier> <file>`, and resolves through `rule-doc`.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class BundledArkitectRulesAreIdentifiedTest extends TestCase
{
    /** The repository whose bundled tiers and rule index are checked. */
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** Every bundled PHPArkitect tier: default, optional and optional-symfony. */
    private const string TIERS = self::REPO_ROOT . '/configDefaults/generic/phparkitect-rules-*.php';

    /** One rule, from where it starts to its because clause; the clause's closing quote ends it. */
    private const string RULE = "/Rule::allClasses\\(\\).*?->because\\('((?:[^'\\\\]|\\\\.)*)'\\)/s";

    /** The identifier a clause ends with. */
    private const string IDENTIFIER = '/ \[(phpqaci\.[A-Za-z]+)\]$/';

    #[Test]
    public function everyBundledRuleEndsItsBecauseClauseWithAnIdentifier(): void
    {
        $unidentified = [];
        foreach ($this->clausesByTier() as $tier => $clauses) {
            self::assertCount(substr_count(\Safe\file_get_contents($tier), 'Rule::allClasses()'), $clauses, $tier . ': a rule whose because clause this test cannot read');
            foreach ($clauses as $clause) {
                if (1 !== \Safe\preg_match(self::IDENTIFIER, $clause)) {
                    $unidentified[] = basename($tier) . ': ' . $clause;
                }
            }
        }

        self::assertSame([], $unidentified, 'end each because clause with " [phpqaci.<identifier>]"');
    }

    #[Test]
    public function noTwoBundledRulesShareAnIdentifier(): void
    {
        $identifiers = $this->identifiers();

        self::assertSame(array_values(array_unique($identifiers)), $identifiers);
    }

    #[Test]
    public function everyIdentifierResolvesToAPageThatExists(): void
    {
        $resolver   = new RuleDocResolver(self::REPO_ROOT);
        $unresolved = array_values(array_filter(
            $this->identifiers(),
            static function (string $identifier) use ($resolver): bool {
                $page = $resolver->docPathFor($identifier);

                return null === $page || !is_file($page);
            },
        ));

        self::assertNotSame([], $this->identifiers(), 'the tiers yielded no identifiers, so this test checked nothing');
        self::assertSame([], $unresolved, 'add a row to docs/phpstan-rules/README.md linking each to its page');
    }

    #[Test]
    public function eachIdentifierNamesItsOwnRuleInTheIndex(): void
    {
        $resolver = new RuleDocResolver(self::REPO_ROOT);
        foreach ($this->identifiers() as $identifier) {
            self::assertFileExists($resolver->resolve($identifier)->sourcePath, $identifier . ': the index row names no tier file that exists');
        }
    }

    /** @return list<string> */
    private function identifiers(): array
    {
        $identifiers = [];
        foreach ($this->clausesByTier() as $clauses) {
            foreach ($clauses as $clause) {
                if (1 === \Safe\preg_match(self::IDENTIFIER, $clause, $match) && isset($match[1])) {
                    $identifiers[] = $match[1];
                }
            }
        }

        return $identifiers;
    }

    /** @return array<string, list<string>> tier file => the because clause of each rule in it */
    private function clausesByTier(): array
    {
        $tiers = \Safe\glob(self::TIERS);
        self::assertNotSame([], $tiers, 'the bundled tiers are not where this test looks');

        $clauses = [];
        foreach ($tiers as $tier) {
            self::assertIsString($tier);
            \Safe\preg_match_all(self::RULE, \Safe\file_get_contents($tier), $matches);
            $found          = $matches[1] ?? [];
            $clauses[$tier] = \is_array($found) ? array_values(array_filter($found, is_string(...))) : [];
        }

        return $clauses;
    }
}
