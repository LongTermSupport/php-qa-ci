<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;

/**
 * Forbids deciding "is this file test code" with a bare, unanchored 'tests/' substring check.
 *
 * `str_contains($scope->getFile(), '/tests/')` reads the ABSOLUTE path, so it holds for every
 * file of a project whose checkout itself sits under a `tests/` directory (`/home/ci/tests/app/`):
 * the whole project is then test code, and a rule that skips test code skips all of it and
 * reports nothing. Decide against the analysed project's own root instead: TestCodeDetector
 * (this directory) is anchored on PHPStan's %currentWorkingDirectory%.
 *
 * Flags a call to str_contains(), strpos(), stripos(), str_starts_with() or str_ends_with() where
 * one argument is a string literal, or a class constant whose value is a constant string,
 * containing 'tests/'. A fragment concatenated onto a variable (`$root . '/tests/'`) is the
 * anchored form and is not flagged. The rule's own match is a regular expression, so it is not
 * itself a target call.
 *
 * See docs/phpstan-rules/forbid-unanchored-tests-substring-check.md.
 *
 * @implements Rule<FuncCall>
 */
final readonly class ForbidUnanchoredTestsSubstringCheckRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.unanchoredTestsSubstringCheck';

    /** @var list<string> */
    private const array TARGET_FUNCTIONS = ['str_contains', 'strpos', 'stripos', 'str_starts_with', 'str_ends_with'];

    /** @var string the fragment the rule looks for, as a pattern */
    private const string TESTS_FRAGMENT_PATTERN = '~tests/~';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @param FuncCall $node
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = strtolower(ltrim($node->name->toString(), '\\'));
        if (!\in_array($functionName, self::TARGET_FUNCTIONS, true)) {
            return [];
        }

        foreach ($node->args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }

            foreach ($this->constantValues($arg, $scope) as $value) {
                if (1 !== \Safe\preg_match(self::TESTS_FRAGMENT_PATTERN, $value)) {
                    continue;
                }

                return [
                    RuleErrorBuilder::message(
                        \sprintf(
                            "Unanchored 'tests/' substring check: %s(..., '%s'). It classifies every file as test code "
                            . 'whenever the project itself sits under a tests/ path. Use TestCodeDetector, or anchor on '
                            . 'the project root. See docs/phpstan-rules/forbid-unanchored-tests-substring-check.md',
                            $functionName,
                            $value,
                        ),
                    )
                        ->identifier(self::IDENTIFIER)
                        ->build(),
                ];
            }
        }

        return [];
    }

    /** @return list<string> the argument's value when it is a literal, or a class constant PHPStan knows the value of */
    private function constantValues(Arg $arg, Scope $scope): array
    {
        if ($arg->value instanceof String_) {
            return [$arg->value->value];
        }

        if (!$arg->value instanceof ClassConstFetch) {
            return [];
        }

        return array_map(
            static fn (ConstantStringType $string): string => $string->getValue(),
            $scope->getType($arg->value)->getConstantStrings(),
        );
    }
}
