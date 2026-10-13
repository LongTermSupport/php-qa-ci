<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
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
 * A local variable PHPStan types as a literal string is owned without that, but
 * PHPStan does not see every write: extract() may replace it with anything. So
 * every variable the pattern names outside a preg_quote() value is recorded
 * too, for the rule to refuse the call where its function writes a variable
 * that cannot be named.
 *
 * Each value is [the function's name as written, line, start offset, the
 * local variables the pattern is built from, or null when it is not owned
 * whatever they hold, function key, every variable the pattern names].
 *
 * @internal
 *
 * @implements Collector<FuncCall, array{string, int, int, list<string>|null, string, list<string>}>
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

    /** The name PHP and thecodingmachine/safe both give the pattern argument. */
    private const string PATTERN_PARAMETER = 'pattern';

    /** Escapes the value it is given, so a pattern holds that value literally. */
    private const string QUOTE = 'preg_quote';

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
     * @return array{string, int, int, list<string>|null, string, list<string>}|null
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
        $named        = $pattern instanceof Expr ? $this->variablesNamed($pattern, $scope) : [];
        if ([] === $dependencies && [] === $named) {
            return null;
        }

        return [
            $node->name->toString(),
            $node->getStartLine(),
            $node->getStartFilePos(),
            $dependencies,
            RegexPatternOwnership::functionKey($scope),
            $named,
        ];
    }

    /**
     * Every variable the pattern names, but the value preg_quote() is given: the
     * pattern is owned or not whatever that holds, since it is matched literally.
     *
     * @return list<string>
     */
    private function variablesNamed(Expr $pattern, Scope $scope): array
    {
        $finder = new NodeFinder();
        $quoted = [];
        foreach ($finder->findInstanceOf($pattern, FuncCall::class) as $call) {
            $value = $call->isFirstClassCallable() ? null : $call->getArgs()[0] ?? null;
            if ($value instanceof Arg && $call->name instanceof Name && self::QUOTE === $this->reflectionProvider->resolveFunctionName($call->name, $scope)) {
                foreach ($finder->findInstanceOf($value->value, Variable::class) as $variable) {
                    $quoted[spl_object_id($variable)] = true;
                }
            }
        }

        $names = [];
        foreach ($finder->findInstanceOf($pattern, Variable::class) as $variable) {
            if (\is_string($variable->name) && 'this' !== $variable->name && !isset($quoted[spl_object_id($variable)])) {
                $names[] = $variable->name;
            }
        }

        return array_values(array_unique($names));
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
