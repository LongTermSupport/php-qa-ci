<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\Dto\OpcacheSettingsDto;

/**
 * What php-qa-ci knows about OPcache defects that produce crashing or wrong
 * bytecode: the affected PHP ranges, the settings that avoid them, and the
 * start-up advisory for a host still exposed. The `opcache` lane checks real
 * code against them; this class holds the knowledge, so a further defect of
 * the same kind is a change here and an assertion there, not a new tool.
 *
 * Known defect: a comparison opcode reaches the VM with both operands constant.
 * The DFA pass (bit 0x20 of `opcache.optimization_level`, which carries SCCP)
 * is how one gets there — it substitutes a variable it has proved constant into
 * a later comparison with a literal and does not fold the result — but upstream
 * has ruled that the missed fold is not the defect. The defect is that
 * ZEND_IS_IDENTICAL_EMPTY_ARRAY and ZEND_IS_NOT_IDENTICAL_EMPTY_ARRAY were the
 * only handlers in their group declared without NO_CONST_CONST, so the
 * specialiser picks a variant that reads op1 as a variable slot: a segfault in
 * zval_undefined_cv when the slot is unmapped, a silent garbage comparison
 * otherwise.
 *
 * Reported as https://github.com/php/php-src/issues/23644 (Status: Verified);
 * fix in https://github.com/php/php-src/pull/23648, based at PHP-8.5. Set
 * FIRST_FIXED below to the release that carries it.
 *
 * Clearing the DFA bit remains the host-side mitigation, because it removes the
 * only route we have seen to that operand pair — but it treats the symptom, and
 * a host on a fixed PHP needs neither.
 *
 * @api
 */
final readonly class OpcacheDefects
{
    /** The lane's stable identifier, printed on failure and by the advisory. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.opcache';

    /** Bit 0x20 of the mask: pass 6, data-flow analysis, which holds SCCP. */
    public const int DFA_PASS = 0x20;

    /**
     * PHP's own default mask, what an unconfigured host compiles with. Note it
     * is not "every bit set": PHP ships passes 0x4000 and 0x10000 off, so a
     * mask invented by setting every bit would enable passes production never
     * runs. The lane compiles with exactly this value.
     */
    public const int DEFAULT_LEVEL = 0x7FFEBFFF;

    /** The default with the DFA pass cleared: everything else, cache included, stays on. */
    public const int RECOMMENDED_LEVEL = self::DEFAULT_LEVEL & ~self::DFA_PASS;

    /**
     * 8.4 and earlier are unaffected: the empty-array comparison optimisation
     * the defect lives in arrives in 8.5, per the maintainer's verdict on
     * GH-23644 and the fix PR's PHP-8.5 base.
     */
    private const string FIRST_AFFECTED = '8.5.0';

    /**
     * No fixing release is known. Set this to the first fixed version when
     * upstream ships one; until then the range is open-ended.
     */
    private const string FIRST_FIXED = '99.0.0';

    public static function affects(string $phpVersion): bool
    {
        return version_compare($phpVersion, self::FIRST_AFFECTED, '>=') && version_compare($phpVersion, self::FIRST_FIXED, '<');
    }

    /** `opcache.optimization_level` as the ini holds it: hex, decimal, or empty for PHP's default. */
    public static function parseLevel(string $ini): int
    {
        $ini = trim($ini);
        if ('' === $ini) {
            return self::DEFAULT_LEVEL;
        }

        if (str_starts_with(strtolower($ini), '0x')) {
            return (int)hexdec(substr($ini, 2));
        }

        return (int)$ini;
    }

    public static function dfaPassEnabled(int $level): bool
    {
        return 0 !== ($level & self::DFA_PASS);
    }

    /**
     * The mask to recommend to a host currently running $level: its own mask
     * with the DFA pass cleared, never the shipped default with it cleared. A
     * host that has deliberately turned other passes off must not be told to
     * turn them back on as the price of dropping this one.
     */
    public static function recommendedFor(int $level): int
    {
        return $level & ~self::DFA_PASS;
    }

    /**
     * The preflight advisory for a host that can still compile a known defect:
     * empty when the PHP is outside the affected range, OPcache is not loaded,
     * or the DFA pass is already off.
     *
     * @return list<string>
     */
    public static function advisory(string $phpVersion, OpcacheSettingsDto $settings): array
    {
        $level = self::parseLevel($settings->optimizationLevel);
        if (!self::affects($phpVersion) || !$settings->loaded || !self::dfaPassEnabled($level)) {
            return [];
        }

        return [
            \sprintf('WARNING: PHP %s runs the OPcache DFA optimizer pass (opcache.optimization_level=0x%X).', $phpVersion, $level),
            '  That pass can leave a constant-vs-constant comparison unfolded, which the VM has no',
            '  handler for: a segfault, or a silent garbage comparison, wherever the shape occurs.',
            '  Recommended in php.ini for every SAPI on this host until upstream fixes it:',
            \sprintf('    opcache.optimization_level=0x%X', self::recommendedFor($level)),
            \sprintf('  Detail: vendor/bin/rule-doc %s', self::IDENTIFIER),
        ];
    }
}
