<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\PathWordSubstringCheck;

use SplFileInfo;

/**
 * Nothing here is reported: the haystack is not known to be an absolute path,
 * or the needle is not a bare word.
 */
final class NotReportable
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public function textHaystacks(string $output, string $root, string $rootNamespace, string $fileContents): bool
    {
        return str_contains($output, 'Magento')
            || str_contains($root, 'Magento')
            || str_contains($rootNamespace, 'Magento')
            || str_contains($fileContents, 'Magento')
            || str_contains(\Safe\file_get_contents(__FILE__), 'strict_types');
    }

    public function needlesThatAreNotWords(string $filePath, string $needle): bool
    {
        return str_contains($filePath, '/vendor/')
            || str_contains($filePath, '.php')
            || str_contains($filePath, '%')
            || str_contains($filePath, $needle)
            || str_contains($filePath, $this->projectRoot);
    }

    public function anchoredFunctions(string $filePath): bool
    {
        return str_starts_with($filePath, 'Magento') || str_ends_with($filePath, 'Magento');
    }

    public function basenameIsNotAPath(SplFileInfo $info): bool
    {
        return str_contains($info->getFilename(), 'Test');
    }

    public function localReassignedToText(string $relative): bool
    {
        $x = \Safe\realpath($relative);
        $x = \Safe\file_get_contents($x);

        return str_contains($x, 'Magento');
    }

    public function localNeverAssignedAPath(string $relative): bool
    {
        $x = strtolower($relative);

        return str_contains($x, 'magento');
    }

    /** @param list<string> $pathParts */
    public function nonStringPathName(array $pathParts): bool
    {
        return \in_array('Magento', $pathParts, true);
    }
}
