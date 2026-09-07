<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\ActiveRulesLister;

use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Node\Printer\Printer;
use PhpParser\Node;

/**
 * A project-authored PHPStan rule with no IDENTIFIER constant, used to prove
 * that ActiveRulesLister still lists a rule class that carries none.
 *
 * @implements Rule<Node>
 */
final readonly class FixtureProjectRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return [];
    }
}
