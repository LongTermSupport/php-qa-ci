<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\PathWordSubstringCheck;

use ReflectionClass;
use SplFileInfo;

/**
 * Every call here decides something from a word somewhere in an absolute path.
 */
final class Reportable
{
    private const string PLATFORM = 'Magento';

    private static string $cacheDir = '/tmp/cache';

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function pathNamedParameter(string $absPathRoot): bool
    {
        return false !== stripos($absPathRoot, 'Magento');
    }

    public function pathNamedProperty(): bool
    {
        return str_contains($this->projectRoot, 'drupal');
    }

    public function constantNeedle(string $filePath): bool
    {
        return str_contains($filePath, self::PLATFORM);
    }

    public function pathFunctions(): bool
    {
        return str_contains(__DIR__, 'vendor')
            || false !== strpos((string)getcwd(), 'magento')
            || false !== stristr(\Safe\realpath('.'), 'Symfony')
            || str_contains(__FILE__ . '/x', 'Laravel')
            || str_contains(\dirname(__DIR__), 'wordpress');
    }

    public function pathMethods(SplFileInfo $info, ReflectionClass $class): bool
    {
        return str_contains((string)$info->getRealPath(), 'generated')
            || str_contains($info->getPathname(), 'Generated')
            || str_contains((string)$class->getFileName(), 'legacy');
    }

    public function localAssignedAPath(string $relative): bool
    {
        $x = \Safe\realpath($relative);

        return false !== strrpos($x, 'shop');
    }

    public function concatenationStartingWithAPath(string $relative): bool
    {
        return str_contains($this->projectRoot . '/' . $relative, 'Magento');
    }

    public function namedArguments(string $rootDir): bool
    {
        return str_contains(needle: 'Magento', haystack: $rootDir);
    }

    public function interpolationStartingWithAPath(): bool
    {
        return str_contains("{$this->projectRoot}/app", 'Magento');
    }

    public function nullableReceivers(?SplFileInfo $info, ?self $other): bool
    {
        return str_contains((string)$info?->getPathname(), 'generated')
            || str_contains((string)$other?->projectRoot, 'drupal');
    }

    public function staticProperty(): bool
    {
        return str_contains(self::$cacheDir, 'phpstan');
    }

    public function functionNamesAreCaseInsensitive(): bool
    {
        return STR_CONTAINS(__DIR__, 'vendor');
    }

    public function appendedToStaysAPath(string $relative): bool
    {
        $x = \Safe\realpath($relative);
        $x .= '/app';

        return str_contains($x, 'shop');
    }

    public function coalesceAssignedAPath(): bool
    {
        $x ??= __DIR__;

        return str_contains($x, 'shop');
    }

    public function closureOverAParameterMadeAPath(string $x): bool
    {
        $x = \Safe\realpath($x);

        return (static fn (): bool => str_contains($x, 'shop'))();
    }

    public function aRetypedLocalDoesNotHideALaterSearch(string $relative): bool
    {
        $text = \Safe\realpath($relative);
        $text = \Safe\file_get_contents($text);
        $x    = \Safe\realpath($relative);

        return str_contains($text, 'shop') || str_contains($x, 'shop');
    }
}
