<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Infection;

/**
 * The source file a test case is named after, so a diff run that changed a
 * test mutates the code that test exists to pin.
 *
 * A test is `<tests>/[<suite dirs>/]<Path>/<Name>Test.php`; its source is the
 * existing `<src>/<Path>/<Name>.php` with the most leading test directories
 * kept (`tests/Small/Pipeline/FooTest.php` tries `src/Small/Pipeline/Foo.php`,
 * then `src/Pipeline/Foo.php`, then `src/Foo.php`). Anything else under the
 * tests directory (support code, fixtures, a test with no same-named source)
 * mirrors nothing. The mapping is by name only: the other source files a test
 * happens to cover are not found this way.
 *
 * @internal
 */
final readonly class TestSourceMirror
{
    private const string TEST_SUFFIX = 'Test.php';

    private string $srcRelative;

    private string $testsRelative;

    public function __construct(private string $projectRoot, string $srcDir, string $testsDir)
    {
        $this->srcRelative   = $this->relative($srcDir);
        $this->testsRelative = $this->relative($testsDir);
    }

    /** The project-relative source file $testPath (project-relative) mirrors, or null. */
    public function sourceFor(string $testPath): ?string
    {
        $prefix = $this->testsRelative . '/';
        if (!str_starts_with($testPath, $prefix) || !str_ends_with($testPath, self::TEST_SUFFIX)) {
            return null;
        }

        $segments = explode('/', substr($testPath, \strlen($prefix), -\strlen(self::TEST_SUFFIX)) . '.php');
        foreach (array_keys($segments) as $skip) {
            $candidate = $this->srcRelative . '/' . implode('/', \array_slice($segments, $skip));
            if (is_file($this->projectRoot . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function relative(string $dir): string
    {
        $root = rtrim($this->projectRoot, '/') . '/';

        return trim(str_starts_with($dir, $root) ? substr($dir, \strlen($root)) : $dir, '/');
    }
}
