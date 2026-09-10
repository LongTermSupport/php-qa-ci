<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A path built from ProjectPathsDto::$binDir names a tool the pipeline runs
 * from the consumer's Composer bin directory, which means the tool is a
 * Composer dependency of php-qa-ci and its whole dependency graph lands in
 * every consumer. Tools ship as PHARs under vendor-phar/ and run from
 * $paths->pharDir. PHPUnit (and its paratest runner) is the one exception:
 * the consumer's tests autoload its classes, so it has to be in the graph.
 *
 * WRONG:
 *
 *   $paths->binDir . '/parallel-lint'
 *
 * RIGHT:
 *
 *   $paths->pharDir . '/parallel-lint.phar'
 *
 * See: docs/phpstan-rules/forbid-bin-dir-tool.md
 *
 * @implements Rule<Concat>
 */
final readonly class ForbidBinDirToolRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.binDirTool';

    private const string BIN_DIR = 'binDir';

    private const array ALLOWED_TOOLS = ['phpunit', 'paratest'];

    private const string ADVICE = ' is run from the Composer bin dir. Ship it as a PHAR under vendor-phar/ and run $paths->pharDir instead: a Composer-installed CLI tool drags its dependency graph into every consumer.';

    public function getNodeType(): string
    {
        return Concat::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $operands = $this->flatten($node);
        $first    = array_shift($operands);
        if (!$first instanceof PropertyFetch || !$this->isProjectPathsBinDir($first, $scope)) {
            return [];
        }

        $tool = $this->toolName($scope, ...$operands);
        if (null === $tool) {
            return $this->error('A tool named at run time');
        }

        if ('' === $tool || \in_array($tool, self::ALLOWED_TOOLS, true)) {
            return [];
        }

        return $this->error('Tool ' . $tool);
    }

    /**
     * Left-associative concatenation, so `$a . $b . $c` is Concat(Concat($a, $b), $c):
     * walk down the left spine to list the operands in source order.
     *
     * @return list<Expr>
     */
    private function flatten(Concat $node): array
    {
        $operands = [$node->right];
        $left     = $node->left;
        while ($left instanceof Concat) {
            array_unshift($operands, $left->right);
            $left = $left->left;
        }

        array_unshift($operands, $left);

        return $operands;
    }

    private function isProjectPathsBinDir(PropertyFetch $fetch, Scope $scope): bool
    {
        if (!$fetch->name instanceof Identifier || self::BIN_DIR !== $fetch->name->toString()) {
            return false;
        }

        return \in_array(ProjectPathsDto::class, $scope->getType($fetch->var)->getObjectClassNames(), true);
    }

    /**
     * The first path segment after binDir, or null when any operand is not a
     * compile-time string, so the tool cannot be named statically.
     */
    private function toolName(Scope $scope, Expr ...$operands): ?string
    {
        $tail = '';
        foreach ($operands as $operand) {
            $strings = $scope->getType($operand)->getConstantStrings();
            if (1 !== \count($strings)) {
                return null;
            }

            $tail .= $strings[0]->getValue();
        }

        $segments = explode('/', ltrim($tail, '/'));

        return $segments[0];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function error(string $subject): array
    {
        return [
            RuleErrorBuilder::message($subject . self::ADVICE)
                ->identifier(self::IDENTIFIER)
                ->build(),
        ];
    }
}
