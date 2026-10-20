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
 * Forbids deciding something from a bare word found anywhere in an absolute path.
 *
 * `stripos($absPathRoot, 'Magento')` holds for every project checked out under a directory whose
 * name contains the word (`/home/ci/magento-tools/my-app/`), and fails for the project it means
 * wherever that is checked out under any other name. The path says where the checkout is, not what
 * the project is: decide that from the project itself, such as its composer.json, or compare a
 * path segment below the project root.
 *
 * Reported: a search for a bare word ({@see PathWordSubstringCheckCollector}) in a haystack known
 * to be a path ({@see AbsolutePathExpression}), or in a local variable every write in the function
 * gives a path. PHPStan nodes carry no parent links, so the searches and the writes are collected
 * per function and matched here.
 *
 * See docs/phpstan-rules/forbid-path-word-substring-check.md.
 *
 * @internal
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ForbidPathWordSubstringCheckRule implements Rule
{
    /** The stable identifier every finding of this rule carries. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.pathWordSubstringCheck';

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
        $errors = [];
        foreach ($node->get(PathWordSubstringCheckCollector::class) as $file => $values) {
            $searches = [];
            $writes   = [];
            foreach ($values as [$function, $fileSearches, $fileWrites]) {
                foreach ($fileSearches as $search) {
                    $searches[] = [$function, ...$search];
                }

                foreach ($fileWrites as [$variable, $isPath]) {
                    $writes[$function][$variable][] = $isPath;
                }
            }

            foreach ($searches as [$function, $variable, $line, $called, $haystack, $word]) {
                if (null !== $variable && !$this->onlyEverAPath(...$writes[$function][$variable] ?? [])) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf(
                    "Word searched for in an absolute path: %s(%s, '%s'). It holds wherever any directory above the "
                    . 'project carries the word, and fails for the same project checked out anywhere else. Decide '
                    . 'from what the project is (its composer.json, a detector), or compare a path segment below the '
                    . 'project root. See docs/phpstan-rules/forbid-path-word-substring-check.md',
                    $called,
                    $haystack,
                    $word,
                ))
                    ->identifier(self::IDENTIFIER)
                    ->file($file)
                    ->line($line)
                    ->build()
                ;
            }
        }

        return $errors;
    }

    /** True when the variable is written at least once and every write gives it a path. */
    private function onlyEverAPath(bool ...$writes): bool
    {
        return [] !== $writes && !\in_array(false, $writes, true);
    }
}
