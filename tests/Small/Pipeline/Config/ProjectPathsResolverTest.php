<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Config;

use LTS\PHPQA\Pipeline\Config\Exception\ProjectLayoutException;
use LTS\PHPQA\Pipeline\Config\ProjectPathsResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProjectPathsResolver::class)]
#[CoversClass(ProjectLayoutException::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[Small]
final class ProjectPathsResolverTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/pipeline';

    private const string LIBRARY = __DIR__ . '/../../../..';

    #[Test]
    public function itResolvesEveryDirectoryFromTheProjectAndLibraryRoots(): void
    {
        $paths = new ProjectPathsResolver()->resolve(self::FIXTURE . '/project/', self::LIBRARY . '/');

        self::assertSame(self::FIXTURE . '/project', $paths->projectRoot);
        self::assertSame(self::LIBRARY, $paths->libraryRoot);
        self::assertSame(self::FIXTURE . '/project/vendor/bin', $paths->binDir);
        self::assertSame(self::FIXTURE . '/project/src', $paths->srcDir);
        self::assertSame(self::FIXTURE . '/project/tests', $paths->testsDir);
        self::assertSame(self::FIXTURE . '/project/qaConfig', $paths->projectConfigDir);
        self::assertSame(self::FIXTURE . '/project/var/qa', $paths->varDir);
        self::assertSame(self::FIXTURE . '/project/var/qa/cache', $paths->cacheDir);
        self::assertSame(self::LIBRARY . '/vendor-phar', $paths->pharDir);
        self::assertSame(self::LIBRARY . '/configDefaults', $paths->configDefaultsDir);
    }

    #[Test]
    public function aCustomComposerBinDirIsHonoured(): void
    {
        $paths = new ProjectPathsResolver()->resolve(self::FIXTURE . '/symfonyProject', self::LIBRARY);

        self::assertSame(self::FIXTURE . '/symfonyProject/bin', $paths->binDir);
        self::assertSame(self::FIXTURE . '/symfonyProject/test', $paths->testsDir);
    }

    #[Test]
    public function aProjectWithoutSrcIsRejectedWithGuidance(): void
    {
        self::assertStringContainsString("You have no 'src' directory", $this->layoutFailure(self::FIXTURE . '/noSrc'));
    }

    #[Test]
    public function aProjectWithoutTestsIsRejectedWithGuidance(): void
    {
        self::assertStringContainsString("You have no 'tests' directory", $this->layoutFailure(self::FIXTURE . '/noTests'));
    }

    #[Test]
    public function aProjectWithoutComposerJsonIsRejected(): void
    {
        self::assertStringContainsString('No readable composer.json', $this->layoutFailure(self::FIXTURE . '/noComposer'));
    }

    private function layoutFailure(string $projectRoot): string
    {
        try {
            new ProjectPathsResolver()->resolve($projectRoot, self::LIBRARY);
        } catch (ProjectLayoutException $projectLayoutException) {
            return $projectLayoutException->getMessage();
        }

        self::fail('Expected a ProjectLayoutException for ' . $projectRoot);
    }
}
