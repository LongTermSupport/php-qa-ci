<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

use ParseError;
use PhpToken;

/**
 * Whether two versions of a PHP file differ only in comments, docblocks and
 * whitespace: their token streams, with T_COMMENT, T_DOC_COMMENT and
 * T_WHITESPACE removed, are identical. Such a change produces no mutant a
 * diff run could judge, so it is left out of the mutation scope.
 *
 * Everything else is code: an attribute is real tokens, and whitespace inside
 * a string or heredoc is part of its token's text. The whitespace PHP folds
 * into an open or close tag is trimmed. Source that does not parse is never
 * comment-only, so a doubtful file is mutated rather than skipped.
 *
 * An annotation Infection or the coverage tool reads is code too, since it
 * decides what is mutated or what counts as covered; each one a comment
 * carries is kept, in place, as a token of its own, and the prose around it
 * is still ignored:
 *
 * - `@infection-ignore-all`, honoured by Infection in any comment attached
 *   to a node;
 * - `@deprecated`, which php-code-coverage treats as ignored code when
 *   `ignoreDeprecatedCodeUnits` is on (off by default; kept regardless, so
 *   a project that turns it on is never misjudged);
 * - `@codeCoverageIgnore`, `@codeCoverageIgnoreStart` and
 *   `@codeCoverageIgnoreEnd`. php-code-coverage reads these by line number
 *   as well as by node: the first ignores the line it is on, the pair the
 *   lines between them. Tokens alone cannot show which code shares a line,
 *   so when either version carries one, every token is compared with its
 *   line and any shift counts as a change.
 *
 * @internal
 */
final readonly class CommentOnlyChange
{
    private const array IGNORED = [\T_COMMENT, \T_DOC_COMMENT, \T_WHITESPACE];

    private const array COMMENTS = [\T_COMMENT, \T_DOC_COMMENT];

    private const array ANNOTATIONS = ['infection', 'codeCoverageIgnore', 'deprecated'];

    private const string NAME_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    private const string LINE_BASED = '@codeCoverageIgnore';

    public function isCommentOnly(string $before, string $after): bool
    {
        $base    = $this->tokenize($before);
        $current = $this->tokenize($after);
        if ($base instanceof ParseError || $current instanceof ParseError) {
            return false;
        }

        $byLine = $this->carriesLineBasedAnnotation(...$base) || $this->carriesLineBasedAnnotation(...$current);

        return $this->significant($byLine, ...$base) === $this->significant($byLine, ...$current);
    }

    /** @return list<PhpToken>|ParseError the tokens, or why the source could not be tokenised */
    private function tokenize(string $code): array|ParseError
    {
        try {
            return array_values(PhpToken::tokenize($code, \TOKEN_PARSE));
        } catch (ParseError $parseError) {
            return $parseError;
        }
    }

    private function carriesLineBasedAnnotation(PhpToken ...$tokens): bool
    {
        return array_any($tokens, static fn (PhpToken $token): bool => $token->is(self::COMMENTS) && str_contains($token->text, self::LINE_BASED));
    }

    /** @return list<string> the code tokens and the annotations, each with its line when `$byLine` */
    private function significant(bool $byLine, PhpToken ...$tokens): array
    {
        $significant = [];
        foreach ($tokens as $token) {
            $line = $byLine ? $token->line . '@' : '';
            if ($token->is(self::COMMENTS)) {
                foreach ($this->annotations($token->text) as $annotation) {
                    $significant[] = $line . 'annotation:' . $annotation;
                }
            }

            if ($token->is(self::IGNORED)) {
                continue;
            }

            $text          = $token->is([\T_OPEN_TAG, \T_CLOSE_TAG]) ? rtrim($token->text) : $token->text;
            $significant[] = $line . $token->id . ':' . $text;
        }

        return $significant;
    }

    /** @return list<string> each annotation the comment carries that Infection or php-code-coverage reads, in order */
    private function annotations(string $comment): array
    {
        $found = [];
        foreach (\array_slice(explode('@', $comment), 1) as $after) {
            $name = substr($after, 0, strspn($after, self::NAME_CHARACTERS));
            foreach (self::ANNOTATIONS as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $found[] = $name;
                }
            }
        }

        return $found;
    }
}
