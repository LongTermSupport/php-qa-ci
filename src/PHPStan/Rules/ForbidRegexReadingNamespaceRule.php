<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports a regular expression that reads a namespace declaration out of text.
 *
 * A pattern such as `^\s*namespace\s+([^;]+);` meant for a PHP file's declaration matches
 * whatever text has the same shape: a docblock or `//` comment line that begins with the word, a
 * line inside a heredoc or a string, a braced `namespace X { }` the pattern did not anticipate. The
 * file is then attributed to a namespace it does not declare. The parser and the tokenizer already
 * tell a declaration from the same words in a comment or a string, so a file's namespace is read
 * from one of them.
 *
 * Recognised: preg_match, preg_match_all, preg_replace, preg_replace_callback, preg_split,
 * preg_grep and preg_filter, PHP's or Safe's, whose pattern PHPStan knows as a constant string in
 * which the word `namespace` is followed by whitespace (`\s`, `\h`, `[[:space:]]` or a space). A
 * pattern PHPStan cannot read, and a `class`, `use` or `interface` declaration, are not reported:
 * those words are ordinary in the text other patterns read.
 *
 * See: docs/phpstan-rules/forbid-regex-reading-namespace.md.
 *
 * @implements Rule<FuncCall>
 */
final readonly class ForbidRegexReadingNamespaceRule implements Rule
{
    /** The identifier every finding carries, resolved by rule-doc. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.regexReadsNamespace';

    /** The message every finding carries. */
    public const string MESSAGE = "This pattern reads a namespace declaration out of text. A regular expression cannot tell the declaration from a comment, a string or a braced block that mentions the word: read the file's namespace with nikic/php-parser's Namespace_ node or PhpToken::tokenize().";

    /** The regex functions that take a pattern as their first argument, lower-cased. */
    private const array FUNCTIONS = ['preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_split', 'preg_grep', 'preg_filter'];

    /** The pattern argument's name, for a named argument. */
    private const string PATTERN_ARGUMENT = 'pattern';

    /**
     * The word `namespace`, not the tail of a longer word (a `\b` before it is a word boundary,
     * not a letter), followed by a whitespace class or a space; PHP keywords are case-insensitive.
     */
    private const string DECLARATION_SHAPE = '/(?:(?<![A-Za-z0-9_])|(?<=\\\b))namespace(?:\\\[sh]| |\[\[:space:\]\])/i';

    /** The assertion that the match starts at the first byte of the text. */
    private const string START_ANCHOR = '\A';

    /** The vendor segment of the functions Safe re-exports, lower-cased. */
    private const string SAFE = 'safe';

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
        if (!$node->name instanceof Name || !$this->isRegexFunction($node->name) || $node->isFirstClassCallable()) {
            return [];
        }

        $pattern = $this->patternArgument($node);
        if (!$pattern instanceof Node\Expr) {
            return [];
        }

        foreach ($scope->getType($pattern)->getConstantStrings() as $constantString) {
            $value = $constantString->getValue();
            if (!$this->isAnchoredAtStart($value) && 1 === \Safe\preg_match(self::DECLARATION_SHAPE, $value)) {
                return [RuleErrorBuilder::message(self::MESSAGE)->identifier(self::IDENTIFIER)->build()];
            }
        }

        return [];
    }

    /**
     * A pattern that begins with `\A` describes the whole start of the text, so what comes before the
     * word is the pattern's own and no comment or string can stand in for it: it recognises a file of
     * a known shape, such as Composer's generated bin proxy, and does not search for a declaration.
     */
    private function isAnchoredAtStart(string $pattern): bool
    {
        return str_starts_with(substr($pattern, 1), self::START_ANCHOR);
    }

    /** PHP's own function, unqualified or global, or the one Safe re-exports. */
    private function isRegexFunction(Name $name): bool
    {
        if (!\in_array(strtolower($name->getLast()), self::FUNCTIONS, true)) {
            return false;
        }

        return 1 === \count($name->getParts()) || self::SAFE === strtolower($name->getFirst());
    }

    private function patternArgument(FuncCall $call): ?Node\Expr
    {
        foreach ($call->getArgs() as $position => $arg) {
            if (null === $arg->name ? 0 === $position : self::PATTERN_ARGUMENT === $arg->name->toString()) {
                return $arg->value;
            }
        }

        return null;
    }
}
