<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\AbsolutePathExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The expression shapes are driven through ForbidPathWordSubstringCheckRuleTest's fixtures; this
 * pins the name reading, which decides most of the rule's precision.
 *
 * @internal
 */
#[CoversClass(AbsolutePathExpression::class)]
#[Small]
final class AbsolutePathExpressionTest extends TestCase
{
    #[Test]
    #[DataProvider('names')]
    public function aNameIsAPathByItsLastWord(string $name, bool $isPath): void
    {
        self::assertSame($isPath, new AbsolutePathExpression()->isPathName($name));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'a path'                    => ['absPathRoot', true];
        yield 'a qualified root'          => ['projectRoot', true];
        yield 'a snake-case path'         => ['project_root_dir', true];
        yield 'a file path'               => ['filePath', true];
        yield 'a file'                    => ['file', true];
        yield 'a file name'               => ['fileName', true];
        yield 'a qualified file name'     => ['configFileName', true];
        yield 'a capitalised path'        => ['CACHE_DIR', true];
        yield 'an acronym and a path'     => ['URLPath', false];
        yield 'a working directory'       => ['workingDirectory', true];
        yield 'a bare root, often text'   => ['root', false];
        yield 'a root namespace'          => ['rootNamespace', false];
        yield 'file contents'             => ['fileContents', false];
        yield 'path parts'                => ['pathParts', false];
        yield 'output'                    => ['output', false];
        yield 'a name with no words'      => ['_', false];
    }
}
