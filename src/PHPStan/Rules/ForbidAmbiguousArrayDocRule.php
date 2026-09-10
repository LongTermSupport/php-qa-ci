<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PHPStan\Analyser\Scope;
use PHPStan\Node\VirtualNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A docblock array type that says nothing about its keys. `T[]` and `array<T>`
 * both resolve to `array<mixed, mixed>` in PHPStan: the single argument
 * constrains the values and leaves the keys unstated, so a reader cannot tell
 * a list from a map, and neither can the variadic rule, which deliberately
 * ignores both forms for that reason. `list<T>` states a list;
 * `array<K, V>` states a map.
 *
 * Every `@param`, `@return` and `@var` tag (including the `@phpstan-` and
 * `@psalm-` prefixed forms) on any statement is checked, so properties,
 * constants, signatures and inline `@var` annotations are all covered. One
 * report per tag, on the tag's own line, however many ambiguous fragments the
 * type contains.
 *
 * WRONG:
 *
 *   /** @param string[] $names * /
 *   /** @param list<string> $names * /
 *
 *   /** @return array<array<string>> * /
 *
 * RIGHT:
 *   /** @return list<array<string, string>> * /
 *
 * See: docs/phpstan-rules/forbid-ambiguous-array-doc.md
 *
 * @implements Rule<Stmt>
 */
final readonly class ForbidAmbiguousArrayDocRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.ambiguousArrayDoc';

    private const string TAG_PATTERN = '/@(?:phpstan-|psalm-)?(param|return|var)\s+/';

    private const string SINGLE_ARGUMENT_ARRAY = '/\b(?:non-empty-)?array<((?:[^<>,]|<[^<>]*>)*)>/';

    public function getNodeType(): string
    {
        return Stmt::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        /*
         * PHPStan visits a class method twice: the parser's node and its own
         * wrapper carrying the same docblock. A class-level docblock has no
         * @param/@return/@var of its own to check (this rule's own WRONG
         * example would be the first hit).
         */
        if ($node instanceof VirtualNode || $node instanceof ClassLike) {
            return [];
        }

        $doc = $node->getDocComment();
        if (!$doc instanceof Doc) {
            return [];
        }

        $text = $doc->getText();
        \Safe\preg_match_all(self::TAG_PATTERN, $text, $tags, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER);
        /** @var list<array{0: array{0: string, 1: int}, 1: array{0: string, 1: int}}> $tags */
        $errors = [];
        foreach ($tags as $tag) {
            [$whole, $offset] = $tag[0];
            $typeStart        = $offset + \strlen($whole);
            $type             = $this->typeToken($text, $typeStart);
            if ('' === $type || !$this->isAmbiguous($type)) {
                continue;
            }

            $subject = '@' . $tag[1][0];
            $var     = $this->variableAfter($text, $typeStart + \strlen($type));
            if (null !== $var) {
                $subject .= ' ' . $var;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                '%s type "%s" leaves the keys unstated: write list<T> for a list or array<K, V> for a map.',
                $subject,
                $type,
            ))
                ->identifier(self::IDENTIFIER)
                ->line($doc->getStartLine() + substr_count(substr($text, 0, $offset), "\n"))
                ->build()
            ;
        }

        return $errors;
    }

    /**
     * The type token starting at $start: everything up to the first whitespace
     * that is not inside angle brackets, braces or parentheses, so
     * `array<string, int>` and `array{a: int, b: string}` are read whole.
     */
    private function typeToken(string $text, int $start): string
    {
        $depth  = 0;
        $length = \strlen($text);
        $end    = $length;
        for ($i = $start; $i < $length; ++$i) {
            $char = $text[$i];
            if ('<' === $char || '{' === $char || '(' === $char) {
                ++$depth;
            } elseif ('>' === $char || '}' === $char || ')' === $char) {
                --$depth;
            } elseif (0 === $depth && (' ' === $char || "\n" === $char || "\t" === $char || '*' === $char)) {
                $end = $i;

                break;
            }
        }

        return substr($text, $start, $end - $start);
    }

    /** The `$name` that follows the type on a @param or @var tag, when there is one. */
    private function variableAfter(string $text, int $offset): ?string
    {
        if (1 !== \Safe\preg_match('/\G[ \t]+(\$\w+)/', $text, $match, 0, $offset)) {
            return null;
        }

        /** @var array{0: string, 1: string} $match a 1 return guarantees the capture group */
        return $match[1];
    }

    /**
     * `T[]` anywhere, or `array<T>` / `non-empty-array<T>` with one argument.
     * An argument holding a top-level comma is a key/value pair and is fine.
     */
    private function isAmbiguous(string $type): bool
    {
        if (str_contains($type, '[]')) {
            return true;
        }

        \Safe\preg_match_all(self::SINGLE_ARGUMENT_ARRAY, $type, $matches);
        /** @var list<string> $arguments */
        $arguments = $matches[1] ?? [];
        foreach ($arguments as $argument) {
            if ('' !== trim($argument)) {
                return true;
            }
        }

        return false;
    }
}
