<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a toolchain that does not run its own defences on itself".
 *
 * The bundled rules reach every consuming project through the PHPStan extension
 * installer, which reads extra.phpstan.includes from each INSTALLED package. It
 * never reads the root package, so when php-qa-ci analyses its own source that
 * mechanism delivers nothing, and the self-check runs none of the rules this
 * package exists to ship. A defect in a rule's own code is then unseen by every
 * rule built to see it, and a clean self-check is believed because nobody expects
 * a clean run to have run nothing. That is how ForbidMockingFinalClassRule's
 * unanchored vendor/ check lived in a rule file undetected.
 *
 * The self-check configuration must therefore include the bundled rule sets
 * explicitly. This guard reads the configuration rather than running PHPStan so
 * it stays a small test; the pipeline's own phpstan lane proves the rules fire.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
#[\PHPUnit\Framework\Attributes\Small]
final class SelfCheckRunsBundledRulesTest extends TestCase
{
    private const string SELF_CHECK_CONFIG = __DIR__ . '/../../qaConfig/phpstan.neon';

    /** @var list<string> every rule set a consumer can receive from this package */
    private const array BUNDLED_RULE_SETS = [
        'rules-default.neon',
        'rules-optional.neon',
    ];

    public function testTheSelfCheckIncludesEveryBundledRuleSet(): void
    {
        $config = \Safe\file_get_contents(self::SELF_CHECK_CONFIG);

        $missing = [];
        foreach (self::BUNDLED_RULE_SETS as $ruleSet) {
            if (!str_contains($config, '../' . $ruleSet)) {
                $missing[] = $ruleSet;
            }
        }

        self::assertSame(
            [],
            $missing,
            'qaConfig/phpstan.neon must include every bundled rule set, otherwise php-qa-ci is the one '
            . 'project in which its own rules never run. Add each missing file under includes:.',
        );
    }
}
