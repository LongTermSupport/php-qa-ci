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
    public function aNameIsAPathWhenItStatesAnAbsoluteOrRootLocation(string $name, bool $isPath): void
    {
        self::assertSame($isPath, new AbsolutePathExpression()->isPathName($name));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'an absolute path root'            => ['absPathRoot', true];
        yield 'a qualified root'                 => ['projectRoot', true];
        yield 'a snake-case directory'           => ['project_root_dir', true];
        yield 'a capitalised directory'          => ['CACHE_DIR', true];
        yield 'a dir name'                       => ['dirName', true];
        yield 'a dirname'                        => ['dirname', true];
        yield 'a working directory'              => ['workingDirectory', true];
        yield 'a work dir'                       => ['workDir', true];
        yield 'a cwd'                            => ['cwd', true];
        yield 'a folder'                         => ['imageFolder', true];
        yield 'a realpath'                       => ['realpath', true];
        yield 'an abs path'                      => ['absPath', true];
        yield 'an absolute path'                 => ['absolutePath', true];
        yield 'a real path'                      => ['realPath', true];
        yield 'a full path'                      => ['fullPath', true];
        yield 'an absolute file path'            => ['absoluteFilePath', true];
        yield 'a full path name'                 => ['fullPathName', true];
        yield 'an abs pathname'                  => ['absPathname', true];
        yield 'an abs filepath'                  => ['abs_filepath', true];
        yield 'a bare path, often a URL'         => ['path', false];
        yield 'a request path'                   => ['requestPath', false];
        yield 'a file path, often relative'      => ['filePath', false];
        yield 'a path name'                      => ['pathName', false];
        yield 'a bare file'                      => ['file', false];
        yield 'a file name, a basename'          => ['fileName', false];
        yield 'a filename'                       => ['filename', false];
        yield 'a qualified file name'            => ['configFileName', false];
        yield 'a template file'                  => ['templateFile', false];
        yield 'a relative path'                  => ['relativePath', false];
        yield 'a relative directory'             => ['relativeDir', false];
        yield 'a relative root'                  => ['relativeRoot', false];
        yield 'a relative absolute path is text' => ['relativeAbsPath', false];
        yield 'an acronym and a path'            => ['URLPath', false];
        yield 'a bare root, often text'          => ['root', false];
        yield 'a root namespace'                 => ['rootNamespace', false];
        yield 'file contents'                    => ['fileContents', false];
        yield 'path parts'                       => ['pathParts', false];
        yield 'output'                           => ['output', false];
        yield 'a name with no words'             => ['_', false];
    }
}
