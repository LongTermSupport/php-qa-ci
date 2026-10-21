<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ClassReferencePositionEnum;
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
    private const string PARAM = 'App\Tests\Param';

    private const string OTHER = 'App\Tests\Other';

    /**
     * Each class name is reported with the position it stands in, which decides whether
     * PHP asks the autoloader for it there.
     */
    #[Test]
    public function everyClassNameInCodeIsAReferenceAtItsPositionAndNothingElseIs(): void
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
                    new class extends Anonymous { public function made(): Made {} };
                    try {
                    } catch (Failure|\RuntimeException $e) {
                    }
                    Named::class;
                    $value === Compared::class;
                    static fn (Closured $c): Arrowed => $c;
                }
            }

            function free(Freed $f): void {}
            PHP_WRAP;

        $hostSignature = static fn (?int $parameter, string ...$typeNames): array => [
            'ancestors' => ['App\Tests\Base', 'App\Tests\Contract', 'App\Tests\Behaviour'],
            'method'    => 'run',
            'parameter' => $parameter,
            'typeNames' => array_values($typeNames),
        ];
        $parameterSignature = $hostSignature(0, self::PARAM, self::OTHER);

        self::assertEquals(
            [
                $this->reference('App\Tests\Marker', 8, ClassReferencePositionEnum::Attribute),
                $this->reference('App\Tests\Base', 9, ClassReferencePositionEnum::Loading),
                $this->reference('App\Tests\Contract', 9, ClassReferencePositionEnum::Loading),
                $this->reference('App\Tests\Behaviour', 11, ClassReferencePositionEnum::Loading),
                $this->reference('App\Tests\Typed', 13, ClassReferencePositionEnum::Inert),
                $this->reference(self::PARAM, 15, ClassReferencePositionEnum::MethodSignature, $parameterSignature),
                $this->reference(self::OTHER, 15, ClassReferencePositionEnum::MethodSignature, $parameterSignature),
                $this->reference('App\Tests\Returned', 15, ClassReferencePositionEnum::MethodSignature, $hostSignature(null, 'App\Tests\Returned')),
                $this->reference('App\Support\Imported', 17, ClassReferencePositionEnum::Loading),
                $this->reference('App\Tests\Factory', 18, ClassReferencePositionEnum::Loading),
                $this->reference('App\Tests\Holder', 19, ClassReferencePositionEnum::Loading),
                $this->reference('App\Tests\Constants', 20, ClassReferencePositionEnum::Loading),
                $this->reference('App\Tests\Checked', 21, ClassReferencePositionEnum::Inert),
                $this->reference('App\Tests\Anonymous', 29, ClassReferencePositionEnum::Loading),
                $this->reference('App\Tests\Made', 29, ClassReferencePositionEnum::MethodSignature, [
                    'ancestors' => ['App\Tests\Anonymous'],
                    'method'    => 'made',
                    'parameter' => null,
                    'typeNames' => ['App\Tests\Made'],
                ]),
                $this->reference('App\Tests\Failure', 31, ClassReferencePositionEnum::Inert),
                $this->reference('RuntimeException', 31, ClassReferencePositionEnum::Inert),
                $this->reference('App\Tests\Named', 33, ClassReferencePositionEnum::ClassName),
                $this->reference('App\Tests\Compared', 34, ClassReferencePositionEnum::Inert),
                $this->reference('App\Tests\Closured', 35, ClassReferencePositionEnum::Inert),
                $this->reference('App\Tests\Arrowed', 35, ClassReferencePositionEnum::Inert),
                $this->reference('App\Tests\Freed', 39, ClassReferencePositionEnum::Inert),
            ],
            new ClassReferenceScanner()->classReferences(...$this->parse($code)),
        );
    }

    #[Test]
    public function anUnresolvedNameIsNotAReference(): void
    {
        // Without a name resolver a plain Name carries no resolved form to report.
        $nodes = new ParserFactory()->createForHostVersion()->parse('<?php new Foo(); new \Bar();') ?? [];

        self::assertEquals(
            [$this->reference('Bar', 1, ClassReferencePositionEnum::Loading)],
            new ClassReferenceScanner()->classReferences(...$nodes),
        );
    }

    #[Test]
    public function everyNamedClassLikeTheCodeDeclaresIsListedAtItsLine(): void
    {
        $code = <<<'PHP_WRAP'
            <?php
            namespace App\Tests;

            final class Declared {}
            interface Contracted {}
            trait Shared {}
            enum Listed {}
            new class {};
            PHP_WRAP;

        self::assertSame(
            [
                ['name' => 'App\Tests\Declared', 'line' => 4],
                ['name' => 'App\Tests\Contracted', 'line' => 5],
                ['name' => 'App\Tests\Shared', 'line' => 6],
                ['name' => 'App\Tests\Listed', 'line' => 7],
            ],
            new ClassReferenceScanner()->declaredClasses(...$this->parse($code)),
        );
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
     * @param array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null $signature
     *
     * @return array{name: string, line: int, position: ClassReferencePositionEnum, signature: array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null}
     */
    private function reference(string $name, int $line, ClassReferencePositionEnum $position, ?array $signature = null): array
    {
        return ['name' => $name, 'line' => $line, 'position' => $position, 'signature' => $signature];
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
