<?php

declare(strict_types=1);

namespace LTS\PHPQA;

use Exception;
use Generator;
use InvalidArgumentException;
use LTS\PHPQA\Regex\RegexPattern;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SplHeap;

final class Psr4Validator
{
    /** A package of Magento's own Composer vendor, in any case. */
    private const string MAGENTO_PACKAGE = '~^magento/~i';

    /** @var list<string> */
    private array $parseErrors = [];

    /** @var array<string,array<int, array<string,string>>> */
    private array $psr4Errors = [];

    /** @var list<string> */
    private array $ignoredFiles = [];

    /** @var array<string, string> keyed by the missing path */
    private array $missingPaths = [];

    /** @var list<RegexPattern> */
    private readonly array $ignorePatterns;

    /**
     * Psr4Validator constructor.
     *
     * @param array<array-key, string> $ignoreRegexPatterns Set of regex patterns used to exclude files or
     *                                                      directories
     * @param array<int|string,mixed>  $decodedComposerJson
     *
     * @throws InvalidArgumentException naming the first ignore pattern that does not compile
     */
    public function __construct(array $ignoreRegexPatterns, private readonly string $pathToProjectRoot, private readonly array $decodedComposerJson)
    {
        $this->ignorePatterns = array_map(
            static fn (string $pattern): RegexPattern => RegexPattern::compile($pattern, 'ignore pattern'),
            array_values($ignoreRegexPatterns),
        );
    }

    /**
     * @return array<string,array<int|string,mixed>>
     *
     * @throws Exception
     */
    public function main(): array
    {
        $this->loop();
        $errors = [];
        // Actual Errors
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

        // Debug Info
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
     * @return Generator<array{string, string, SplFileInfo}>
     *
     * @throws Exception
     */
    private function yieldPhpFilesToCheck(): Generator
    {
        $json = $this->decodedComposerJson;
        foreach (['autoload', 'autoload-dev'] as $autoload) {
            $autoloadSection = $json[$autoload] ?? null;
            if (!\is_array($autoloadSection)) {
                continue;
            }

            if (!isset($autoloadSection['psr-4'])) {
                continue;
            }

            if (!\is_array($autoloadSection['psr-4'])) {
                continue;
            }

            /** @var array<string, string|list<string>> $psr4 */
            $psr4 = $autoloadSection['psr-4'];
            foreach ($psr4 as $namespaceRoot => $paths) {
                if (!\is_array($paths)) {
                    $paths = [$paths];
                }

                foreach ($paths as $path) {
                    $absPathRoot = $this->pathToProjectRoot . '/' . $path;
                    if (!file_exists($absPathRoot)) {
                        $this->addMissingPathError($path, $namespaceRoot);
                        continue;
                    }

                    $realAbsPathRoot = \Safe\realpath($absPathRoot);

                    $iterator = $this->getDirectoryIterator($absPathRoot);
                    foreach ($iterator as $fileInfo) {
                        if ('php' !== $fileInfo->getExtension()) {
                            continue;
                        }

                        foreach ($this->ignorePatterns as $pattern) {
                            $path = (string)$fileInfo->getRealPath();
                            if ($pattern->matches($path)) {
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

    private function addMissingPathError(string $path, string $namespaceRoot): void
    {
        $invalidPathMessage = "Namespace root '{$namespaceRoot}'\ncontains a path '{$path}'\nwhich doesn't exist\n";
        if ($this->isMagentoProject()) {
            $invalidPathMessage .= "Magento's composer includes this by default, "
                                   . 'it should be removed from the psr-4 section';
        }

        $this->missingPaths[$path] = $invalidPathMessage;
    }

    /**
     * A Magento project says so in its composer.json: it is a Magento package itself, or it requires
     * one. Where it is checked out says nothing about it.
     */
    private function isMagentoProject(): bool
    {
        $name     = $this->decodedComposerJson['name']    ?? null;
        $require  = $this->decodedComposerJson['require'] ?? null;
        $packages = [
            ...(\is_string($name) ? [$name] : []),
            ...(\is_array($require) ? array_keys($require) : []),
        ];

        return [] !== \Safe\preg_grep(self::MAGENTO_PACKAGE, $packages);
    }

    /**
     * @return SplHeap<SplFileInfo>
     */
    private function getDirectoryIterator(string $realPath): SplHeap
    {
        $directoryIterator = new RecursiveDirectoryIterator(
            $realPath,
            RecursiveDirectoryIterator::SKIP_DOTS
        );
        $iterator          = new RecursiveIteratorIterator(
            $directoryIterator,
            RecursiveIteratorIterator::SELF_FIRST
        );

        return new class($iterator) extends SplHeap {
            /**
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
                    'fileInfo'          => (string)$fileInfo->getRealPath(),
                    'expectedNamespace' => $expectedNamespace,
                    'actualNamespace'   => $actualNamespace,
                ];
        }
    }

    private function getActualNamespace(SplFileInfo $fileInfo): string
    {
        $contents = \Safe\file_get_contents($fileInfo->getPathname());
        $matches  = [];
        \Safe\preg_match('%namespace\s+?([^;]+)%', $contents, $matches);
        if (!isset($matches[1])) {
            $this->parseErrors[] = (string)$fileInfo->getRealPath();

            return '';
        }

        return $matches[1];
    }

    private function expectedFileNamespace(string $absPathRoot, string $namespaceRoot, SplFileInfo $fileInfo): string
    {
        $relativePath = substr($fileInfo->getPathname(), \strlen($absPathRoot));
        $relativeDir  = \dirname($relativePath);
        $relativeNs   = '';
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
