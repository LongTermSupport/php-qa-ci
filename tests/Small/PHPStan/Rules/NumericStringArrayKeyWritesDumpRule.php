<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\NumericStringArrayKeyWritesCollector;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports each body NumericStringArrayKeyWritesCollector recorded, at the
 * line the body starts, as its key, then `|`, then each write as name@line
 * (name@-1 where PHPStan cannot follow it), so a test can read the records.
 *
 * @internal
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class NumericStringArrayKeyWritesDumpRule implements Rule
{
    /** The identifier of each finding, which only a test reads. */
    public const string IDENTIFIER = 'test.numericStringArrayKeyWrites';

    /** How an untracked write is positioned; it and the other negative markers are shown as they are. */
    private const string UNTRACKED = '-1';

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
        foreach ($node->get(NumericStringArrayKeyWritesCollector::class) as $file => $records) {
            $source = \Safe\file_get_contents($file);
            foreach ($records as $record) {
                $writes   = array_map(fn (string $write): string => $this->withLine($write, $source), $record['uses']);
                $errors[] = RuleErrorBuilder::message($record['key'] . '|' . implode(',', $writes))
                    ->identifier(self::IDENTIFIER)
                    ->file($file)
                    ->line($record['line'])
                    ->build()
                ;
            }
        }

        return $errors;
    }

    private function withLine(string $write, string $source): string
    {
        [$name, $position] = explode('@', $write, 2) + [1 => self::UNTRACKED];
        if ((int)$position < 0) {
            return $write;
        }

        return $name . '@' . (substr_count(substr($source, 0, (int)$position), "\n") + 1);
    }
}
