<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use LTS\PHPQA\PackageType\ProjectComposerTypeReader;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * API-surface integrity (the complement to {@see RequireApiOrInternalTagRule}).
 *
 * Classifying every class-like `@api` / `@internal` is necessary but not
 * sufficient: the classification can still be INCOHERENT. If an `@api` class
 * exposes an `@internal` one through its public signature — a public method's
 * parameter or return type, or a public property — then a consumer using the
 * supported `@api` type is forced to touch the `@internal` one, and PHPStan flags
 * the consumer with `class.internal` / `method.internal`. The public contract has
 * leaked an internal type; the `@api` promise is hollow.
 *
 * This rule catches that leak at the library's own gate. For a `type: library`
 * project, every `@api` class-like's public surface (public-method parameter and
 * return types, public-property types) is scanned; referencing an `@internal`
 * class-like IN THE SAME PACKAGE (same root namespace segment — the boundary
 * PHPStan keys `@internal` on) is an error. The fix is one of: promote the
 * referenced type to `@api` (commit to it as public contract), or keep it off the
 * public surface (e.g. map it to an `@api` DTO at the boundary).
 *
 * Third-party `@internal` types (a different root namespace) are NOT flagged here
 * — leaking another package's internals is that package's concern, and flagging it
 * would punish unavoidable vendor surface. The rule no-ops for any non-library
 * package type, unless the project opts in via `phpqaciApiOrInternal.enforce: always`
 * (and `never` forces it off even for a library) — the shared gate is
 * {@see ProjectComposerTypeReader::enforcesApiSurface()}.
 *
 * @implements Rule<InClassNode>
 */
final readonly class ApiMustNotExposeInternalRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.apiMustNotExposeInternal';

    public function __construct(
        private ProjectComposerTypeReader $typeReader,
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->typeReader->enforcesApiSurface()) {
            return [];
        }

        $classReflection = $node->getClassReflection();
        if ($classReflection->isAnonymous()) {
            return [];
        }

        // Only the public (@api) surface can leak; an @internal class referencing
        // an @internal one is fine.
        if (!$this->hasTag($this->docText($node->getOriginalNode()->getDocComment()), 'api')) {
            return [];
        }

        $className = $classReflection->getName();
        $ownRoot   = $this->rootSegment($className);

        $errors   = [];
        $reported = [];
        foreach ($this->publicSurface($classReflection, $scope) as [$where, $type]) {
            foreach ($type->getReferencedClasses() as $referenced) {
                if ($referenced === $className) {
                    continue;
                }

                if (isset($reported[$referenced])) {
                    continue;
                }

                if ($this->rootSegment($referenced) !== $ownRoot) {
                    continue; // only OUR OWN internals are the leak we own
                }

                if (!$this->reflectionProvider->hasClass($referenced)) {
                    continue;
                }

                if (!$this->isInternal($this->reflectionProvider->getClass($referenced))) {
                    continue;
                }

                $reported[$referenced] = true;
                $errors[]              = RuleErrorBuilder::message(\sprintf(
                    'Class %s is @api but exposes @internal type %s through its public surface (%s). '
                    . 'A consumer using this @api class would be forced to touch an @internal one '
                    . '(PHPStan flags that as class.internal/method.internal). Either promote %s to @api '
                    . '(commit to it as a public contract) or keep it off the public surface '
                    . '(e.g. map it to an @api type at the boundary).',
                    $className,
                    $referenced,
                    $where,
                    $referenced,
                ))->identifier(self::IDENTIFIER)->build();
            }
        }

        return $errors;
    }

    /**
     * Yield [where-label, type] for every type on the class's own public surface:
     * public-method parameter + return types (constructor included) and
     * public-property types. Inherited members are skipped (a parent classifies
     * its own surface).
     *
     * @return iterable<array{string, Type}>
     */
    private function publicSurface(ClassReflection $classReflection, Scope $scope): iterable
    {
        $native     = $classReflection->getNativeReflection();
        $className  = $classReflection->getName();

        foreach ($native->getMethods() as $nativeMethod) {
            if (!$nativeMethod->isPublic()) {
                continue;
            }

            if ($nativeMethod->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            // A member tagged @internal (e.g. a factory-only constructor or an
            // internal mapping bridge) is not part of the consumer surface, so an
            // internal type reached only through it is not a leak.
            if ($this->hasTag($this->docText($nativeMethod->getDocComment()), 'internal')) {
                continue;
            }

            $method = $classReflection->getMethod($nativeMethod->getName(), $scope);
            foreach ($method->getVariants() as $variant) {
                foreach ($variant->getParameters() as $parameter) {
                    yield [
                        \sprintf('parameter $%s of method %s()', $parameter->getName(), $nativeMethod->getName()),
                        $parameter->getType(),
                    ];
                }

                yield [\sprintf('return type of method %s()', $nativeMethod->getName()), $variant->getReturnType()];
            }
        }

        foreach ($native->getProperties() as $nativeProperty) {
            if (!$nativeProperty->isPublic()) {
                continue;
            }

            if ($nativeProperty->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            if (!$classReflection->hasProperty($nativeProperty->getName())) {
                continue;
            }

            $property = $classReflection->getProperty($nativeProperty->getName(), $scope);
            yield [\sprintf('property $%s', $nativeProperty->getName()), $property->getReadableType()];
        }
    }

    private function isInternal(ClassReflection $classReflection): bool
    {
        return $this->hasTag($this->docText($classReflection->getNativeReflection()->getDocComment()), 'internal');
    }

    private function rootSegment(string $fqcn): string
    {
        return explode('\\', ltrim($fqcn, '\\'))[0];
    }

    private function docText(\PhpParser\Comment\Doc|string|false|null $docComment): string
    {
        if ($docComment instanceof \PhpParser\Comment\Doc) {
            return $docComment->getText();
        }

        return \is_string($docComment) ? $docComment : '';
    }

    /**
     * Matches a standalone `@api` / `@internal` PHPDoc tag, not a longer tag that
     * merely starts with it (e.g. `@apiNote`, `@internalRef`).
     */
    private function hasTag(string $docText, string $tag): bool
    {
        return 1 === \Safe\preg_match('/@' . $tag . '(?![\w-])/', $docText);
    }
}
