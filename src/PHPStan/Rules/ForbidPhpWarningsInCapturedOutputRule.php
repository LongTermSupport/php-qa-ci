<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A PHP child process whose output is captured runs with its warnings sent to stderr.
 *
 * The PHP CLI displays errors unless an ini says otherwise, and it displays them on stdout. A
 * warning or deprecation the child raises, from its own code, a dependency or the host's
 * configuration, then becomes part of the value the caller captured: a version, a JSON report, the
 * line a message relays. `-d display_errors=stderr` keeps them visible and out of the value.
 *
 * CapturedPhpOutputCollector finds the captured calls and every write to a local variable. A call
 * whose command is read in place is reported when it runs PHP and does not route the warnings; a
 * call given a variable is judged by the last write to that variable before it in the same
 * function.
 *
 * See: docs/phpstan-rules/forbid-php-warnings-in-captured-output.md.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ForbidPhpWarningsInCapturedOutputRule implements Rule
{
    /** The identifier every finding carries, resolved by rule-doc. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpWarningsInCapturedOutput';

    /** The message every finding carries. */
    public const string MESSAGE = 'PHP runs here with its output captured, but nothing keeps its warnings out of it: the PHP CLI displays errors on stdout unless an ini says otherwise, so a warning or deprecation becomes part of the captured value. '
        . 'Pass -d display_errors=stderr to php, before the script. See docs/phpstan-rules/forbid-php-warnings-in-captured-output.md.';

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
        foreach ($node->get(CapturedPhpOutputCollector::class) as $file => $collected) {
            $records = array_merge(...$collected);

            // Function key => variable => [line, verdict] of each write to it.
            $writes = [];
            foreach ($records as [$kind, $line, $functionKey, $variable, $verdict]) {
                if (CapturedPhpOutputCollector::WRITE === $kind) {
                    $writes[$functionKey][$variable][] = [$line, $verdict];
                }
            }

            foreach ($records as [$kind, $line, $functionKey, $variable, $verdict]) {
                if (CapturedPhpOutputCollector::CALL !== $kind) {
                    continue;
                }

                $judged = '' === $variable ? $verdict : $this->lastWrite($line, ...$writes[$functionKey][$variable] ?? []);
                if (false === $judged) {
                    $errors[] = RuleErrorBuilder::message(self::MESSAGE)
                        ->identifier(self::IDENTIFIER)
                        ->file($file)
                        ->line($line)
                        ->build()
                    ;
                }
            }
        }

        return $errors;
    }

    /**
     * What the last write at or before the call's line says about the command; null when there is none.
     *
     * @param array{int, bool|null} ...$writes
     */
    private function lastWrite(int $callLine, array ...$writes): ?bool
    {
        $last = null;
        foreach ($writes as [$line, $verdict]) {
            if ($line <= $callLine && (null === $last || $line >= $last[0])) {
                $last = [$line, $verdict];
            }
        }

        return $last[1] ?? null;
    }
}
