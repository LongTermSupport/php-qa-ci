<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan;

use PhpParser\ConstExprEvaluator;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * The NEON files phpstan/extension-installer hands PHPStan: every installed
 * package's extra.phpstan.includes. This is how the bundled rule tiers reach
 * a consuming project (the installer never reads the root package, which is
 * why php-qa-ci includes its own tiers by hand), so a listing of the active
 * defences that skipped it would list none of them in a consumer.
 *
 * Read from the GeneratedConfig class the installer writes under the
 * project's vendor dir, which is what PHPStan itself loads, and parsed rather
 * than loaded: the running process may have a different GeneratedConfig on
 * its autoloader. Each install path is relative to that file's directory;
 * an include that does not exist is left out.
 *
 * @internal
 */
final readonly class InstalledPhpstanExtensions
{
    private const string GENERATED_CONFIG = '/phpstan/extension-installer/src/GeneratedConfig.php';

    /** @return array<string, list<string>> package name => absolute include paths, in the installer's order */
    public function includes(string $projectRoot): array
    {
        $file = $this->vendorDir($projectRoot) . self::GENERATED_CONFIG;
        if (!is_file($file)) {
            return [];
        }

        $includes = [];
        foreach ($this->extensions($file) as $name => $extension) {
            if (!\is_string($name) || !\is_array($extension) || !\is_string($extension['relative_install_path'] ?? null)) {
                continue;
            }

            $extra = $extension['extra'] ?? null;
            $paths = [];
            foreach (\is_array($extra) && \is_array($extra['includes'] ?? null) ? $extra['includes'] : [] as $include) {
                $path = \dirname($file) . '/' . $extension['relative_install_path'] . '/' . (\is_string($include) ? $include : '');
                if (\is_string($include) && is_file($path)) {
                    $paths[] = \Safe\realpath($path);
                }
            }

            if ([] !== $paths) {
                $includes[$name] = $paths;
            }
        }

        return $includes;
    }

    /** @return array<array-key, mixed> the EXTENSIONS constant's value, or nothing */
    private function extensions(string $file): array
    {
        $ast = new ParserFactory()->createForNewestSupportedVersion()->parse(\Safe\file_get_contents($file)) ?? [];
        foreach (new NodeFinder()->findInstanceOf($ast, ClassConst::class) as $constant) {
            foreach ($constant->consts as $const) {
                if ('EXTENSIONS' === $const->name->toString()) {
                    $value = new ConstExprEvaluator()->evaluateDirectly($const->value);

                    return \is_array($value) ? $value : [];
                }
            }
        }

        return [];
    }

    private function vendorDir(string $projectRoot): string
    {
        $manifest = $projectRoot . '/composer.json';
        $decoded  = is_file($manifest) ? json_decode(\Safe\file_get_contents($manifest), true) : null;
        $config   = \is_array($decoded) ? ($decoded['config'] ?? null) : null;
        $vendor   = \is_array($config) && \is_string($config['vendor-dir'] ?? null) ? $config['vendor-dir'] : 'vendor';

        return str_starts_with($vendor, '/') ? $vendor : $projectRoot . '/' . $vendor;
    }
}
