<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

/**
 * The axes that pick PHPStan's Turbo binary for a host: operating system, CPU, C library, PHP
 * minor version and thread safety. It names the phpstan/turbo-ext release asset built for the
 * host, and the path under `turbo-ext/` where phpstan.phar looks for it, both as upstream spells
 * them. A host upstream builds nothing for (Windows, an Intel Mac, an unknown CPU or libc) is
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

    /** libc as PHPStan names it in the directory, mapped to the asset name's spelling. */
    private const array LINUX_LIBCS = ['gnu' => 'glibc', 'musl' => 'musl'];

    /** Where a musl host keeps its dynamic loader; a glibc host has none of these. */
    private const string MUSL_LOADER_GLOB = '/lib/ld-musl-*.so.1';

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
        $libc = '';
        if (self::LINUX === \PHP_OS_FAMILY) {
            $libc = [] === \Safe\glob(self::MUSL_LOADER_GLOB) ? 'gnu' : 'musl';
        }

        return new self(\PHP_OS_FAMILY, php_uname('m'), $libc, \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION, 1 === \PHP_ZTS);
    }

    public function isSupported(): bool
    {
        return null !== $this->directory();
    }

    /** The release asset built for this host, e.g. `php_phpstan_turbo-2.3.0_php8.5-x86_64-linux-glibc.zip`. */
    public function assetName(string $phpstanVersion): ?string
    {
        $arch = $this->arch();
        if (null === $arch || null === $this->directory()) {
            return null;
        }

        $suffix = self::DARWIN === $this->os ? 'darwin-bsdlibc' : 'linux-' . self::LINUX_LIBCS[$this->libc];

        return \sprintf('php_phpstan_turbo-%s_php%s-%s-%s%s.zip', $phpstanVersion, $this->phpMinor, $arch, $suffix, $this->zts ? '-zts' : '');
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

        if (self::LINUX === $this->os && isset(self::LINUX_LIBCS[$this->libc])) {
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
