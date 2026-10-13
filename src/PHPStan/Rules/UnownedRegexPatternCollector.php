<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Collects each PCRE call whose pattern the code may not own
 * ({@see RegexPatternOwnership}). Whether such a pattern compiles is not known
 * until it runs.
 *
 * A pattern built from local variables is owned when every write to each of
 * them is, which the rule decides from RegexPatternVariableWriteCollector, so
 * the variables are recorded with the call.
 *
 * Each value is [the function's name as written, line, start offset, the
 * local variables the pattern is built from, or null when it is not owned
 * whatever they hold, function key].
 *
 * @internal
 *
 * @implements Collector<FuncCall, array{string, int, int, list<string>|null, string}>
 */
final readonly class UnownedRegexPatternCollector implements Collector
{
    /** Each PCRE function, PHP's or thecodingmachine/safe's, => whether its pattern is the keys of an array. */
    private const array PATTERN_FUNCTIONS = [
        'preg_match'                       => false,
        'preg_match_all'                   => false,
        'preg_replace'                     => false,
        'preg_replace_callback'            => false,
        'preg_filter'                      => false,
        'preg_split'                       => false,
        'preg_grep'                        => false,
        'preg_replace_callback_array'      => true,
        'safe\preg_match'                  => false,
        'safe\preg_match_all'              => false,
        'safe\preg_replace'                => false,
        'safe\preg_replace_callback'       => false,
        'safe\preg_split'                  => false,
        'safe\preg_grep'                   => false,
        'safe\preg_replace_callback_array' => true,
    ];

    private const string PATTERN_PARAMETER = 'pattern';

    private RegexPatternOwnership $ownership;

    public function __construct(private ReflectionProvider $reflectionProvider)
    {
        $this->ownership = new RegexPatternOwnership($reflectionProvider);
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return array{string, int, int, list<string>|null, string}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node->name instanceof Name || $node->isFirstClassCallable()) {
            return null;
        }

        $function = strtolower((string)$this->reflectionProvider->resolveFunctionName($node->name, $scope));
        if (!\array_key_exists($function, self::PATTERN_FUNCTIONS)) {
            return null;
        }

        $pattern      = $this->patternArgument($node);
        $dependencies = $pattern instanceof Expr ? $this->ownership->dependencies($pattern, $scope, self::PATTERN_FUNCTIONS[$function]) : null;
        if ([] === $dependencies) {
            return null;
        }

        return [
            $node->name->toString(),
            $node->getStartLine(),
            $node->getStartFilePos(),
            $dependencies,
            RegexPatternOwnership::functionKey($scope),
        ];
    }

    /** The pattern, by name or as the first positional argument; null when the arguments are unpacked and it cannot be seen. */
    private function patternArgument(FuncCall $call): ?Expr
    {
        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                return null;
            }

            if (null === $arg->name ? 0 === $position : self::PATTERN_PARAMETER === $arg->name->toString()) {
                return $arg->value;
            }
        }

        return null;
    }
}
