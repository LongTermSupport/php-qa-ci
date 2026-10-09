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
 * Some comments are read by the tools: Infection skips a node whose comment
 * holds `@infection-ignore-all`, and php-code-coverage leaves code it marks
 * with `@codeCoverageIgnore` (`Start`/`End`), or `@deprecated` when
 * `ignoreDeprecatedCodeUnits` is on, out of coverage. Which code that is
 * depends on the comment's form, text and line. So when either version has a
 * comment containing one of those directives, every comment counts: all
 * non-whitespace tokens, comments included, are compared verbatim with their
 * lines, and any comment change at all makes the file changed.
 *
 * @internal
 */
final readonly class CommentOnlyChange
{
    private const array IGNORED = [\T_COMMENT, \T_DOC_COMMENT, \T_WHITESPACE];

    private const array COMMENTS = [\T_COMMENT, \T_DOC_COMMENT];

    private const array DIRECTIVES = ['@infection', '@codeCoverageIgnore', '@deprecated'];

    public function isCommentOnly(string $before, string $after): bool
    {
        $base    = $this->tokenize($before);
        $current = $this->tokenize($after);
        if ($base instanceof ParseError || $current instanceof ParseError) {
            return false;
        }

        if ($this->carriesDirective(...$base) || $this->carriesDirective(...$current)) {
            return $this->everyToken(...$base) === $this->everyToken(...$current);
        }

        return $this->codeTokens(...$base) === $this->codeTokens(...$current);
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

    private function carriesDirective(PhpToken ...$tokens): bool
    {
        foreach ($tokens as $token) {
            if (!$token->is(self::COMMENTS)) {
                continue;
            }

            foreach (self::DIRECTIVES as $directive) {
                if (str_contains($token->text, $directive)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> the code tokens, comments and whitespace left out */
    private function codeTokens(PhpToken ...$tokens): array
    {
        $code = [];
        foreach ($tokens as $token) {
            if (!$token->is(self::IGNORED)) {
                $code[] = $token->id . ':' . $this->text($token);
            }
        }

        return $code;
    }

    /** @return list<string> every token but whitespace, comments included, each with its line */
    private function everyToken(PhpToken ...$tokens): array
    {
        $all = [];
        foreach ($tokens as $token) {
            if (!$token->is(\T_WHITESPACE)) {
                $all[] = $token->line . ':' . $token->id . ':' . $this->text($token);
            }
        }

        return $all;
    }

    private function text(PhpToken $token): string
    {
        return $token->is([\T_OPEN_TAG, \T_CLOSE_TAG]) ? rtrim($token->text) : $token->text;
    }
}
