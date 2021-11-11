<?php

declare(strict_types=1);

namespace LTS\PHPQA;

use Exception;
use Generator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use SplHeap;

final class Psr4Validator
{
    private string $pathToProjectRoot;

    /** @phpstan-ignore-next-line  Seems impossible to properly define type for this */
    private array $decodedComposerJson;

    /** @var string[] */
    private array $parseErrors = [];

    /** @var array<string,array<string,string>> */
    private array $psr4Errors = [];

    /** @var string[] */
    private array $ignoreRegexPatterns;

    /** @var string[] */
    private array $ignoredFiles = [];

    /** @var string[] */
    private array $missingPaths = [];

    /**
     * Psr4Validator constructor.
     *
     * @param string[]                $ignoreRegexPatterns Set of regex patterns used to exclude files or
     *                                                     directories
     * @param array<int|string,mixed> $decodedComposerJson
     */
    public function __construct(array $ignoreRegexPatterns, string $pathToProjectRoot, array $decodedComposerJson)
    {
        $this->ignoreRegexPatterns = $ignoreRegexPatterns;
        $this->pathToProjectRoot = $pathToProjectRoot;
        $this->decodedComposerJson = $decodedComposerJson;
    }

    /**
     * @throws Exception
     *
     * @return array<string,array<int|string,mixed>>
     */
    public function main(): array
    {
        $this->loop();
        $errors = [];
        //Actual Errors
        if ([] !== $this->psr4Errors) {
            $errors['PSR-4 Errors:'] = $this->psr4Errors;
        }
        if ([] !== $this->parseErrors) {
            $errors['Parse Errors:'] = $this->parseErrors;
        }
        if ([] !== $this->missingPaths) {
            $errors['Missing Paths:'] = $this->missingPaths;
        }
        if ([] === $errors) {
            return $errors;
        }
        //Debug Info
        if ([] !== $this->ignoredFiles) {
            $errors['Ignored Files:'] = $this->ignoredFiles;
        }

        return $errors;
    }

    /**
     * @throws Exception
     */
    private function loop(): void
    {
        foreach ($this->yieldPhpFilesToCheck() as [$absPathRoot, $namespaceRoot, $fileInfo]) {
            $this->check($absPathRoot, $namespaceRoot, $fileInfo);
        }
    }

    /**
     * @throws Exception
     *
     * @return Generator<array{string, string, SplFileInfo}>
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function yieldPhpFilesToCheck(): Generator
    {
        $json = $this->decodedComposerJson;
        foreach (['autoload', 'autoload-dev'] as $autoload) {
            if (!isset($json[$autoload]['psr-4'])) {
                continue;
            }
            $psr4 = $json[$autoload]['psr-4'];
            foreach ($psr4 as $namespaceRoot => $paths) {
                if (!\is_array($paths)) {
                    $paths = [$paths];
                }
                foreach ($paths as $path) {
                    $absPathRoot = $this->pathToProjectRoot . '/' . $path;
                    $realAbsPathRoot = realpath($absPathRoot);
                    if (false === $realAbsPathRoot) {
                        $this->addMissingPathError($path, $namespaceRoot, $absPathRoot);
                        continue;
                    }
                    $iterator = $this->getDirectoryIterator($absPathRoot);
                    foreach ($iterator as $fileInfo) {
                        if ('php' !== $fileInfo->getExtension()) {
                            continue;
                        }
                        foreach ($this->ignoreRegexPatterns as $pattern) {
                            $path = (string)$fileInfo->getRealPath();
                            if (1 === \Safe\preg_match($pattern, $path)) {
                                $this->ignoredFiles[] = $path;
                                continue 2;
                            }
                        }
                        yield [
                            $absPathRoot,
                            $namespaceRoot,
                            $fileInfo,
                        ];
                    }
                }
            }
        }
    }

    private function addMissingPathError(string $path, string $namespaceRoot, string $absPathRoot): void
    {
        $invalidPathMessage = "Namespace root '{$namespaceRoot}'\ncontains a path '{$path}'\nwhich doesn't exist\n";
        if (false !== stripos($absPathRoot, 'Magento')) {
            $invalidPathMessage .= 'Magento\'s composer includes this by default, '
                                   . 'it should be removed from the psr-4 section';
        }
        $this->missingPaths[$path] = $invalidPathMessage;
    }

    /**
     * @return SplHeap|SplFileInfo[]
     * @SuppressWarnings(PHPMD.UndefinedVariable) - phpmd cant handle the anon class
     */
    private function getDirectoryIterator(string $realPath): SplHeap
    {
        $directoryIterator = new RecursiveDirectoryIterator(
            $realPath,
            RecursiveDirectoryIterator::SKIP_DOTS
        );
        $iterator = new RecursiveIteratorIterator(
            $directoryIterator,
            RecursiveIteratorIterator::SELF_FIRST
        );

        return new class($iterator) extends SplHeap {
            /**
             *  constructor.
             *
             * @param RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator
             */
            public function __construct(RecursiveIteratorIterator $iterator)
            {
                foreach ($iterator as $item) {
                    $this->insert($item);
                }
            }

            protected function compare($item1, $item2): int
            {
                if (!($item1 instanceof SplFileInfo) || !($item2 instanceof SplFileInfo)) {
                    throw new InvalidArgumentException('Unexpected items, should be SplFileInfo');
                }

                return strcmp((string)$item2->getRealPath(), (string)$item1->getRealPath());
            }
        };
    }

    private function check(string $absPathRoot, string $namespaceRoot, SplFileInfo $fileInfo): void
    {
        $actualNamespace = $this->getActualNamespace($fileInfo);
        if ('' === $actualNamespace) {
            return;
        }
        $expectedNamespace = $this->expectedFileNamespace($absPathRoot, $namespaceRoot, $fileInfo);
        if ($actualNamespace !== $expectedNamespace) {
            $this->psr4Errors[$namespaceRoot][] =
                [
                    'fileInfo' => $fileInfo->getRealPath(),
                    'expectedNamespace' => $expectedNamespace,
                    'actualNamespace' => $actualNamespace,
                ];
        }
    }

    private function getActualNamespace(SplFileInfo $fileInfo): string
    {
        $contents = file_get_contents($fileInfo->getPathname());
        if (false === $contents) {
            throw new RuntimeException('Failed getting file contents for ' . $fileInfo->getPathname());
        }
        $matches = null;
        preg_match('%namespace\s+?([^;]+)%', $contents, $matches);
        if ([] === $matches) {
            $this->parseErrors[] = (string)$fileInfo->getRealPath();

            return '';
        }

        return $matches[1];
    }

    private function expectedFileNamespace(string $absPathRoot, string $namespaceRoot, SplFileInfo $fileInfo): string
    {
        $relativePath = substr($fileInfo->getPathname(), \strlen($absPathRoot));
        $relativeDir = \dirname($relativePath);
        $relativeNs = '';
        if ('.' !== $relativeDir) {
            $relativeNs = str_replace(
                '/',
                '\\',
                ltrim($relativeDir, '/')
            );
        }

        return rtrim($namespaceRoot . $relativeNs, '\\');
    }
}
