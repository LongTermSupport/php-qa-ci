<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\PHPStan\Rules\ExitCodeTextCollector;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports each entry ExitCodeTextCollector recorded, at the line it was
 * recorded for, so a test can pin what the collector saw: "<kind> <class
 * short name>::<function>" followed by " $<variable>" for an entry naming a variable.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class CollectedExitCodeTextEntriesRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.collectedExitCodeTextEntry';

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($node->get(ExitCodeTextCollector::class) as $file => $entries) {
            foreach ($entries as $entry) {
                foreach ($entry as [$kind, $key, $variable, $line]) {
                    $message  = $kind . ' ' . $this->shortKey($key) . ('' === $variable ? '' : ' $' . $variable);
                    $errors[] = RuleErrorBuilder::message($message)->file($file)->line($line)->identifier(self::IDENTIFIER)->build();
                }
            }
        }

        return $errors;
    }

    /** The function key without the class's namespace: "Reports::handFormatted". */
    private function shortKey(string $key): string
    {
        $separator = strrpos($key, '\\');

        return false === $separator ? $key : substr($key, $separator + 1);
    }
}
