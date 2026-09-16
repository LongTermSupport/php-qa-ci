<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use LTS\PHPQA\PackageType\AutoloadRootReader;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Dev-only code must not live under a SHIPPED autoload root.
 *
 * A `Dev` segment in a namespace or a path is the author saying "this is for
 * maintainers": a fixture capture command, a sandbox pusher, a spec generator.
 * A composer `autoload` root says the opposite — it installs into every
 * production deployment and into every consumer, dragging the tooling's
 * dependencies, its runnable commands and its audit surface with it. The two
 * declarations contradict each other, and the `autoload` one is the one that
 * takes effect.
 *
 * WRONG (composer `autoload` maps `App\` to `src/`):
 *
 *   src/Command/Dev/SandboxPushCommand.php   namespace App\Command\Dev;
 *
 * RIGHT (composer `autoload-dev` maps `App\Dev\` to `src-dev/`):
 *
 *   src-dev/SandboxPushCommand.php           namespace App\Dev;
 *
 * The roots come from the analysed project's own composer.json, never from a
 * hard-coded `src/`, so a project whose shipped root is `lib/` or `app/` is read
 * just as correctly.
 *
 * Quiet on: an `autoload-dev` root (that IS the fix), vendored code (the hazard
 * belongs to the package that publishes it, and this project cannot move a file
 * it does not own), anonymous classes, and a segment or class name that merely
 * STARTS with `Dev` — `DevTools`, `DevModeSwitch` — because only a whole segment
 * is a declaration.
 *
 * See: docs/phpstan-rules/forbid-dev-namespace-in-production-source.md
 *
 * @implements Rule<InClassNode>
 */
final readonly class ForbidDevNamespaceInProductionSourceRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.devNamespaceInProductionSource';

    private const string DEV_SEGMENT = 'Dev';

    private const string NAMESPACE_SEPARATOR = '\\';

    public function __construct(
        private AutoloadRootReader $rootReader,
        private VendoredCodeDetector $vendoredCodeDetector,
        private string $projectRoot,
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
        $classReflection = $node->getClassReflection();

        // An anonymous class has no namespace to declare anything with.
        if ($classReflection->isAnonymous()) {
            return [];
        }

        $className = $classReflection->getName();
        $fileName  = $classReflection->getFileName();

        if ($this->vendoredCodeDetector->isVendoredCode($fileName)) {
            return [];
        }

        if ($this->isDevOnly($className, $fileName)) {
            return [];
        }

        $prefix = $this->shippedRootFor($className, $fileName);
        if (null === $prefix) {
            return [];
        }

        if (!$this->carriesDevSegment($className, $prefix, $fileName)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Class %s is dev-only code (a %s segment) yet lives under the shipped composer autoload root "%s", '
                . 'so it installs into production and into every consumer. Move it to a src-dev/ tree mapped under '
                . 'autoload-dev and register it for the dev environment only.',
                $className,
                self::DEV_SEGMENT,
                $prefix,
            ))->identifier(self::IDENTIFIER)->build(),
        ];
    }

    /**
     * Declared not-shipped, by namespace prefix or by directory: `composer install
     * --no-dev` drops it, so the contradiction this rule reports cannot arise.
     */
    private function isDevOnly(string $className, ?string $fileName): bool
    {
        foreach ($this->rootReader->devRoots() as $prefix => $directories) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }

            foreach ($directories as $directory) {
                if (null !== $this->relativeToRoot($fileName, $directory)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The shipped PSR-4 prefix this class belongs to, or null when it is under none.
     *
     * Matched by namespace first and by directory second: a file whose layout does
     * not conform to PSR-4 is still shipped by the root that contains it.
     */
    private function shippedRootFor(string $className, ?string $fileName): ?string
    {
        foreach ($this->rootReader->productionRoots() as $prefix => $directories) {
            if (str_starts_with($className, $prefix)) {
                return $prefix;
            }

            foreach ($directories as $directory) {
                if (null !== $this->relativeToRoot($fileName, $directory)) {
                    return $prefix;
                }
            }
        }

        return null;
    }

    /**
     * A whole `Dev` segment in the namespace below the root, or in the file's
     * directory path below the root. The class's own short name and the file's
     * basename are excluded: a class NAMED Dev is not a Dev tree.
     */
    private function carriesDevSegment(string $className, string $prefix, ?string $fileName): bool
    {
        if (\in_array(self::DEV_SEGMENT, $this->namespaceSegments($className, $prefix), true)) {
            return true;
        }

        foreach ($this->rootReader->productionRoots()[$prefix] ?? [] as $directory) {
            $relative = $this->relativeToRoot($fileName, $directory);
            if (null === $relative) {
                continue;
            }

            if (\in_array(self::DEV_SEGMENT, $this->pathSegments($relative), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> the namespace segments between the root prefix and the class's short name
     */
    private function namespaceSegments(string $className, string $prefix): array
    {
        if (!str_starts_with($className, $prefix)) {
            return [];
        }

        $segments = explode(self::NAMESPACE_SEPARATOR, substr($className, \strlen($prefix)));
        array_pop($segments);

        return array_values(array_filter($segments, static fn (string $segment): bool => '' !== $segment));
    }

    /**
     * @return list<string> the directory segments of a root-relative file path
     */
    private function pathSegments(string $relativePath): array
    {
        $segments = explode('/', $relativePath);
        array_pop($segments);

        return array_values(array_filter($segments, static fn (string $segment): bool => '' !== $segment));
    }

    /**
     * The file's path relative to a root directory, or null when it is not under it.
     */
    private function relativeToRoot(?string $fileName, string $directory): ?string
    {
        if (null === $fileName) {
            return null;
        }

        $absoluteRoot = $this->absoluteRoot($directory);
        if (!str_starts_with($fileName, $absoluteRoot . '/')) {
            return null;
        }

        return substr($fileName, \strlen($absoluteRoot) + 1);
    }

    private function absoluteRoot(string $directory): string
    {
        $root     = rtrim($this->projectRoot, '/');
        $relative = trim($directory, '/');

        return '' === $relative || '.' === $relative ? $root : $root . '/' . $relative;
    }
}
