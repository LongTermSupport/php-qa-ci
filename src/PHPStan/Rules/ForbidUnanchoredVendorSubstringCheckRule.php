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
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;

/**
 * Forbids deciding "is this file mine, or a dependency's" with a bare, unanchored
 * 'vendor/' substring check.
 *
 * ForbidMockingFinalClassRule once skipped any class whose file
 * `str_contains($fileName, '/vendor/')`, meaning to skip only third-party classes. That holds
 * only when the analysed project's own source never sits under a path containing 'vendor/', and
 * php-qa-ci is routinely developed in place inside a consumer's `vendor/lts/php-qa-ci/` checkout
 * (CLAUDE/dogfooding.md): every one of its own classes matched, and the rule went dark there.
 *
 * Decide ownership against the analysed project's own root: VendoredCodeDetector (this
 * directory) is wired from PHPStan's %currentWorkingDirectory%; inject it.
 *
 * Flags a call to str_contains(), strpos(), stripos(), str_starts_with() or str_ends_with() where
 * one argument is a string literal, or a class constant whose value is a constant string,
 * containing 'vendor/'. A fragment concatenated onto a variable (`$this->projectRoot . '/vendor/'`)
 * is the anchored form and is not flagged. The rule's own match is a regular expression, so it
 * is not itself a target call.
 *
 * See docs/phpstan-rules/forbid-unanchored-vendor-substring-check.md.
 *
 * @implements Rule<FuncCall>
 */
final readonly class ForbidUnanchoredVendorSubstringCheckRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.unanchoredVendorSubstringCheck';

    /** @var list<string> */
    private const array TARGET_FUNCTIONS = ['str_contains', 'strpos', 'stripos', 'str_starts_with', 'str_ends_with'];

    /** @var string the fragment the rule looks for, as a pattern */
    private const string VENDOR_FRAGMENT_PATTERN = '~vendor/~';

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @param FuncCall $node
     *
     * @return list<IdentifierRuleError>
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
                if (1 !== \Safe\preg_match(self::VENDOR_FRAGMENT_PATTERN, $value)) {
                    continue;
                }

                return [
                    RuleErrorBuilder::message(
                        \sprintf(
                            "Unanchored 'vendor/' substring check: %s(..., '%s'). It misclassifies this project's own "
                            . 'source whenever the project sits under a vendor/ path. Use VendoredCodeDetector, or anchor '
                            . 'on the project root. See docs/phpstan-rules/forbid-unanchored-vendor-substring-check.md',
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
