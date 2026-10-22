<?php

declare(strict_types=1);

namespace LTS\PHPQA;

use Exception;
use Generator;
use InvalidArgumentException;
use LTS\PHPQA\Filesystem\UnresolvablePath;
use LTS\PHPQA\Regex\RegexPattern;
use PhpParser\Error as PhpParserError;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
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

    /** @var array<array-key,array<int, array<string,string>>> */
    private array $psr4Errors = [];

    /** @var array<array-key, list<array{fileInfo: string, expectedClassLike: string, declaredClassLike: string, problem: string}>> */
    private array $classLikeErrors = [];

    /** @var list<string> */
    private array $ignoredFiles = [];

    /** @var array<array-key, string> keyed by the missing path */
    private array $missingPaths = [];

    /** @var list<string> */
    private array $unresolvable = [];

    /** @var list<RegexPattern> */
    private readonly array $ignorePatterns;

    private readonly Parser $parser;

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
        $this->parser = new ParserFactory()->createForHostVersion();
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

        if ([] !== $this->classLikeErrors) {
            $errors['Class-Like Errors:'] = $this->classLikeErrors;
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
     * The files main() skipped because their real path cannot be resolved (a dangling
     * symlink), each named by its directory's real path; one an ignore pattern matches is
     * ignored instead, and not listed here.
     *
     * @return list<string>
     */
    public function unresolvable(): array
    {
        return $this->unresolvable;
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

            /** @var array<array-key, string|list<string>> $psr4 */
            $psr4 = $autoloadSection['psr-4'];
            foreach ($psr4 as $namespaceRoot => $paths) {
                $namespaceRoot = (string)$namespaceRoot;
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

                        $unresolvable = UnresolvablePath::is($fileInfo->getPathname());
                        $path         = $unresolvable ? UnresolvablePath::nominal($fileInfo->getPathname()) : (string)$fileInfo->getRealPath();
                        foreach ($this->ignorePatterns as $pattern) {
                            if ($pattern->matches($path)) {
                                $this->ignoredFiles[] = $path;
                                continue 2;
                            }
                        }

                        if ($unresolvable) {
                            $this->unresolvable[] = $path;
                            continue;
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
        $statements = $this->parse($fileInfo);
        if (null === $statements) {
            return;
        }

        $actualNamespace = $this->declaredNamespace(...$statements);
        if (null === $actualNamespace) {
            $this->parseErrors[] = (string)$fileInfo->getRealPath();

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

        $this->checkClassLikes($namespaceRoot, $fileInfo, $actualNamespace, $expectedNamespace, ...$statements);
    }

    /**
     * The file's statements with names resolved, or null when the parser cannot read it, which
     * is a parse error naming the parser's reason.
     *
     * @return list<Stmt>|null
     */
    private function parse(SplFileInfo $fileInfo): ?array
    {
        try {
            $statements = $this->parser->parse(\Safe\file_get_contents($fileInfo->getPathname())) ?? [];
            // The resolver throws on a name it cannot resolve, such as a second `use` of one short name.
            new NodeTraverser(new NameResolver())->traverse($statements);
        } catch (PhpParserError $phpParserError) {
            $this->parseErrors[] = $fileInfo->getRealPath() . ': ' . $phpParserError->getMessage();

            return null;
        }

        return array_values($statements);
    }

    /**
     * The file's namespace: the name of its first namespace declaration, braced or not. Null
     * when it declares none, or only the global namespace. A file of several namespace blocks
     * is judged by the first; a class-like in a later block is reported by checkClassLikes().
     */
    private function declaredNamespace(Stmt ...$statements): ?string
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_ && $statement->name instanceof \PhpParser\Node\Name) {
                return $statement->name->toString();
            }
        }

        return null;
    }

    /**
     * The autoloader looks for a class-like in the one file its name maps to, so a file under a
     * PSR-4 root can be loaded only as the class-like its path names. Every other named class-like
     * it declares, its only one or one beside it, is reported; anonymous classes have no name to
     * load. A class-like whose only fault is the namespace already reported for the file is not
     * reported a second time.
     */
    private function checkClassLikes(string $namespaceRoot, SplFileInfo $fileInfo, string $actualNamespace, string $expectedNamespace, Stmt ...$statements): void
    {
        $classLikes = $this->namedClassLikes(...$statements);
        $expected   = ltrim($expectedNamespace . '\\' . $fileInfo->getBasename('.php'), '\\');
        $declared   = array_keys($classLikes);
        if (\in_array($expected, $declared, true)) {
            $remedy = 'move it to a file of its own, named for it';
        } else {
            $remedy = 'rename the class-like or the file so that the two agree';
        }

        $namespaceOnly = ltrim($actualNamespace . '\\' . $fileInfo->getBasename('.php'), '\\');
        foreach ($classLikes as $name => $kind) {
            $fqcn = (string)$name;
            if ($fqcn === $expected || ($fqcn === $namespaceOnly && $actualNamespace !== $expectedNamespace)) {
                continue;
            }

            $this->classLikeErrors[$namespaceRoot][] = [
                'fileInfo'          => (string)$fileInfo->getRealPath(),
                'expectedClassLike' => $expected,
                'declaredClassLike' => $fqcn,
                'problem'           => "{$kind} {$fqcn} is declared in a file whose path autoloads only {$expected}, so no autoloader can load it: {$remedy}",
            ];
        }
    }

    /**
     * Every named class-like the statements declare, by fully qualified name, in declaration
     * order, to its kind.
     *
     * @return array<array-key, string>
     */
    private function namedClassLikes(Stmt ...$statements): array
    {
        $classLikes = [];
        foreach (new NodeFinder()->findInstanceOf($statements, ClassLike::class) as $classLike) {
            if (null !== $classLike->namespacedName) {
                $classLikes[$classLike->namespacedName->toString()] = $this->kind($classLike);
            }
        }

        return $classLikes;
    }

    private function kind(ClassLike $classLike): string
    {
        return match (true) {
            $classLike instanceof Enum_      => 'enum',
            $classLike instanceof Interface_ => 'interface',
            $classLike instanceof Trait_     => 'trait',
            default                          => 'class',
        };
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
