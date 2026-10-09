<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config;

/**
 * The start-up note for a host that loads Xdebug while OPcache's JIT is
 * configured on. PHP then prints "JIT is incompatible with third party
 * extensions" on stdout at every start of a process that has Xdebug active,
 * which corrupts anything that parses a child's stdout (PHPStan's JSON, a git
 * hook's `php -r` output). The pipeline starts every non-coverage child with
 * `XDEBUG_MODE=off`, which avoids it; a PHP started by hand does not.
 *
 * @internal
 */
final readonly class XdebugJitAdvisory
{
    private const array JIT_OFF = ['', '0', 'off', 'false', 'no', 'disable'];

    /**
     * @param string $jitProbe the probe answer: `opcache.enable_cli|opcache.jit|opcache.jit_buffer_size`
     *
     * @return list<string>
     */
    public static function lines(bool $xdebugLoaded, string $jitProbe): array
    {
        if (!$xdebugLoaded || !self::jitConfigured($jitProbe)) {
            return [];
        }

        return [
            'WARNING: Xdebug is loaded and OPcache JIT is configured on (opcache.jit, opcache.jit_buffer_size).',
            '  PHP prints "JIT is incompatible with third party extensions" on stdout at every start',
            '  of a process with Xdebug active, which breaks anything that parses its output.',
            '  This run starts every non-coverage tool with XDEBUG_MODE=off, so it is unaffected;',
            '  a PHP you start by hand needs XDEBUG_MODE=off in its environment to stay quiet.',
        ];
    }

    private static function jitConfigured(string $jitProbe): bool
    {
        $fields = explode('|', trim($jitProbe));
        if (3 !== \count($fields)) {
            return false;
        }

        [$enableCli, $jit, $buffer] = $fields;

        return '1' === $enableCli
            && !\in_array(strtolower($jit), self::JIT_OFF, true)
            && '0' !== $buffer
            && ''  !== $buffer;
    }
}
