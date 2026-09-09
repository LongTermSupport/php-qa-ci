<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans PHP functions that execute code, run shell commands or disclose source.
 *
 * These are OWASP A03 risks with no legitimate place in application source:
 * run shell commands through Symfony Process, serialise through JSON.
 *
 * Part of the list below is lifted from spaze/phpstan-disallowed-calls (MIT,
 * Copyright (c) 2018 Michal Špaček), whose execution-calls and dangerous-calls
 * bundles are the reference for this class. The list is carried here rather
 * than the engine imported, so that one rule owns the convention and the two
 * cannot drift; each lifted entry is marked.
 *
 * See: docs/phpstan-rules/forbid-dangerous-functions.md for fix documentation.
 *
 * @implements Rule<FuncCall>
 */
final readonly class ForbidDangerousFunctionsRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.dangerousFunctions';

    /**
     * Function name to the reason it is banned, which becomes the message.
     * Entries marked "spaze" are lifted from the MIT-licensed bundles named in
     * the class docblock.
     *
     * @var array<string, string>
     */
    private const array BANNED_FUNCTIONS = [
        'exec'            => 'runs a shell command; use Symfony Process',
        'shell_exec'      => 'runs a shell command, as does the backtick operator; use Symfony Process',
        'system'          => 'runs a shell command; use Symfony Process',
        'passthru'        => 'runs a shell command; use Symfony Process',
        'proc_open'       => 'runs a shell command; use Symfony Process',
        'popen'           => 'runs a shell command; use Symfony Process',
        'pcntl_exec'      => 'replaces the running process with a program; use Symfony Process (spaze)',
        'eval'            => 'executes a string as code, and there is always another way',
        'unserialize'     => 'deserialising untrusted data can execute arbitrary code; use json_decode',
        'extract'         => 'creates variables from array keys, so the data chooses the variable names',
        'parse_str'       => 'without a second argument it writes straight into local scope',
        'create_function' => 'evaluates a string as a function body; removed in PHP 8.0 (spaze)',
        'dl'              => 'loads a shared extension at run time, letting untrusted code into the process (spaze)',
        'highlight_file'  => 'renders source, disclosing code and configuration (spaze)',
        'show_source'     => 'renders source, disclosing code and configuration (spaze)',
        'phpinfo'         => 'prints the environment including cookies and session ids (spaze)',
    ];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toLowerString();
        $reason       = self::BANNED_FUNCTIONS[$functionName] ?? null;
        if (null === $reason) {
            return [];
        }

        // parse_str is only dangerous without the second argument (output variable)
        if ('parse_str' === $functionName && \count($node->args) >= 2) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                \sprintf(
                    '%s() is banned: %s. See docs/phpstan-rules/forbid-dangerous-functions.md.',
                    $functionName,
                    $reason,
                ),
            )->identifier(self::IDENTIFIER)->build(),
        ];
    }
}
