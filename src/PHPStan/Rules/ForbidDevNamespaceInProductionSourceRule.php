<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

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
 * declarations contradict each other, and the `autoload` one takes effect.
 *
 * WRONG (composer `autoload` maps `App\` to `src/`):
 *
 *   src/Command/Dev/SandboxPushCommand.php   namespace App\Command\Dev;
 *
 * RIGHT (composer `autoload-dev` maps `App\Dev\` to `src-dev/`):
 *
 *   src-dev/SandboxPushCommand.php           namespace App\Dev;
 *
 * Quiet on: an `autoload-dev` root (that IS the fix), vendored code (the hazard
 * belongs to the package that publishes it, and this project cannot move a file
 * it does not own), anonymous classes, and a segment or class name that merely
 * STARTS with `Dev`.
 *
 * @see DevCodeInShippedRootDetector for the decision, and for why namespace and
 *      path are both read
 *
 * See: docs/phpstan-rules/forbid-dev-namespace-in-production-source.md
 *
 * @implements Rule<InClassNode>
 */
final readonly class ForbidDevNamespaceInProductionSourceRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.devNamespaceInProductionSource';

    public function __construct(
        private DevCodeInShippedRootDetector $detector,
        private VendoredCodeDetector $vendoredCodeDetector,
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

        $shippedRoot = $this->detector->shippedRootCarryingDevCode($className, $fileName);
        if (null === $shippedRoot) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Class %s is dev-only code (a Dev segment) yet lives under the shipped composer autoload root "%s", '
                . 'so it installs into production and into every consumer. Move it to a src-dev/ tree mapped under '
                . 'autoload-dev and register it for the dev environment only.',
                $className,
                $shippedRoot,
            ))->identifier(self::IDENTIFIER)->build(),
        ];
    }
}
