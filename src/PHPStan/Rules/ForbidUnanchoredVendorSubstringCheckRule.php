<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids deciding "is this file mine, or a dependency's" with a bare, unanchored
 * 'vendor/' substring check.
 *
 * THE DEFECT THIS GUARDS AGAINST:
 * ================================
 * ForbidMockingFinalClassRule used to skip any class whose file
 * `str_contains($fileName, '/vendor/')`, intending to skip only genuinely
 * third-party classes it could not add an interface to. That reasoning holds
 * only when the ANALYSED PROJECT's own source can never itself sit under a
 * path containing the substring 'vendor/'. php-qa-ci is routinely developed
 * in place inside a consuming project's `vendor/lts/php-qa-ci/` checkout (see
 * this project's own CLAUDE.md, "dogfooding" section) — so php-qa-ci's own
 * final classes ALSO matched the substring, and the rule went silently dark
 * for the one project it is most often edited inside.
 *
 * THE FIX:
 * ========
 * Decide ownership against the analysed project's own root, never against the
 * bare substring. VendoredCodeDetector (this directory) is the shared, tested
 * implementation and is wired from PHPStan's %currentWorkingDirectory%; inject
 * it rather than re-deriving the check. Full guidance, with the correct
 * construction, is in docs/phpstan-rules/forbid-unanchored-vendor-substring-check.md.
 *
 * WHAT THIS RULE FLAGS:
 * ======================
 * A call to str_contains(), strpos(), stripos(), str_starts_with() or
 * str_ends_with() where one argument is a bare string literal containing the
 * substring 'vendor/'. A literal concatenated with a variable (e.g.
 * `$this->projectRoot . '/vendor/...'`) is NOT flagged — concatenation with a
 * root variable is exactly the anchored form this rule steers authors
 * towards, and is how ForbidHttpPrefixedEnvVarsRule already does it safely.
 *
 * @implements Rule<FuncCall>
 */
final readonly class ForbidUnanchoredVendorSubstringCheckRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.unanchoredVendorSubstringCheck';

    /** @var list<string> */
    private const array TARGET_FUNCTIONS = ['str_contains', 'strpos', 'stripos', 'str_starts_with', 'str_ends_with'];

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

        // NARROWING (not a suppression): this rule's OWN detection call, immediately
        // below, is itself a str_contains(..., 'vendor/') call and would otherwise
        // match its own pattern. It does not carry the Hazard this rule defends
        // against: its subject is $arg->value->value, the literal TEXT of a String
        // AST node found in code under analysis, never a filesystem path variable
        // (e.g. ClassReflection::getFileName()) used to decide project-vs-vendor
        // ownership. Excluding this rule's own class by name is therefore precision,
        // not suppression — no other legitimate vendor/-classification code should
        // ever share this rule's exact self-referential shape.
        $classReflection = $scope->getClassReflection();
        if ($classReflection instanceof \PHPStan\Reflection\ClassReflection
            && self::class === $classReflection->getName()) {
            return [];
        }

        foreach ($node->args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }

            if (!$arg->value instanceof String_) {
                continue;
            }

            if (!str_contains($arg->value->value, 'vendor/')) {
                continue;
            }

            return [
                RuleErrorBuilder::message(
                    \sprintf(
                        "Unanchored 'vendor/' substring check: %s(..., '%s'). It misclassifies this project's own "
                        . 'source whenever the project sits under a vendor/ path. Use VendoredCodeDetector, or anchor '
                        . 'on the project root. See docs/phpstan-rules/forbid-unanchored-vendor-substring-check.md',
                        $functionName,
                        $arg->value->value,
                    ),
                )
                    ->identifier(self::IDENTIFIER)
                    ->build(),
            ];
        }

        return [];
    }
}
