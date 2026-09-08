<?php

namespace LTS\PHPQA\Tests\Assets\PHPStan\StrictTypes;

/*
 * Violation fixture for RequireDeclareStrictTypesRule: it has NO
 * declare(strict_types=1) statement, so the AST-based rule flags it.
 *
 * NOTE for maintainers: the pipeline's own phpStrictTypes gate is a plain text
 * scan for the substring "strict_types" (see PhpStrictTypesTool)
 * and tests/assets is not excluded from it, so this comment MUST keep mentioning
 * strict_types — that substring is what keeps the gate green for this deliberately
 * declaration-less fixture. The rule under test parses the AST (not the text), so
 * it is not fooled by this comment.
 */
final class MissingStrictTypes
{
}
