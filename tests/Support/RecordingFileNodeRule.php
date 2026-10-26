<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use ErrorException;
use LTS\PHPQA\PHPStan\Rules\ForbidHttpPrefixedEnvVarsRule;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Runs ForbidHttpPrefixedEnvVarsRule inside a real analysis and keeps the
 * messages it returned for each analysed file, in order. PHPStan reorders the
 * errors an analysis reports, so the rule's own ordering, and that it reports
 * once per run, can only be read from what the rule itself returned.
 *
 * It reports nothing to PHPStan, so the analysis it runs under expects no errors,
 * and a PHP warning inside the rule fails that analysis.
 *
 * @implements Rule<FileNode>
 */
final class RecordingFileNodeRule implements Rule
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(private readonly ForbidHttpPrefixedEnvVarsRule $inner)
    {
    }

    public function getNodeType(): string
    {
        return $this->inner->getNodeType();
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        // PHPStan's analysis swallows a PHP warning raised inside a rule, so one is made
        // to fail the analysis instead: a rule that scans the project must do so warning-free.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $errors = $this->inner->processNode($node, $scope);
        } finally {
            restore_error_handler();
        }

        $this->calls[] = array_map(static fn (RuleError $error): string => $error->getMessage(), $errors);

        return [];
    }
}
