<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ClassReferenceScanner;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ClassReferenceScanner}, over code parsed and name-resolved the
 * way PHPStan hands it to a rule.
 *
 * @internal
 */
#[CoversClass(ClassReferenceScanner::class)]
#[Small]
final class ClassReferenceScannerTest extends TestCase
{
    #[Test]
    public function everyClassNameInCodeIsAReferenceAndNothingElseIs(): void
    {
        $code = <<<'PHP_WRAP'
            <?php
            namespace App\Tests;

            use App\Support\Imported;
            use function App\Support\helper;
            use const App\Support\LIMIT;

            #[Marker]
            final class Host extends Base implements Contract
            {
                use Behaviour;

                private ?Typed $typed = null;

                public function run(Param|Other $value): Returned
                {
                    new Imported();
                    Factory::make();
                    Holder::$shared;
                    Constants::NAME;
                    $value instanceof Checked;
                    helper();
                    \strlen(LIMIT);
                    \PHP_EOL;
                    true;
                    new self();
                    static::class;
                    parent::run();
                    new class extends Anonymous {};
                    try {
                    } catch (Failure|\RuntimeException $e) {
                    }
                }
            }
            PHP_WRAP;

        self::assertSame(
            [
                ['name' => 'App\Tests\Marker', 'line' => 8],
                ['name' => 'App\Tests\Base', 'line' => 9],
                ['name' => 'App\Tests\Contract', 'line' => 9],
                ['name' => 'App\Tests\Behaviour', 'line' => 11],
                ['name' => 'App\Tests\Typed', 'line' => 13],
                ['name' => 'App\Tests\Param', 'line' => 15],
                ['name' => 'App\Tests\Other', 'line' => 15],
                ['name' => 'App\Tests\Returned', 'line' => 15],
                ['name' => 'App\Support\Imported', 'line' => 17],
                ['name' => 'App\Tests\Factory', 'line' => 18],
                ['name' => 'App\Tests\Holder', 'line' => 19],
                ['name' => 'App\Tests\Constants', 'line' => 20],
                ['name' => 'App\Tests\Checked', 'line' => 21],
                ['name' => 'App\Tests\Anonymous', 'line' => 29],
                ['name' => 'App\Tests\Failure', 'line' => 31],
                ['name' => 'RuntimeException', 'line' => 31],
            ],
            new ClassReferenceScanner()->classReferences(...$this->parse($code)),
        );
    }

    #[Test]
    public function anUnresolvedNameIsNotAReference(): void
    {
        // Without a name resolver a plain Name carries no resolved form to report.
        $nodes = new ParserFactory()->createForHostVersion()->parse('<?php new Foo(); new \Bar();') ?? [];

        self::assertSame([['name' => 'Bar', 'line' => 1]], new ClassReferenceScanner()->classReferences(...$nodes));
    }

    #[Test]
    public function anIncludeIsResolvedWhereItsPathIsStatic(): void
    {
        $file = __FILE__;
        $dir  = __DIR__;
        $code = <<<PHP
            <?php
            require_once __DIR__ . '/AutoloadPathDetectorTest.php';
            include \\dirname(__FILE__) . '/ScopeStubTrait.php';
            require '{$dir}/ScopeStubTraitTest.php';
            require 'relative.php';
            require __DIR__ . '/Missing.php';
            require \$dynamic;
            require dirname(__FILE__, 2) . '/x.php';
            require \\strtolower(__FILE__);
            PHP;

        self::assertSame(
            [$dir . '/AutoloadPathDetectorTest.php', $dir . '/ScopeStubTrait.php', $dir . '/ScopeStubTraitTest.php'],
            new ClassReferenceScanner()->includedFiles($file, ...$this->parse($code)),
        );
    }

    /**
     * @return list<Node>
     */
    private function parse(string $code): array
    {
        $nodes     = new ParserFactory()->createForHostVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser(new NameResolver());

        return array_values($traverser->traverse($nodes));
    }
}
