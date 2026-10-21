<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use FilesystemIterator;
use LTS\PHPQA\PHPStan\InstalledPhpstanExtensions;
use LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Everything the answers of `phpstan.phar dump-parameters` and `phpstan.phar diagnose` depend on,
 * as one digest per question, so PhpstanSetupCache can tell an answer still current from a stale
 * one without asking PHPStan again (#219).
 *
 * Both keys cover the phar's bytes and every NEON file PHPStan merges: the chain from the entry
 * file through `includes:` (NeonIncludeChain) and the configs phpstan/extension-installer adds.
 * The dump key adds the `--autoload-file` it passes and the temp directory it runs under. The
 * diagnose key adds the lane's wrapper neon and everything Turbo's verdict rests on (#92, #98):
 * the files under the phar's `turbo-ext/` (the per-PHP binary and its core, each by content), the
 * committed manifest, and the PHP that runs PHPStan, asked about itself in one short process
 * (RUNTIME_PROBE): its binary (by content), version, thread safety, debug build, platform, the
 * ini files it loads (by content), whether Turbo is already loaded and at what version, whether
 * it can restart itself, and the environment that moves its ini or stops the restart.
 *
 * Content is hashed rather than size and mtime read: a binary replaced in place within one second
 * keeps both, and xxh128 reads the 31 MB phar in about 15 ms. Where the key cannot see an input,
 * it is null and the question is asked: a `%env.X%` value, a PHP config file (PHPStan runs it; the
 * walk does not), an include the walk cannot follow, a file it cannot read, or a runtime that
 * does not answer. A read that fails part-way throws, and PhpstanSetupCache treats that the same.
 *
 * @internal
 */
final readonly class PhpstanSetupKey
{
    /**
     * Run as `php -r` by the PHP that runs PHPStan. Every field is something phpstan.phar's
     * TurboExtensionSelector, TurboProcessRestarter or TurboExtensionEnabler reads.
     */
    public const string RUNTIME_PROBE = 'echo json_encode(["binary"=>PHP_BINARY,"version"=>PHP_VERSION,"zts"=>PHP_ZTS,"debug"=>PHP_DEBUG,"os"=>PHP_OS_FAMILY,'
        . '"machine"=>php_uname("m"),"maps"=>is_readable("/proc/self/maps")&&str_contains((string)file_get_contents("/proc/self/maps"),"/ld-musl-"),'
        . '"alpine"=>is_file("/etc/alpine-release"),"ini"=>php_ini_loaded_file(),"scanned"=>php_ini_scanned_files(),"turbo"=>phpversion("phpstan_turbo"),'
        . '"pcntl"=>function_exists("pcntl_exec")&&function_exists("pcntl_fork"),"opcache"=>extension_loaded("Zend OPcache"),'
        . '"env"=>[getenv("PHPRC"),getenv("PHP_INI_SCAN_DIR"),isset($_SERVER["BLACKFIRE_AGENT_SOCKET"])]]);';

    /** Bumped whenever what a key covers changes, so an entry written by an older key is a miss. */
    private const int SCHEMA = 1;

    /** Fast, and only ever compared with itself: a change detector, not a signature. */
    private const string HASH = 'xxh128';

    /** The shipped PHPStan, under the phar directory. */
    private const string PHAR = '/phpstan.phar';

    /** Where phpstan.phar looks for Turbo's binaries, beside itself. */
    private const string TURBO_DIR = '/turbo-ext';

    /** The committed manifest of Turbo builds, under the phar directory. */
    private const string MANIFEST = '/turbo-ext.json';

    /** How a NEON value read from the environment begins. */
    private const string ENV_PARAMETER = '%env.';

    /** RUNTIME_PROBE's field naming the php.ini loaded, or false. */
    private const string INI = 'ini';

    /** RUNTIME_PROBE's field listing the further ini files scanned, comma-separated, or false. */
    private const string SCANNED = 'scanned';

    /** RUNTIME_PROBE's field naming the PHP binary. */
    private const string BINARY = 'binary';

    /** Seconds the runtime may take to answer before the key is given up on. */
    private const float RUNTIME_TIMEOUT = 30.0;

    public function forDumpParameters(ToolContext $context, string $config, ?string $autoloadFile): ?string
    {
        $parts = $this->shared($context, $config);
        if (null === $parts) {
            return null;
        }

        if (null !== $autoloadFile) {
            $autoload = $this->fileHash($autoloadFile);
            if (null === $autoload) {
                return null;
            }

            $parts['autoload'] = [$autoloadFile, $autoload];
        }

        $parts['temp'] = $context->config->paths->cacheDir . '/' . DumpParametersTmpDirProbe::TEMP_DIR;

        return $this->digest('dump-parameters', $parts);
    }

    public function forDiagnose(ToolContext $context, string $wrapperNeon): ?string
    {
        $parts = $this->shared($context, $wrapperNeon);
        if (null === $parts) {
            return null;
        }

        $runtime = $this->runtime($context);
        if (null === $runtime) {
            return null;
        }

        $pharDir              = $context->config->paths->pharDir;
        $parts['runtime']     = $runtime;
        $parts['turbo']       = $this->turboFiles($pharDir . self::TURBO_DIR);
        $parts['manifest']    = is_file($pharDir . self::MANIFEST) ? $this->fileHash($pharDir . self::MANIFEST) : null;
        $parts['memoryLimit'] = $context->config->memoryLimit;

        return $this->digest('diagnose', $parts);
    }

    /**
     * What both answers rest on: where PHPStan runs, the phar, and the merged configuration.
     *
     * @return array<string, mixed>|null
     */
    private function shared(ToolContext $context, string $entryConfig): ?array
    {
        $paths = $context->config->paths;
        $phar  = $this->fileHash($paths->pharDir . self::PHAR);
        if (null === $phar) {
            return null;
        }

        $configs = $this->configs($entryConfig, $paths->projectRoot);
        if (null === $configs) {
            return null;
        }

        return ['root' => $paths->projectRoot, 'php' => $context->config->phpBinPath, 'phar' => $phar, 'entry' => $entryConfig, 'configs' => $configs];
    }

    /**
     * Every NEON file PHPStan merges, path to content hash; null when one of them reads the
     * environment, or the chain reaches something the walk cannot read.
     *
     * @return array<string, string>|null
     */
    private function configs(string $entryConfig, string $projectRoot): ?array
    {
        if (!is_file($entryConfig)) {
            return null;
        }

        $entries = [$entryConfig];
        foreach (new InstalledPhpstanExtensions()->includes($projectRoot) as $includes) {
            $entries = [...$entries, ...$includes];
        }

        $hashes = [];
        $chain  = new NeonIncludeChain();
        foreach ($entries as $entry) {
            $resolved = $chain->resolve($entry, $projectRoot);
            if ([] !== $resolved->problems || [] !== $resolved->phpIncludes) {
                return null;
            }

            foreach ($resolved->files as $file) {
                if (str_contains($file->neon, self::ENV_PARAMETER)) {
                    return null;
                }

                $hashes[$file->path] = hash(self::HASH, $file->neon);
            }
        }

        return $hashes;
    }

    /**
     * What the PHP that runs PHPStan says about itself, with the binary and every ini file it
     * loads hashed; null when it does not answer, or names a file this process cannot read.
     *
     * @return array<string, mixed>|null
     */
    private function runtime(ToolContext $context): ?array
    {
        $config = $context->config;
        $result = $context->processes->run(new ProcessSpecDto(
            // A warning displayed on stdout would make the answer unreadable JSON, and the run uncacheable.
            [$config->phpBinPath, '-d', 'display_errors=stderr', '-r', self::RUNTIME_PROBE],
            $config->paths->projectRoot,
            ['XDEBUG_MODE' => 'off'],
            self::RUNTIME_TIMEOUT,
            streamOutput: false,
        ));
        if (!$result->succeeded() || !json_validate($result->stdout)) {
            return null;
        }

        $runtime = \Safe\json_decode($result->stdout, true);
        if (!\is_array($runtime) || !\is_string($runtime[self::BINARY] ?? null)) {
            return null;
        }

        $files = [$runtime[self::BINARY]];
        foreach ([self::INI, self::SCANNED] as $field) {
            $value = $runtime[$field] ?? null;
            if (false === $value) {
                continue;
            }

            if (!\is_string($value)) {
                return null;
            }

            $files = [...$files, ...array_filter(array_map(trim(...), explode(',', $value)), static fn (string $file): bool => '' !== $file)];
        }

        $hashes = [];
        foreach ($files as $file) {
            $hash = $this->fileHash($file);
            if (null === $hash) {
                return null;
            }

            $hashes[$file] = $hash;
        }

        return ['reported' => $runtime, 'files' => $hashes];
    }

    /**
     * Every file under the phar's `turbo-ext/`, path to content hash. Only the host's own
     * binaries are installed there, a few MB.
     *
     * @return array<string, string|null>
     */
    private function turboFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), \strlen($directory))] = $this->fileHash($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    private function fileHash(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        return \Safe\hash_file(self::HASH, $path);
    }

    /** @param array<string, mixed> $parts */
    private function digest(string $question, array $parts): string
    {
        return hash(self::HASH, \Safe\json_encode([self::SCHEMA, $question, $parts]));
    }
}
