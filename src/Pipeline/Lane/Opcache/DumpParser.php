<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Opcache;

use LTS\PHPQA\Pipeline\Lane\Opcache\Dto\ConstComparisonDto;

/**
 * Reads the after-optimizer dump `opcache.opt_debug_level=0x20000` prints
 * (one block per op_array: a `name:` line, `; file:start-end`, then one line
 * per opcode) and picks out every comparison whose two operands are both
 * literals. The compiler folds those itself, so one surviving to the dump is
 * the optimizer's doing, and the VM has no handler for it.
 *
 * @internal
 */
final readonly class DumpParser
{
    private const string OPERAND = '(?:null|true|false|int\(-?\d+\)|float\([^)]*\)|string\(".*?"\)|array\(\.\.\.\))';

    private const string COMPARISON = '/^\d{4}\s+(?:[TV]\d+ = )?((?:IS_IDENTICAL|IS_NOT_IDENTICAL|IS_EQUAL|IS_NOT_EQUAL|IS_SMALLER|IS_SMALLER_OR_EQUAL|CASE|CASE_STRICT) ' . self::OPERAND . ' ' . self::OPERAND . ')$/';

    private const string HEADER = '/^(\S.*):$/';

    private const string LOCATION = '/^\s+; (.+):(\d+)-(\d+)$/';

    /**
     * Every file the dump carries an op_array for. A file OPcache declined to
     * cache (file_update_protection on a just-written file, a blacklist, a full
     * SHM) is compiled but never dumped, and the lane must not read its absence
     * as a clean result.
     *
     * @return list<string>
     */
    public function filesDumped(string $dump): array
    {
        $files = [];
        foreach (explode("\n", $dump) as $line) {
            if (1 === \Safe\preg_match(self::LOCATION, $line, $location) && isset($location[1])) {
                $files[$location[1]] = true;
            }
        }

        return array_keys($files);
    }

    /** @return list<ConstComparisonDto> in dump order */
    public function parse(string $dump): array
    {
        $findings  = [];
        $function  = '';
        $file      = '';
        $lineStart = 0;
        $lineEnd   = 0;
        foreach (explode("\n", $dump) as $line) {
            if (1 === \Safe\preg_match(self::HEADER, $line, $header) && isset($header[1])) {
                $function = $header[1];

                continue;
            }

            if (1 === \Safe\preg_match(self::LOCATION, $line, $location) && isset($location[1], $location[2], $location[3])) {
                $file      = $location[1];
                $lineStart = (int)$location[2];
                $lineEnd   = (int)$location[3];

                continue;
            }

            if (1 === \Safe\preg_match(self::COMPARISON, $line, $comparison) && isset($comparison[1])) {
                $findings[] = new ConstComparisonDto($file, $function, $lineStart, $lineEnd, $comparison[1]);
            }
        }

        return $findings;
    }
}
