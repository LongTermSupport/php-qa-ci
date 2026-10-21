<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpstan\Dto;

use LTS\PHPQA\Pipeline\Lane\Phpstan\Dto\PhpstanParametersDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PhpstanParametersDto::class)]
#[Small]
final class PhpstanParametersDtoTest extends TestCase
{
    private const string SYS_TEMP = '/t';

    private const string CI_CACHE = '/ci-cache/phpstan';

    private const string SRC = '/p/src';

    private const string PHP = 'php';

    #[Test]
    public function itReadsWhatDumpParametersPrints(): void
    {
        $parameters = PhpstanParametersDto::fromDump(\Safe\json_encode([
            'tmpDir'         => self::CI_CACHE,
            'sysGetTempDir'  => self::SYS_TEMP,
            'paths'          => [self::SRC, '/p/bin/console'],
            'excludePaths'   => ['analyseAndScan' => ['/p/src/legacy'], 'analyse' => ['/p/tests/*']],
            'fileExtensions' => [self::PHP, 'inc'],
        ]));

        self::assertNotNull($parameters);
        self::assertSame(self::CI_CACHE, $parameters->tmpDir);
        self::assertSame([self::SRC, '/p/bin/console'], $parameters->paths);
        self::assertSame(['/p/src/legacy', '/p/tests/*'], $parameters->excludePaths);
        self::assertSame([self::PHP, 'inc'], $parameters->fileExtensions);
    }

    /** PHPStan's own default tmpDir is not the project's choice. */
    #[Test]
    public function phpstansDefaultTmpDirIsNoProjectTmpDir(): void
    {
        $parameters = PhpstanParametersDto::fromDump(\Safe\json_encode(['tmpDir' => self::SYS_TEMP . '/phpstan', 'sysGetTempDir' => self::SYS_TEMP]));

        self::assertNotNull($parameters);
        self::assertNull($parameters->tmpDir);
    }

    /** With no `sysGetTempDir` there is nothing to tell the default from, so no tmpDir is the project's. */
    #[Test]
    public function aTmpDirWithNoSystemTempDirectoryIsNoProjectTmpDir(): void
    {
        $parameters = PhpstanParametersDto::fromDump(\Safe\json_encode(['tmpDir' => self::CI_CACHE]));

        self::assertNotNull($parameters);
        self::assertNull($parameters->tmpDir);
    }

    /** A key the dump lacks is PHPStan's default: no paths, no exclusions, `.php` files. */
    #[Test]
    public function aMissingKeyIsPhpstansDefault(): void
    {
        $parameters = PhpstanParametersDto::fromDump('{}');

        self::assertEquals(new PhpstanParametersDto(null, [], [], [self::PHP]), $parameters);
        self::assertEquals($parameters, PhpstanParametersDto::defaults());
    }

    /** An `excludePaths` given as a plain list is PHPStan's older form, every entry excluded from analysis. */
    #[Test]
    public function aPlainListOfExcludedPathsIsRead(): void
    {
        $parameters = PhpstanParametersDto::fromDump(\Safe\json_encode(['excludePaths' => ['/p/a', 3, '/p/b']]));

        self::assertNotNull($parameters);
        self::assertSame(['/p/a', '/p/b'], $parameters->excludePaths);
    }

    #[Test]
    public function entriesThatAreNotStringsAreDropped(): void
    {
        $parameters = PhpstanParametersDto::fromDump(\Safe\json_encode([
            'paths'          => [self::SRC, null, ['x']],
            'excludePaths'   => ['analyse' => ['/p/x', false], 'analyseAndScan' => 'not a list'],
            'fileExtensions' => [self::PHP, 7],
        ]));

        self::assertNotNull($parameters);
        self::assertSame([self::SRC], $parameters->paths);
        self::assertSame(['/p/x'], $parameters->excludePaths);
        self::assertSame([self::PHP], $parameters->fileExtensions);
    }

    /** An empty `fileExtensions` would analyse nothing; PHPStan's default `php` stands. */
    #[Test]
    public function noFileExtensionIsPhpstansDefault(): void
    {
        $parameters = PhpstanParametersDto::fromDump(\Safe\json_encode(['fileExtensions' => []]));

        self::assertNotNull($parameters);
        self::assertSame([self::PHP], $parameters->fileExtensions);
    }

    #[Test]
    #[DataProvider('noAnswers')]
    public function outputThatIsNoParameterObjectIsNoAnswer(string $output): void
    {
        self::assertNull(PhpstanParametersDto::fromDump($output));
    }

    /** @return iterable<string, array{string}> */
    public static function noAnswers(): iterable
    {
        yield 'not JSON' => ['Invalid configuration'];
        yield 'a JSON string' => ['"x"'];
        yield 'nothing' => [''];
    }
}
