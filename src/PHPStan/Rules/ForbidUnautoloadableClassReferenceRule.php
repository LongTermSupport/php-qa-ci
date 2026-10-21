<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A class referenced from another file must be one the autoloader can load.
 *
 * A class declared at the foot of another class's file, or in a file whose namespace
 * has drifted from its directory, is not where its PSR-4 or PSR-0 prefix says it is.
 * Code in its own file can use it, because loading the file declares it. Code in any
 * other file finds it only when something else has already loaded that file, which
 * a full test run usually does and a single test, a filtered run, a random order or
 * Infection's covering-tests run does not: "Class not found", from a file that is fine.
 *
 * WRONG (tests/Runner/ToolExecutorTest.php):
 *
 *   final class ToolExecutorTest extends TestCase { ... }
 *   final readonly class AlwaysRetry implements RetryPromptInterface { ... }
 *
 * and tests/Runner/PipelineTest.php says `new AlwaysRetry()`.
 *
 * RIGHT: AlwaysRetry in its own file, at the path its namespace gives.
 *
 * Quiet on: a reference from the declaring file itself, or from a file that includes
 * it by a static path; a class no PSR-4 or PSR-0 prefix of the project claims; a file
 * a `classmap` or `files` entry loads; vendored code.
 *
 * @see AutoloadPathDetector for the decision against the composer.json mapping
 * @see ClassReferenceScanner for what counts as a reference
 *
 * See: docs/phpstan-rules/forbid-unautoloadable-class-reference.md
 *
 * @implements Rule<FileNode>
 */
final readonly class ForbidUnautoloadableClassReferenceRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.unautoloadableClassReference';

    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private AutoloadPathDetector $autoloadPathDetector,
        private VendoredCodeDetector $vendoredCodeDetector,
        private ClassReferenceScanner $scanner,
    ) {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $file     = $this->real($scope->getFile());
        $nodes    = $node->getNodes();
        $included = $this->scanner->includedFiles($file, ...$nodes);

        $errors   = [];
        $reported = [];
        foreach ($this->scanner->classReferences(...$nodes) as ['name' => $className, 'line' => $line]) {
            $declaringFile = $this->declaringFile($className);
            if (null === $declaringFile || $declaringFile === $file || \in_array($declaringFile, $included, true)) {
                continue;
            }

            if ($this->vendoredCodeDetector->isVendoredCode($declaringFile)) {
                continue;
            }

            $expectedPath = $this->autoloadPathDetector->expectedPathWhenUnloadable($className, $declaringFile);
            $key          = $className . '@' . $line;
            if (null === $expectedPath || isset($reported[$key])) {
                continue;
            }

            $reported[$key] = true;
            $errors[]       = RuleErrorBuilder::message(\sprintf(
                'Class %s cannot be autoloaded: it is declared in %s, not at %s, so this reference finds it only if '
                . 'that file was loaded first. Move the class to its own file at that path.',
                $className,
                $this->autoloadPathDetector->relativeToRoot($declaringFile),
                $this->autoloadPathDetector->relativeToRoot($expectedPath),
            ))->identifier(self::IDENTIFIER)->line($line)->build();
        }

        return $errors;
    }

    private function declaringFile(string $className): ?string
    {
        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        // A class declared inside a stream, such as PHPStan's own phar, is no file of the project's.
        $fileName = $this->reflectionProvider->getClass($className)->getFileName();
        if (null === $fileName || str_contains($fileName, '://')) {
            return null;
        }

        return $this->real($fileName);
    }

    private function real(string $path): string
    {
        return !str_contains($path, '://') && file_exists($path) ? \Safe\realpath($path) : $path;
    }
}
