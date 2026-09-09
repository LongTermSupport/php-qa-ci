<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans functions that are not dangerous in themselves but are the wrong tool
 * whenever security depends on them: broken hashes, predictable randomness,
 * and the unparameterised MySQL query functions.
 *
 * Separate from ForbidDangerousFunctionsRule because the hazard is different.
 * Nothing here executes code; each is simply too weak for the job it usually
 * gets used for, which is why this rule is opt-in — a project hashing for
 * cache keys rather than for secrets is not doing anything wrong.
 *
 * The list is lifted from spaze/phpstan-disallowed-calls (MIT, Copyright (c)
 * 2018 Michal Špaček), specifically its insecure-calls bundle. Carried here
 * rather than importing the engine, so one rule owns the convention and the
 * two cannot drift.
 *
 * See: docs/phpstan-rules/forbid-insecure-functions.md.
 *
 * @implements Rule<FuncCall>
 */
final readonly class ForbidInsecureFunctionsRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.insecureFunction';

    private const string WEAK_HASH_ADVICE = 'use hash() with SHA-256 or better, or password_hash() for passwords';

    private const string WEAK_RANDOM_ADVICE = 'not a cryptographically secure generator; use random_int() or random_bytes()';

    private const string SQL_ADVICE = 'use a prepared statement with bound parameters';

    /**
     * Function name to the reason it is banned.
     *
     * @var array<string, string>
     */
    private const array BANNED_FUNCTIONS = [
        'md5'                     => self::WEAK_HASH_ADVICE,
        'sha1'                    => self::WEAK_HASH_ADVICE,
        'md5_file'                => self::WEAK_HASH_ADVICE,
        'sha1_file'               => self::WEAK_HASH_ADVICE,
        'rand'                    => self::WEAK_RANDOM_ADVICE,
        'mt_rand'                 => self::WEAK_RANDOM_ADVICE,
        'lcg_value'               => self::WEAK_RANDOM_ADVICE,
        'uniqid'                  => self::WEAK_RANDOM_ADVICE,
        'mysql_query'             => self::SQL_ADVICE,
        'mysql_unbuffered_query'  => self::SQL_ADVICE,
        'mysqli_query'            => self::SQL_ADVICE,
        'mysqli_multi_query'      => self::SQL_ADVICE,
        'mysqli_real_query'       => self::SQL_ADVICE,
    ];

    /**
     * These take the algorithm as their first argument, so only the weak
     * choices are reportable. Passing a variable is not reported: the value is
     * unknown here, and guessing would report code that may be correct.
     *
     * @var list<string>
     */
    private const array ALGORITHM_FUNCTIONS = ['hash', 'hash_file', 'hash_init'];

    /** @var list<string> */
    private const array WEAK_ALGORITHMS = ['md5', 'sha1'];

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

        if (\in_array($functionName, self::ALGORITHM_FUNCTIONS, true)) {
            return $this->checkAlgorithm($node, $functionName);
        }

        $reason = self::BANNED_FUNCTIONS[$functionName] ?? null;

        return null === $reason ? [] : [$this->error($functionName, $reason)];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkAlgorithm(FuncCall $node, string $functionName): array
    {
        $first = $node->args[0] ?? null;
        if (!$first instanceof Node\Arg || !$first->value instanceof String_) {
            return [];
        }

        if (!\in_array(strtolower($first->value->value), self::WEAK_ALGORITHMS, true)) {
            return [];
        }

        return [$this->error($functionName, self::WEAK_HASH_ADVICE)];
    }

    private function error(string $functionName, string $reason): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            \sprintf(
                '%s() is banned: %s. See docs/phpstan-rules/forbid-insecure-functions.md.',
                $functionName,
                $reason,
            ),
        )->identifier(self::IDENTIFIER)->build();
    }
}
