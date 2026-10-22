<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PhpFile;

use LTS\PHPQA\PhpFile\DeclaredNamespaceReader;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DeclaredNamespaceReader::class)]
#[Small]
final class DeclaredNamespaceReaderTest extends TestCase
{
    private const string OPEN = "<?php\n\ndeclare(strict_types=1);\n\n";

    private const string APP = 'App';

    private const string DOMAIN = 'App\Domain';

    #[Test]
    #[DataProvider('declaredNamespaces')]
    public function aSourceNamesTheNamespaceItDeclares(string $source, string $expected): void
    {
        self::assertSame($expected, new DeclaredNamespaceReader()->fromSource($source));
    }

    /** @return iterable<string, array{string, string}> source, then the namespace it declares */
    public static function declaredNamespaces(): iterable
    {
        yield 'a plain declaration' => [self::OPEN . "namespace App\\Domain;\n\nfinal class A\n{\n}\n", self::DOMAIN];
        yield 'a braced declaration' => [self::OPEN . "namespace App\\Domain {\n    final class A\n    {\n    }\n}\n", self::DOMAIN];
        yield 'no declaration' => [self::OPEN . "final class A\n{\n}\n", ''];
        yield 'a braced global namespace' => [self::OPEN . "namespace {\n    final class A\n    {\n    }\n}\n", ''];
        yield 'a block comment line before the real one' => [self::OPEN . "/*\nnamespace Other;\n*/\nnamespace App;\n", self::APP];
        yield 'a line comment and a docblock before the real one' => [self::OPEN . "// namespace Other;\n/**\n * namespace Another;\n */\nnamespace App;\n", self::APP];
        yield 'a heredoc line in a file with no declaration' => [self::OPEN . "\$text = <<<'PHP'\nnamespace Other;\nPHP;\n", ''];
        yield 'a string that holds a declaration' => [self::OPEN . "namespace App;\n\nconst TEXT = 'namespace Other;';\n", self::APP];
        yield 'several declarations: the first' => [self::OPEN . "namespace One {\n}\nnamespace Two {\n}\n", 'One'];
        yield 'a keyword written in capitals' => [self::OPEN . "NAMESPACE App;\n", self::APP];
        yield 'a name that starts with the word' => [self::OPEN . "namespace namespaces\\Inner;\n", 'namespaces\Inner'];
    }

    #[Test]
    public function sourceThatCannotBeParsedHasNoKnownNamespace(): void
    {
        self::assertNull(new DeclaredNamespaceReader()->fromSource(self::OPEN . "namespace App;\n\nfinal class A {\n"));
    }

    #[Test]
    public function aFileIsReadByItsPath(): void
    {
        $project = TempDir::create('phpqa-namespace-reader');

        try {
            $path = $project->write('src/A.php', self::OPEN . "namespace App\\Domain;\n");

            self::assertSame(self::DOMAIN, new DeclaredNamespaceReader()->fromFile($path));
        } finally {
            $project->remove();
        }
    }
}
