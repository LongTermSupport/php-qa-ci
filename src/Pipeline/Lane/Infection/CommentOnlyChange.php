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
 * @internal
 */
final readonly class CommentOnlyChange
{
    private const array IGNORED = [\T_COMMENT, \T_DOC_COMMENT, \T_WHITESPACE];

    public function isCommentOnly(string $before, string $after): bool
    {
        $base    = $this->significantTokens($before);
        $current = $this->significantTokens($after);
        if ($base instanceof ParseError || $current instanceof ParseError) {
            return false;
        }

        return $base === $current;
    }

    /** @return list<string>|ParseError the significant tokens, or why the source could not be tokenised */
    private function significantTokens(string $code): array|ParseError
    {
        try {
            $tokens = PhpToken::tokenize($code, \TOKEN_PARSE);
        } catch (ParseError $parseError) {
            return $parseError;
        }

        $significant = [];
        foreach ($tokens as $token) {
            if ($token->is(self::IGNORED)) {
                continue;
            }

            $text          = $token->is([\T_OPEN_TAG, \T_CLOSE_TAG]) ? rtrim($token->text) : $token->text;
            $significant[] = $token->id . ':' . $text;
        }

        return $significant;
    }
}
