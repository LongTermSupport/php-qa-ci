<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

/**
 * The axes that pick PHPStan's Turbo binary for a host: operating system, CPU, C library, PHP
 * minor version and thread safety. It names the paths under `turbo-ext/` where phpstan.phar looks
 * for the host's binary and its core, as upstream spells them. A host upstream builds nothing for (Windows, an Intel Mac, an unknown CPU or libc) is
 * unsupported: PHPStan runs there without Turbo.
 *
 * @internal
 */
final readonly class TurboPlatform
{
    /** PHP_OS_FAMILY on Linux. */
    private const string LINUX = 'Linux';

    /** PHP_OS_FAMILY on macOS. */
    private const string DARWIN = 'Darwin';

    /** Upstream's name for 64-bit Intel and AMD CPUs. */
    private const string X86_64 = 'x86_64';

    /** Upstream's name for 64-bit ARM CPUs. */
    private const string ARM64 = 'arm64';

    /** CPU names as `php_uname('m')` reports them, mapped to upstream's. */
    private const array MACHINES = ['x86_64' => self::X86_64, 'amd64' => self::X86_64, 'aarch64' => self::ARM64, 'arm64' => self::ARM64];

    /** The libc spellings PHPStan uses in the directory name. */
    private const array LINUX_LIBCS = ['gnu', 'musl'];

    /** The core every binary of a platform loads, in the binary's own directory. */
    private const string CORE_FILE = 'phpstan_turbo_core.so';

    /** The libraries mapped into this process, its loader among them. */
    private const string SELF_MAPS = '/proc/self/maps';

    /** How musl's loader appears in the maps; glibc's is ld-linux. */
    private const string MUSL_LOADER = '/ld-musl-';

    /** Present on Alpine, whose PHP packages are built against musl. */
    private const string ALPINE_RELEASE = '/etc/alpine-release';

    /**
     * @param string $os       PHP_OS_FAMILY
     * @param string $machine  php_uname('m')
     * @param string $libc     'gnu' or 'musl' on Linux, '' elsewhere
     * @param string $phpMinor e.g. '8.5'
     */
    public function __construct(
        private string $os,
        private string $machine,
        private string $libc,
        private string $phpMinor,
        private bool $zts,
    ) {
    }

    public static function fromRuntime(): self
    {
        $maps = is_readable(self::SELF_MAPS) ? \Safe\file_get_contents(self::SELF_MAPS) : null;

        return self::fromProcess(\PHP_OS_FAMILY, php_uname('m'), $maps, is_file(self::ALPINE_RELEASE));
    }

    /**
     * The running PHP's platform, its libc decided as phpstan.phar's TurboExtensionSelector
     * decides it: musl when musl's loader is mapped into the process, not when one merely exists
     * on disk (a glibc host with musl-tools has one). Where the maps cannot be read, Alpine is musl.
     *
     * @param string      $os               PHP_OS_FAMILY
     * @param string      $machine          php_uname('m')
     * @param string|null $selfMaps         /proc/self/maps, or null when it cannot be read
     * @param bool        $hasAlpineRelease whether /etc/alpine-release exists
     */
    public static function fromProcess(string $os, string $machine, ?string $selfMaps, bool $hasAlpineRelease): self
    {
        $libc = '';
        if (self::LINUX === $os) {
            $musl = null === $selfMaps ? $hasAlpineRelease : str_contains($selfMaps, self::MUSL_LOADER);
            $libc = $musl ? 'musl' : 'gnu';
        }

        return new self($os, $machine, $libc, \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION, 1 === \PHP_ZTS);
    }

    /** Where phpstan.phar looks for the platform's shared core, relative to `turbo-ext/`; it loads no binary without it. */
    public function corePath(): ?string
    {
        $directory = $this->directory();
        if (null === $directory) {
            return null;
        }

        return $directory . '/' . self::CORE_FILE;
    }

    /** Where phpstan.phar looks for the binary, relative to `turbo-ext/`. */
    public function binaryPath(): ?string
    {
        $directory = $this->directory();
        if (null === $directory) {
            return null;
        }

        return \sprintf('%s/phpstan_turbo-%s%s.so', $directory, $this->phpMinor, $this->zts ? '-zts' : '');
    }

    public function describe(): string
    {
        $libc = '' === $this->libc ? '' : ', libc ' . $this->libc;

        return \sprintf('%s %s%s, PHP %s%s', $this->os, $this->machine, $libc, $this->phpMinor, $this->zts ? ' ZTS' : '');
    }

    private function directory(): ?string
    {
        $arch = $this->arch();
        if (null === $arch) {
            return null;
        }

        if (self::LINUX === $this->os && \in_array($this->libc, self::LINUX_LIBCS, true)) {
            return \sprintf('linux-%s-%s', $this->libc, $arch);
        }

        // Upstream builds macOS for Apple silicon only, and without a ZTS variant.
        if (self::DARWIN === $this->os && self::ARM64 === $arch && !$this->zts) {
            return 'macos-' . self::ARM64;
        }

        return null;
    }

    private function arch(): ?string
    {
        return self::MACHINES[strtolower($this->machine)] ?? null;
    }
}
