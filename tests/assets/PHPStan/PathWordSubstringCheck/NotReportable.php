<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\PathWordSubstringCheck;

use Closure;
use SplFileInfo;

/**
 * Nothing here is reported: the haystack is not known to be an absolute path,
 * or the needle is not a bare word.
 */
final class NotReportable
{
    private const string CONFIG_FILE = 'composer.json';

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

    public function aConstantIsALiteralNotAnAbsolutePath(): bool
    {
        return str_contains(self::CONFIG_FILE, 'composer');
    }

    /**
     * @param Closure(): string $read
     */
    public function dynamicCallsAreNotKnownPaths(Closure $read, SplFileInfo $info, string $method): bool
    {
        return str_contains($read(), 'Magento') || str_contains($info->{$method}(), 'Magento');
    }

    public function interpolationStartingWithText(): bool
    {
        return str_contains("label {$this->projectRoot}", 'Magento');
    }

    public function firstClassCallable(): bool
    {
        return array_any(['Magento'], str_contains(...));
    }

    public function parameterSearchedBeforeItIsMadeAPath(string $x): string
    {
        if (str_contains($x, 'Magento')) {
            $x = \Safe\realpath($x);
        }

        return $x;
    }

    /** @param array{string, string} $pair */
    public function destructuredOver(string $relative, array $pair): bool
    {
        $x = \Safe\realpath($relative);
        [$x, $other] = $pair;

        return str_contains($x, 'Magento') || str_contains($other, 'Magento');
    }

    public function aliasedByReference(string $relative): bool
    {
        $x     = \Safe\realpath($relative);
        $alias = &$x;
        $alias = 'text';

        return str_contains($x, 'Magento');
    }

    public function boundToAReference(string $relative, string $text): bool
    {
        $x = \Safe\realpath($relative);
        $x = &$text;

        return str_contains($x, 'Magento');
    }

    /** @param list<string> $list */
    public function overwrittenByAForeachValue(string $relative, array $list): bool
    {
        $x = \Safe\realpath($relative);
        foreach ($list as $x) {
        }

        return str_contains($x, 'Magento');
    }

    /** @param array<string, string> $map */
    public function overwrittenByAForeachKey(string $relative, array $map): bool
    {
        $x = \Safe\realpath($relative);
        foreach ($map as $x => $value) {
        }

        return str_contains($x, 'Magento');
    }

    public function aStaticKeepsAnEarlierCallsValue(string $relative): bool
    {
        static $x;
        $x = \Safe\realpath($relative);

        return str_contains($x, 'Magento');
    }

    public function aGlobalIsWrittenElsewhere(string $relative): bool
    {
        global $x;
        $x = \Safe\realpath($relative);

        return str_contains($x, 'Magento');
    }

    public function anotherCompoundAssignment(string $relative): bool
    {
        $x = \Safe\realpath($relative);
        $x ^= 'mask';

        return str_contains($x, 'Magento');
    }
}
