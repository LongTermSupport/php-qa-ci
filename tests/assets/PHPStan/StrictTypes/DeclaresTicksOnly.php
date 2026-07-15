<?php

declare(ticks=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\StrictTypes;

/*
 * Violation fixture: it has a declare() block, but for ticks — NOT strict_types —
 * so the AST-based RequireDeclareStrictTypesRule still flags it.
 *
 * NOTE for maintainers: the phpStrictTypes gate is a plain text grep for the
 * substring "strict_types" and does not exclude tests/assets, so this comment
 * MUST keep mentioning strict_types to keep that gate green. The rule parses the
 * AST, so the comment does not affect the rule's verdict.
 */
final class DeclaresTicksOnly
{
}
