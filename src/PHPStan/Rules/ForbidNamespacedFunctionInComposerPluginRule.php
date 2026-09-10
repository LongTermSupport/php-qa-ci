<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A Composer plugin calling a namespaced function. A namespaced function is
 * defined by a dependency's "files" autoload, and Composer activates a plugin
 * the moment the plugin package is installed, part-way through a fresh
 * install, before the dependency exists on disk. Whether the call resolves
 * then depends on the install order of unrelated packages: the same plugin
 * works in one project and fails with "Call to undefined function" in another.
 * A Composer plugin may use PHP's global functions and Composer's own API only.
 *
 * A class is a plugin when it implements Composer\Plugin\PluginInterface, or
 * when its namespace has a `ComposerPlugin` segment, which is how the plugin
 * tree is recognised when the Composer interfaces are not loaded.
 *
 * WRONG:
 *
 *   \Safe\exec($command, $output, $exitCode);
 *
 * RIGHT:
 *
 *   $exitCode = new ProcessExecutor($io)->execute($command, $output);
 *
 * See: docs/phpstan-rules/forbid-namespaced-function-in-composer-plugin.md
 *
 * @implements Rule<FuncCall>
 */
final readonly class ForbidNamespacedFunctionInComposerPluginRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.composerPluginNamespacedFunction';

    private const string PLUGIN_INTERFACE = \Composer\Plugin\PluginInterface::class;

    private const string PLUGIN_NAMESPACE_SEGMENT = 'ComposerPlugin';

    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name || !$this->isComposerPlugin($scope)) {
            return [];
        }

        $function = $node->name->toString();
        if (!str_contains($function, '\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Composer plugin calls namespaced function %s(): a dependency\'s "files" autoload is not registered when a plugin activates part-way through an install. Use a global function or Composer\'s own API.',
                $function,
            ))
                ->identifier(self::IDENTIFIER)
                ->build(),
        ];
    }

    private function isComposerPlugin(Scope $scope): bool
    {
        $class = $scope->getClassReflection();
        if (!$class instanceof \PHPStan\Reflection\ClassReflection) {
            return false;
        }

        // The interface is only known when Composer's classes are loaded or
        // stubbed; asking about an unknown interface is an internal error.
        if ($this->reflectionProvider->hasClass(self::PLUGIN_INTERFACE) && $class->implementsInterface(self::PLUGIN_INTERFACE)) {
            return true;
        }

        $namespace = $scope->getNamespace();
        if (null === $namespace) {
            return false;
        }

        return \in_array(self::PLUGIN_NAMESPACE_SEGMENT, explode('\\', $namespace), true);
    }
}
