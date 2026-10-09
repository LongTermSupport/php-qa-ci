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

    #[Test]
    public function aDocblockOnlyEditIsCommentOnly(): void
    {
        self::assertTrue($this->commentOnly(str_replace('/** Adds. */', "/**\n * Adds two integers.\n *\n * @api\n */", self::BASE)));
    }

    #[Test]
    public function aLineCommentEditIsCommentOnly(): void
    {
        self::assertTrue($this->commentOnly(str_replace('// sum', '# the total', self::BASE)));
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
        self::assertFalse($this->commentOnly(str_replace(['#[\Deprecated]', '/** Adds. */'], ['#[\Override]', '/** Adds them. */'], self::BASE)));
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

    private function commentOnly(string $after): bool
    {
        return new CommentOnlyChange()->isCommentOnly(self::BASE, $after);
    }
}
