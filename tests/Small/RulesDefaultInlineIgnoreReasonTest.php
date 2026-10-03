<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use Nette\Neon\Neon;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for detector specification 7.2: an inline suppression must carry a
 * written reason.
 *
 * The default tier already forbids inline PHPStan ignore comments outright
 * (ForbidInlinePhpstanIgnoreRule), but that rule is itself a finding a project
 * can exclude for a path. PHPStan's own reportIgnoresWithoutComments is the
 * second line behind it: with it on, an inline ignore naming an identifier but
 * giving no `(reason)` is reported as `ignore.noComment`, and the line-wide
 * forms as `ignore.allLineErrors`. It ships in rules-default.neon so it
 * reaches every consumer the bundled rules reach.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class RulesDefaultInlineIgnoreReasonTest extends TestCase
{
    private const string RULES_DEFAULT = __DIR__ . '/../../rules-default.neon';

    #[Test]
    public function theDefaultTierRequiresAReasonOnEveryInlineIgnore(): void
    {
        $decoded    = Neon::decode(\Safe\file_get_contents(self::RULES_DEFAULT));
        $parameters = \is_array($decoded) ? ($decoded['parameters'] ?? null) : null;

        self::assertIsArray($parameters);
        self::assertTrue($parameters['reportIgnoresWithoutComments'] ?? null);
    }
}
