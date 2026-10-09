<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Lane\Infection\CommentOnlyChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CommentOnlyChange::class)]
#[Small]
final class CommentOnlyChangeTest extends TestCase
{
    private const string BASE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        /** Adds. */
        final class Adder
        {
            #[\Deprecated]
            public function add(int $a, int $b): int
            {
                return $a + $b; // sum
            }
        }

        PHP;

    private const string DOCBLOCK = '/** Adds. */';

    private const string LINE_COMMENT = '// sum';

    private const string RETURN = "        return \$a + \$b;\n";

    private const string IGNORE_LINE = '@codeCoverageIgnore';

    private const string STATEMENT = 'return $a + $b;';

    private const string SLASHED_IGNORE = '// @codeCoverageIgnore';

    #[Test]
    public function aDocblockOnlyEditIsCommentOnly(): void
    {
        self::assertTrue($this->commentOnly(str_replace(self::DOCBLOCK, "/**\n * Adds two integers.\n *\n * @api\n */", self::BASE)));
    }

    #[Test]
    public function aLineCommentEditIsCommentOnly(): void
    {
        self::assertTrue($this->commentOnly(str_replace(self::LINE_COMMENT, '# the total', self::BASE)));
    }

    #[Test]
    public function aWhitespaceOnlyReformatIsCommentOnly(): void
    {
        self::assertTrue($this->commentOnly(str_replace([self::STATEMENT, "<?php\n"], ["return\n            \$a+\$b ;", '<?php '], self::BASE)));
    }

    #[Test]
    public function anAddedStatementIsCode(): void
    {
        self::assertFalse($this->commentOnly(str_replace(self::STATEMENT, "\$a++;\n        return \$a + \$b;", self::BASE)));
    }

    #[Test]
    public function anAttributeChangeIsCodeEvenBesideACommentChange(): void
    {
        self::assertFalse($this->commentOnly(str_replace(['#[\Deprecated]', self::DOCBLOCK], ['#[\Override]', '/** Adds them. */'], self::BASE)));
    }

    #[Test]
    public function stringContentsAreCodeWhitespaceIncluded(): void
    {
        $base = "<?php\n\$a = 'x y';\n";

        self::assertFalse(new CommentOnlyChange()->isCommentOnly($base, "<?php\n\$a = 'x  y';\n"));
        self::assertFalse(new CommentOnlyChange()->isCommentOnly("<?php\n\$a = <<<T\n x\nT;\n", "<?php\n\$a = <<<T\n  x\nT;\n"));
    }

    #[Test]
    public function codeThatDoesNotParseIsNeverCommentOnly(): void
    {
        self::assertFalse(new CommentOnlyChange()->isCommentOnly(self::BASE, self::BASE . '}'));
        self::assertFalse(new CommentOnlyChange()->isCommentOnly('<?php function (', '<?php function ( /* x */'));
    }

    #[Test]
    public function identicalContentIsCommentOnly(): void
    {
        self::assertTrue($this->commentOnly(self::BASE));
    }

    #[Test]
    #[DataProvider('directives')]
    public function addingOrRemovingADirectiveInAnyCommentFormIsCode(string $directive): void
    {
        foreach ([\sprintf('/** %s */', $directive), \sprintf('/* %s */', $directive), '// ' . $directive, '# ' . $directive] as $comment) {
            $annotated = str_replace(self::LINE_COMMENT, $comment, self::BASE);

            self::assertFalse($this->commentOnly($annotated), 'added: ' . $comment);
            self::assertFalse(new CommentOnlyChange()->isCommentOnly($annotated, self::BASE), 'removed: ' . $comment);
        }
    }

    #[Test]
    #[DataProvider('directives')]
    public function anyCommentEditInAFileCarryingADirectiveIsCode(string $directive): void
    {
        $annotated = str_replace(self::DOCBLOCK, \sprintf('/** Adds. %s */', $directive), self::BASE);

        self::assertFalse(new CommentOnlyChange()->isCommentOnly($annotated, str_replace(self::LINE_COMMENT, '// the total', $annotated)), 'an unrelated comment');
        self::assertFalse(new CommentOnlyChange()->isCommentOnly($annotated, str_replace('Adds.', 'Sums.', $annotated)), 'the prose beside the directive');
        self::assertFalse(new CommentOnlyChange()->isCommentOnly($annotated, str_replace(self::STATEMENT, "return \$a\n            + \$b;", $annotated)), 'a reformat that moves code to another line');
        self::assertTrue(new CommentOnlyChange()->isCommentOnly($annotated, $annotated . "\n"), 'trailing whitespace moves no token');
    }

    /** @return iterable<string, array{string}> */
    public static function directives(): iterable
    {
        yield 'infection' => ['@infection-ignore-all'];
        yield 'coverage ignore' => [self::IGNORE_LINE];
        yield 'coverage ignore start' => ['@codeCoverageIgnoreStart'];
        yield 'coverage ignore end' => ['@codeCoverageIgnoreEnd'];
        yield 'deprecated' => ['@deprecated'];
    }

    #[Test]
    #[DataProvider('commentFormChanges')]
    public function changingTheFormOrPlaceOfADirectiveCommentIsCode(string $before, string $after): void
    {
        self::assertFalse(new CommentOnlyChange()->isCommentOnly($before, $after));
    }

    /** @return iterable<string, array{string, string}> the probe cases where the comment form, not only the directive, decides what php-code-coverage ignores */
    public static function commentFormChanges(): iterable
    {
        $method = static fn (string $head, string $body): string => "<?php\n\ndeclare(strict_types=1);\n\nfinal class Summer\n{\n" . $head . "    public function sum(int \$a, int \$b): int\n    {\n" . $body . "    }\n}\n";
        $line   = static fn (string $comment): string => $method('', '        return $a + $b; ' . $comment . "\n");

        yield 'exact line annotation gains a reason' => [$line(self::SLASHED_IGNORE), $line(self::SLASHED_IGNORE . ' unreachable')];
        yield 'line annotation loses its reason' => [$line(self::SLASHED_IGNORE . ' unreachable'), $line(self::SLASHED_IGNORE)];
        yield 'hash to slashes' => [$line('# @codeCoverageIgnore'), $line(self::SLASHED_IGNORE)];
        yield 'block to slashes' => [$line('/* @codeCoverageIgnore */'), $line(self::SLASHED_IGNORE)];
        yield 'start/end slashes to hash' => [
            $method('', "        // @codeCoverageIgnoreStart\n" . self::RETURN . "        // @codeCoverageIgnoreEnd\n"),
            $method('', "        # @codeCoverageIgnoreStart\n" . self::RETURN . "        # @codeCoverageIgnoreEnd\n"),
        ];
        yield 'method docblock to block comment' => [$method("    /** @codeCoverageIgnore */\n", self::RETURN), $method("    /* @codeCoverageIgnore */\n", self::RETURN)];
        yield 'method docblock to line comment' => [$method("    /** @codeCoverageIgnore */\n", self::RETURN), $method("    // @codeCoverageIgnore\n", self::RETURN)];
        yield 'two docblocks on one line swapped' => [$method("    /** @codeCoverageIgnore */ /** Adds. */\n", self::RETURN), $method("    /** Adds. */ /** @codeCoverageIgnore */\n", self::RETURN)];
        yield 'deprecated docblock to block comment' => [$method("    /** @deprecated */\n", self::RETURN), $method("    /* @deprecated */\n", self::RETURN)];
        yield 'deprecated docblocks swapped' => [$method("    /** @deprecated */\n    /** Adds. */\n", self::RETURN), $method("    /** Adds. */\n    /** @deprecated */\n", self::RETURN)];
    }

    #[Test]
    public function aDirectiveInsideAStringDoesNotMarkTheFile(): void
    {
        $code = str_replace(self::STATEMENT, "return \$a + \$b + strlen('" . self::IGNORE_LINE . "');", self::BASE);

        self::assertTrue(new CommentOnlyChange()->isCommentOnly($code, str_replace(self::DOCBLOCK, "/**\n * Adds.\n */", $code)));
    }

    private function commentOnly(string $after): bool
    {
        return new CommentOnlyChange()->isCommentOnly(self::BASE, $after);
    }
}
