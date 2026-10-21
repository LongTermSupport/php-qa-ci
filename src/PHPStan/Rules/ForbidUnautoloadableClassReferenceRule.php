<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;

/**
 * A class referenced from another file must be one the autoloader can load.
 *
 * A class declared at the foot of another class's file, or in a file whose namespace
 * has drifted from its directory, is not where its PSR-4 or PSR-0 prefix says it is.
 * Code in its own file can use it, because loading the file declares it. Code in any
 * other file that makes PHP ask the autoloader for it fails unless something else has
 * already loaded that file, which a full test run usually does and a single test, a
 * filtered run, a random order or Infection's covering-tests run does not.
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
 * Reported only where PHP asks the autoloader for the class: see
 * {@see ClassReferencePositionEnum}. A name written in a case other than the declared one
 * is reported as such, and so is a second declaration of a class the autoloader finds
 * at its path, at the copy.
 *
 * Quiet on: a reference from the declaring file itself, or from a file that includes
 * it by a static path; a class no PSR-4 or PSR-0 prefix of the project claims; a file
 * a `classmap` or `files` entry loads; vendored code.
 *
 * @see AutoloadPathDetector for the decision against the composer.json mapping
 * @see ClassReferenceScanner for what counts as a reference, and where
 *
 * See: docs/phpstan-rules/forbid-unautoloadable-class-reference.md
 *
 * @implements Rule<FileNode>
 */
final readonly class ForbidUnautoloadableClassReferenceRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.unautoloadableClassReference';

    private const string UNLESS_LOADED = 'unless that file was loaded first.';

    private const string CONSTRUCTOR = '__construct';

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
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $file     = $this->real($scope->getFile());
        $nodes    = $node->getNodes();
        $included = $this->scanner->includedFiles($file, ...$nodes);

        $errors   = [];
        $reported = [];
        foreach ($this->scanner->classReferences(...$nodes) as $reference) {
            $message = $this->referenceMessage($reference, $file, ...$included);
            $key     = $reference['name'] . '@' . $reference['line'] . '@' . $message;
            if (null === $message || isset($reported[$key])) {
                continue;
            }

            $reported[$key] = true;
            $errors[]       = $this->error($message, $reference['line']);
        }

        foreach ($this->scanner->declaredClasses(...$nodes) as ['name' => $className, 'line' => $line]) {
            $original = $this->autoloadPathDetector->claimedPathDeclaring($className, $file);
            if (null !== $original && !$this->vendoredCodeDetector->isVendoredCode($file)) {
                $errors[] = $this->error(\sprintf(
                    'Class %s is also declared at %s, where the autoloader finds it: loading this file after that one '
                    . 'fails with "Cannot declare class", and loading it first gives every other file this copy. '
                    . 'Delete this copy.',
                    $className,
                    $this->autoloadPathDetector->relativeToRoot($original),
                ), $line);
            }
        }

        return $errors;
    }

    /**
     * @param array{name: string, line: int, position: ClassReferencePositionEnum, signature: array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null} $reference
     */
    private function referenceMessage(array $reference, string $file, string ...$included): ?string
    {
        $written = $reference['name'];
        if (ClassReferencePositionEnum::Inert === $reference['position'] || !$this->reflectionProvider->hasClass($written)) {
            return null;
        }

        $declaringFile = $this->declaringFile($written);
        if (null === $declaringFile || $declaringFile === $file || \in_array($declaringFile, $included, true)
                                    || $this->vendoredCodeDetector->isVendoredCode($declaringFile)) {
            return null;
        }

        $declared     = $this->reflectionProvider->getClass($written)->getName();
        $expectedPath = $this->autoloadPathDetector->expectedPathWhenUnloadable($declared, $declaringFile);
        if (null === $expectedPath) {
            return $this->miscasedMessage($reference, $declared, $declaringFile);
        }

        // A second declaration: the autoloader finds the class at its path, and the copy is reported where it is declared.
        if (null !== $this->autoloadPathDetector->claimedPathDeclaring($declared, $declaringFile)) {
            return null;
        }

        $consequence = $this->consequence($reference);

        return null === $consequence ? null : \sprintf(
            'Class %s cannot be autoloaded: it is declared in %s, not at %s, so %s Move the class to its own file at that path.',
            $declared,
            $this->autoloadPathDetector->relativeToRoot($declaringFile),
            $this->autoloadPathDetector->relativeToRoot($expectedPath),
            $consequence,
        );
    }

    /**
     * The autoloader is asked for the name as written, and maps it to a path letter for letter.
     *
     * @param array{name: string, line: int, position: ClassReferencePositionEnum, signature: array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null} $reference
     */
    private function miscasedMessage(array $reference, string $declared, string $declaringFile): ?string
    {
        $written = $reference['name'];
        if ($written === $declared) {
            return null;
        }

        $writtenPath = $this->autoloadPathDetector->expectedPathWhenUnloadable($written, $declaringFile);
        $consequence = null === $writtenPath ? null : $this->consequence($reference);
        if (null === $writtenPath || null === $consequence) {
            return null;
        }

        return \sprintf(
            'Class %s is written here as %s: the autoloader looks for the name as written, at %s, where a '
            . 'case-sensitive filesystem has no file, so %s Write the name as it is declared.',
            $declared,
            $written,
            $this->autoloadPathDetector->relativeToRoot($writtenPath),
            $consequence,
        );
    }

    /**
     * What fails at the reference's position when the class's file was not loaded first;
     * null for a signature PHP need not load the class to check.
     *
     * @param array{name: string, line: int, position: ClassReferencePositionEnum, signature: array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null} $reference
     */
    private function consequence(array $reference): ?string
    {
        return match ($reference['position']) {
            ClassReferencePositionEnum::Loading         => 'this reference fails with a "not found" error ' . self::UNLESS_LOADED,
            ClassReferencePositionEnum::ClassName       => 'whatever loads the class by this name (a mock, a container, class_exists()) '
                . 'fails, or answers false, ' . self::UNLESS_LOADED,
            ClassReferencePositionEnum::Attribute       => 'reading this attribute with newInstance() fails with "Attribute class not found" '
                . self::UNLESS_LOADED,
            ClassReferencePositionEnum::MethodSignature => $this->signatureConsequence($reference['name'], $reference['signature']),
            ClassReferencePositionEnum::Inert           => null,
        };
    }

    /**
     * @param array{ancestors: list<string>, method: string, parameter: int|null, typeNames: list<string>}|null $signature
     */
    private function signatureConsequence(string $className, ?array $signature): ?string
    {
        if (null === $signature) {
            return null;
        }

        $method = $signature['method'];
        foreach ($this->prototypeOwners($method, ...$signature['ancestors']) as $owner) {
            if ($this->checkLoads($owner, $method, $className, $signature['parameter'], ...$signature['typeNames'])) {
                $prototype = $owner->getNativeMethod($method);

                return \sprintf(
                    'declaring this class makes PHP load it to check this signature against %s::%s(), which fails with '
                    . '"Could not check compatibility" %s',
                    $prototype->getDeclaringClass()->getName(),
                    $prototype->getName(),
                    self::UNLESS_LOADED,
                );
            }
        }

        return null;
    }

    /**
     * The ancestors whose method of that name PHP checks a method's signature against when
     * it declares the class: any that has one, unless it is private, it is a trait's
     * concrete method, or it is a constructor that is neither abstract nor an interface's.
     *
     * @return list<ClassReflection>
     */
    private function prototypeOwners(string $method, string ...$ancestors): array
    {
        $owners = [];
        foreach ($ancestors as $ancestorName) {
            if (!$this->reflectionProvider->hasClass($ancestorName)) {
                continue;
            }

            foreach ($this->reflectionProvider->getClass($ancestorName)->getAncestors() as $ancestor) {
                if (!$ancestor->hasNativeMethod($method)) {
                    continue;
                }

                $prototype = $ancestor->getNativeMethod($method);
                $abstract  = $prototype->isAbstract();
                $abstract  = \is_bool($abstract) ? $abstract : $abstract->yes();
                $declaring = $prototype->getDeclaringClass();
                if ($prototype->isPrivate()
                    || ($declaring->isTrait() && !$abstract)
                    || (self::CONSTRUCTOR === strtolower($method) && !$abstract && !$declaring->isInterface())) {
                    continue;
                }

                $owners[] = $ancestor;
            }
        }

        return $owners;
    }

    /**
     * PHP compares a signature with an inherited one by name first, and loads a class only
     * where the names do not settle it. A return type naming the class is loaded unless
     * the inherited return type names it too, or declares nothing an object could be. A
     * parameter type naming the class is loaded when the inherited parameter's type names
     * a class this parameter's type does not.
     *
     * @param string ...$typeNames every class this position's type names
     */
    private function checkLoads(ClassReflection $owner, string $method, string $className, ?int $parameter, string ...$typeNames): bool
    {
        $variant = $owner->getNativeMethod($method)->getOnlyVariant();
        if (null === $parameter) {
            $inherited = $variant->getNativeReturnType();

            return !$inherited->isObject()->no()
                && !$inherited instanceof MixedType
                && !$this->names($className, ...$inherited->getReferencedClasses());
        }

        $parameters = $variant->getParameters();
        if (!isset($parameters[$parameter])) {
            return false;
        }

        foreach ($parameters[$parameter]->getNativeType()->getReferencedClasses() as $inheritedClass) {
            if (!$this->names($inheritedClass, ...$typeNames)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the class is among the names, compared as PHP compares class names.
     */
    private function names(string $className, string ...$classNames): bool
    {
        return array_any($classNames, static fn (string $candidate): bool => 0 === strcasecmp(ltrim($candidate, '\\'), ltrim($className, '\\')));
    }

    private function error(string $message, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier(self::IDENTIFIER)->line($line)->build();
    }

    private function declaringFile(string $className): ?string
    {
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
