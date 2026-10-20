<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids classifying a class or namespace name by a bare word found anywhere in it.
 *
 * `str_contains($className, 'Generated')` is meant to ask "is this in a Generated namespace", and
 * also holds for `App\GeneratedReports\Invoice` and `App\Ungenerated`; a rule that skips the
 * namespace then skips classes nobody exempted. Compare whole namespace segments instead.
 *
 * NamespaceWordSubstringCallCollector finds str_contains(), strpos() and stripos() calls looking
 * for a namespace-segment word in a subject that is a class or namespace name, or in a local
 * variable; ClassNameVariableWriteCollector finds the local variables assigned a class or
 * namespace name. A call is reported when its subject is a class name, or a variable some write
 * to which, in the same function, is one.
 *
 * See docs/phpstan-rules/forbid-unanchored-namespace-substring-check.md.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ForbidUnanchoredNamespaceSubstringCheckRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.unanchoredNamespaceSubstringCheck';

    private const string MESSAGE = "%s() looks for '%s' anywhere in a class or namespace name, so it also matches a name that merely contains it, "
        . "such as App\\%sReports. Compare whole namespace segments: in_array('%s', explode('\\\\', \$name), true). "
        . 'See docs/phpstan-rules/forbid-unanchored-namespace-substring-check.md';

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // File => function key => variable => true, for each variable assigned a class name.
        $classNames = [];
        foreach ($node->get(ClassNameVariableWriteCollector::class) as $file => $writes) {
            foreach ($writes as [$functionKey, $variable]) {
                $classNames[$file][$functionKey][$variable] = true;
            }
        }

        $errors = [];
        foreach ($node->get(NamespaceWordSubstringCallCollector::class) as $file => $calls) {
            foreach ($calls as [$function, $words, $line, $functionKey, $variable]) {
                if (null !== $variable && !isset($classNames[$file][$functionKey][$variable])) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf(self::MESSAGE, $function, implode("', '", $words), $words[0], $words[0]))
                    ->identifier(self::IDENTIFIER)
                    ->file($file)
                    ->line($line)
                    ->build()
                ;
            }
        }

        return $errors;
    }
}
