<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\PHPStan\Rules\ProcessSignalHandlingCollector;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports each entry ProcessSignalHandlingCollector recorded, at the line it
 * was recorded for, so a test can pin what the collector saw: "call <method>"
 * for a wait, "guard, <n> excluded" for a try that catches the signal.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class CollectedProcessSignalEntriesRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.collectedProcessSignalEntry';

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
        foreach ($node->get(ProcessSignalHandlingCollector::class) as $file => $entries) {
            foreach ($entries as [$kind, $method, $line, , , $excluded]) {
                $message  = ProcessSignalHandlingCollector::CALL === $kind ? 'call ' . $method : \sprintf('guard, %d excluded', \count($excluded));
                $errors[] = RuleErrorBuilder::message($message)->file($file)->line($line)->identifier(self::IDENTIFIER)->build();
            }
        }

        return $errors;
    }
}
