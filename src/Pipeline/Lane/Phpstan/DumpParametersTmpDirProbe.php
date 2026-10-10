<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * Asks PHPStan for the `tmpDir` it merges from a config, with
 * `phpstan.phar dump-parameters --json`, so an include no NEON walk can
 * follow (a `%parameter%` path, a PHP config file) is resolved by PHPStan's
 * own loader. PHPStan's default is `%sysGetTempDir%/phpstan`; any other value
 * is the project's. The default is read from the same dump's `sysGetTempDir`,
 * which is the child's own `sys_get_temp_dir()`, so the comparison never
 * depends on this process's environment.
 *
 * The dump compiles PHPStan's container into `tmpDir`, and with the default
 * that is the system temp directory every checkout on the host shares (#123).
 * The child's TMPDIR is therefore a directory of the probe's own under the QA
 * cache. Its `sysGetTempDir` moves with it, so the default is still
 * recognised; a project `tmpDir` built from `%sysGetTempDir%` resolves under
 * the probe's directory instead, and is still not the default.
 *
 * A dump that fails, or prints no usable JSON, is no answer: the lane's
 * analyse run reports a broken config itself.
 *
 * @internal
 */
final readonly class DumpParametersTmpDirProbe implements TmpDirProbeInterface
{
    public const string TEMP_DIR = 'phpstan-dump-parameters';

    private const string DEFAULT_TMP_DIR = '/phpstan';

    public function projectTmpDir(ToolContext $context, string $config, ?string $autoloadFile): ?string
    {
        $paths = $context->config->paths;
        $temp  = $paths->cacheDir . '/' . self::TEMP_DIR;
        if (!is_dir($temp)) {
            \Safe\mkdir($temp, 0o777, true);
        }

        $args = ['dump-parameters', '-c', $config, '--json'];
        if (null !== $autoloadFile) {
            $args[] = '--autoload-file';
            $args[] = $autoloadFile;
        }

        $result = $context->php->withoutXdebug($paths->pharDir . '/phpstan.phar', $args, $paths->projectRoot, ['TMPDIR' => $temp], streamOutput: false);
        if (!$result->succeeded() || !json_validate($result->stdout)) {
            return null;
        }

        $parameters = \Safe\json_decode($result->stdout, true);
        $tmpDir     = \is_array($parameters) ? ($parameters['tmpDir'] ?? null) : null;
        $systemTemp = \is_array($parameters) ? ($parameters['sysGetTempDir'] ?? null) : null;
        if (!\is_string($tmpDir) || !\is_string($systemTemp) || $systemTemp . self::DEFAULT_TMP_DIR === $tmpDir) {
            return null;
        }

        return $tmpDir;
    }
}
