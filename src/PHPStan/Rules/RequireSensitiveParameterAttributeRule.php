<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires the native #[\SensitiveParameter] attribute on plaintext credential parameters.
 *
 * THE PROBLEM THIS SOLVES:
 * ========================
 * When an exception is thrown, PHP records every function argument in
 * Throwable::getTrace(). A password, token or secret passed as a plain string
 * therefore ends up in stack traces, logs and error reporters in clear text.
 *
 * PHP 8.2+ ships the #[\SensitiveParameter] attribute: PHP replaces a so-marked
 * argument with a SensitiveParameterValue placeholder in the trace, so the real
 * value never leaks.
 *
 * THE SOLUTION:
 * =============
 * This rule flags any parameter whose NAME looks like a credential AND whose
 * type is a plausible plaintext value (string, ?string, untyped, or mixed) when
 * it is missing the attribute:
 *
 *     // FLAGGED
 *     public function login(string $password): bool
 *
 *     // OK
 *     public function login(#[\SensitiveParameter] string $password): bool
 *
 * What is deliberately NOT flagged:
 *   - Object-typed params (e.g. `Credentials $credential`) — not a plaintext value.
 *   - Already-hashed/encoded values (e.g. `$hashedPassword`, `$passwordHash`) —
 *     not sensitive plaintext, matched via the ignore-substrings list.
 *
 * Both the credential name patterns and the ignore-substrings are configurable
 * via the constructor (wired from neon parameters) with sensible defaults baked in.
 *
 * @implements Rule<FunctionLike>
 */
final readonly class RequireSensitiveParameterAttributeRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.requireSensitiveParameterAttribute';

    /**
     * Default case-insensitive substrings that mark a parameter name as a credential.
     *
     * @var list<string>
     */
    private const array DEFAULT_NAME_PATTERNS = [
        'password',
        'passwd',
        'pwd',
        'passphrase',
        'secret',
        'apiSecret',
        'privateKey',
        'credential',
        'credentials',
    ];

    /**
     * Default case-insensitive substrings that mark a name as already
     * hashed/encoded/encrypted, a URL/URI, or a file-system path — and therefore
     * NOT plaintext sensitive.
     *
     * Includes: hash, hashed, encoded, encrypted, url, uri, path.
     *
     * @var list<string>
     */
    private const array DEFAULT_IGNORE_SUBSTRINGS = [
        'hash',
        'hashed',
        'encoded',
        'encrypted',
        'url',
        'uri',
        'path',
    ];

    /**
     * @var list<string>
     */
    private array $namePatterns;

    /**
     * @var list<string>
     */
    private array $ignoreSubstrings;

    /**
     * @param list<string> $namePatterns     credential name substrings (case-insensitive); defaults baked in
     * @param list<string> $ignoreSubstrings substrings marking already-hashed/encoded names; defaults baked in
     */
    public function __construct(
        array $namePatterns = self::DEFAULT_NAME_PATTERNS,
        array $ignoreSubstrings = self::DEFAULT_IGNORE_SUBSTRINGS,
    ) {
        $this->namePatterns     = array_map(strtolower(...), $namePatterns);
        $this->ignoreSubstrings = array_map(strtolower(...), $ignoreSubstrings);
    }

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @param FunctionLike $node
     *
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $context = $this->describeContext($node, $scope);

        $errors = [];
        foreach ($node->getParams() as $param) {
            $error = $this->checkParam($param, $context);
            if ($error instanceof \PHPStan\Rules\IdentifierRuleError) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private function checkParam(Param $param, string $context): ?\PHPStan\Rules\IdentifierRuleError
    {
        if (!$param->var instanceof Node\Expr\Variable || !\is_string($param->var->name)) {
            return null;
        }

        $paramName      = $param->var->name;
        $paramNameLower = strtolower($paramName);

        if (!$this->nameLooksLikeCredential($paramNameLower)) {
            return null;
        }

        if ($this->nameIsIgnored($paramNameLower)) {
            return null;
        }

        if (!$this->typeIsPlausiblePlaintext($param->type)) {
            return null;
        }

        if ($this->hasSensitiveParameterAttribute($param)) {
            return null;
        }

        return RuleErrorBuilder::message(
            \sprintf(
                'Parameter $%s of %s looks like a plaintext credential but is missing the '
                . '#[\SensitiveParameter] attribute. Add #[\SensitiveParameter] so its value '
                . 'is redacted from stack traces.',
                $paramName,
                $context,
            ),
        )
            ->identifier(self::IDENTIFIER)
            ->line($param->getStartLine())
            ->build()
        ;
    }

    private function nameLooksLikeCredential(string $paramNameLower): bool
    {
        return array_any($this->namePatterns, static fn (string $pattern): bool => str_contains($paramNameLower, $pattern));
    }

    private function nameIsIgnored(string $paramNameLower): bool
    {
        return array_any($this->ignoreSubstrings, static fn (string $ignore): bool => str_contains($paramNameLower, $ignore));
    }

    private function typeIsPlausiblePlaintext(Identifier|Node\Name|ComplexType|null $type): bool
    {
        // No declared type — could carry a plaintext value.
        if (null === $type) {
            return true;
        }

        if ($type instanceof Identifier) {
            $name = strtolower($type->name);

            return 'string' === $name || 'mixed' === $name;
        }

        // ?string is a plausible plaintext value; any other nullable (e.g. ?Foo) is not.
        if ($type instanceof NullableType) {
            return $type->type instanceof Identifier && 'string' === strtolower($type->type->name);
        }

        return false;
    }

    private function hasSensitiveParameterAttribute(Param $param): bool
    {
        foreach ($param->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();

                if ('SensitiveParameter' === $name || str_ends_with($name, '\SensitiveParameter')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function describeContext(FunctionLike $node, Scope $scope): string
    {
        if ($node instanceof ClassMethod) {
            $classReflection = $scope->getClassReflection();
            $className       = $classReflection instanceof \PHPStan\Reflection\ClassReflection
                ? $classReflection->getName()
                : '{anonymous}';

            return \sprintf('method %s::%s()', $className, $node->name->toString());
        }

        if ($node instanceof Function_) {
            return \sprintf('function %s()', $node->name->toString());
        }

        return 'closure';
    }
}
