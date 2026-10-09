<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Lane\Infection\CommentOnlyChange;
use PHPUnit\Framework\Attributes\CoversClass;
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

    private const string ANNOTATED_DOCBLOCK = '/** @infection-ignore-all */';

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
        self::assertTrue($this->commentOnly(str_replace(['return $a + $b;', "<?php\n"], ["return\n            \$a+\$b ;", '<?php '], self::BASE)));
    }

    #[Test]
    public function anAddedStatementIsCode(): void
    {
        self::assertFalse($this->commentOnly(str_replace('return $a + $b;', "\$a++;\n        return \$a + \$b;", self::BASE)));
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
    public function addingAnInfectionAnnotationInADocblockIsCode(): void
    {
        self::assertFalse($this->commentOnly(str_replace(self::DOCBLOCK, '/** Adds. @infection-ignore-all */', self::BASE)));
    }

    #[Test]
    public function addingAnInfectionAnnotationInALineOrBlockCommentIsCode(): void
    {
        self::assertFalse($this->commentOnly(str_replace(self::LINE_COMMENT, '// @infection-ignore-all', self::BASE)));
        self::assertFalse($this->commentOnly(str_replace(self::LINE_COMMENT, '/* @infection-ignore-all */', self::BASE)));
    }

    #[Test]
    public function removingAnInfectionAnnotationIsCode(): void
    {
        self::assertFalse(new CommentOnlyChange()->isCommentOnly($this->annotatedClass(), self::BASE));
    }

    #[Test]
    public function movingAnInfectionAnnotationIsCode(): void
    {
        $onMethod = str_replace('#[\Deprecated]', self::ANNOTATED_DOCBLOCK . "\n    #[\\Deprecated]", self::BASE);

        self::assertFalse(new CommentOnlyChange()->isCommentOnly($this->annotatedClass(), $onMethod));
    }

    #[Test]
    public function editingTheProseAroundAKeptAnnotationIsCommentOnly(): void
    {
        $annotated = str_replace(self::DOCBLOCK, "/**\n * Adds.\n *\n * @infection-ignore-all\n */", self::BASE);
        $reworded  = str_replace(self::DOCBLOCK, "/**\n * Adds two integers.\n *\n * @api\n * @infection-ignore-all\n */", self::BASE);

        self::assertTrue(new CommentOnlyChange()->isCommentOnly($annotated, $reworded));
    }

    #[Test]
    public function identicalContentIsCommentOnly(): void
    {
        self::assertTrue($this->commentOnly(self::BASE));
    }

    private function annotatedClass(): string
    {
        return str_replace(self::DOCBLOCK, self::ANNOTATED_DOCBLOCK, self::BASE);
    }

    private function commentOnly(string $after): bool
    {
        return new CommentOnlyChange()->isCommentOnly(self::BASE, $after);
    }
}
