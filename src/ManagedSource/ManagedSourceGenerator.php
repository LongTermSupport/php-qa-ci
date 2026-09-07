<?php

declare(strict_types=1);

namespace LTS\PHPQA\ManagedSource;

use RuntimeException;

/**
 * Generates the php-qa-ci "managed source" tree into a consumer project.
 *
 * Some php-qa-ci features need a small PHP artefact to live in the CONSUMER's
 * own (production) namespace — e.g. the {@see \LTS\PHPQA\PHPStan\Rules\FactorySealedRule}
 * sealing attribute, which production code annotates with and therefore cannot
 * `use` from this `require-dev` package. Rather than have each project hand-write
 * (and risk drifting) that artefact, php-qa-ci OWNS it and writes it into a
 * dedicated, locked `<RootNs>\PhpQaCi\` namespace on every composer
 * install/update (see {@see \LTS\PHPQA\ComposerPlugin\ManagedSourceDeployPlugin}).
 *
 * The tree is regenerated deterministically and is drift-checked by the QA
 * pipeline, so it is "always and only managed by php-qa-ci". Because this package
 * is `require-dev`, the plugin only runs in the artefact-owning project's own
 * dev/CI; that project commits the generated files so they ship to its downstream
 * consumers (exactly like other committed generated code).
 *
 * The class is split so the pure parts (namespace resolution + rendered file set)
 * are unit-testable without a filesystem.
 *
 * @internal
 */
final readonly class ManagedSourceGenerator
{
    /**
     * Resolve the consumer's runtime root namespace and source directory from its
     * decoded composer.json. Uses `autoload` (NEVER `autoload-dev`) and takes the
     * first psr-4 prefix — the package's primary runtime namespace.
     *
     * @param array<string, mixed> $composerData decoded composer.json
     *
     * @return array{rootNamespace: string, srcDir: string}
     */
    public static function resolveTarget(array $composerData): array
    {
        $autoload = $composerData['autoload'] ?? null;
        if (!\is_array($autoload) || !isset($autoload['psr-4']) || !\is_array($autoload['psr-4'])) {
            throw new RuntimeException('composer.json has no autoload.psr-4 mapping to host the managed namespace');
        }

        $psr4      = $autoload['psr-4'];
        $namespace = array_key_first($psr4);
        if (!\is_string($namespace) || '' === $namespace) {
            throw new RuntimeException('composer.json autoload.psr-4 is empty');
        }

        $path = $psr4[$namespace];
        if (\is_array($path)) {
            $path = $path[0] ?? null;
        }

        if (!\is_string($path) || '' === $path) {
            throw new RuntimeException(\sprintf('autoload.psr-4 entry "%s" has no usable directory', $namespace));
        }

        return [
            'rootNamespace' => rtrim($namespace, '\\'),
            'srcDir'        => rtrim($path, '/'),
        ];
    }

    /**
     * The managed files to render for a consumer, keyed by their path relative to
     * the consumer's source directory. Pure — no filesystem access.
     *
     * @return array<string, string> relativePath => file contents
     */
    public function managedFiles(string $rootNamespace): array
    {
        return [
            'PhpQaCi/FactorySealedBy.php' => $this->renderFactorySealedBy($rootNamespace . '\PhpQaCi'),
        ];
    }

    /**
     * Write the managed tree into the project. Returns the absolute paths written.
     *
     * @return list<string>
     */
    public function generate(string $projectRoot): array
    {
        [$rootNamespace, $srcAbs] = $this->target($projectRoot);

        $written = [];
        foreach ($this->managedFiles($rootNamespace) as $relativePath => $contents) {
            $absolutePath = $srcAbs . '/' . $relativePath;
            $directory    = \dirname($absolutePath);
            if (!is_dir($directory)) {
                \Safe\mkdir($directory, 0o755, true);
            }

            \Safe\file_put_contents($absolutePath, $contents);
            $written[] = $absolutePath;
        }

        return $written;
    }

    /**
     * Report managed files whose on-disk content differs from what would be
     * generated (hand-edited, stale, or missing). Empty list means no drift.
     *
     * @return list<string> drifted paths, relative to the source directory
     */
    public function check(string $projectRoot): array
    {
        [$rootNamespace, $srcAbs] = $this->target($projectRoot);

        $drift = [];
        foreach ($this->managedFiles($rootNamespace) as $relativePath => $expected) {
            $absolutePath = $srcAbs . '/' . $relativePath;
            if (!is_file($absolutePath) || \Safe\file_get_contents($absolutePath) !== $expected) {
                $drift[] = $relativePath;
            }
        }

        return $drift;
    }

    /**
     * Resolve [rootNamespace, absolute source dir] from the project's composer.json.
     *
     * @return array{0: string, 1: string}
     */
    private function target(string $projectRoot): array
    {
        $decoded = \Safe\json_decode(\Safe\file_get_contents($projectRoot . '/composer.json'), true);
        if (!\is_array($decoded)) {
            throw new RuntimeException(\sprintf('composer.json at %s did not decode to an object', $projectRoot));
        }

        /** @var array<string, mixed> $decoded */
        $target = self::resolveTarget($decoded);

        return [$target['rootNamespace'], $projectRoot . '/' . $target['srcDir']];
    }

    private function renderFactorySealedBy(string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Attribute;

            /**
             * GENERATED & MANAGED BY php-qa-ci — DO NOT EDIT.
             *
             * This file is (re)generated into your project on every `composer install`
             * / `composer update` by php-qa-ci's ManagedSourceDeployPlugin, and is
             * drift-checked by the QA pipeline. Any hand edit will be reverted on the
             * next install/update and will fail QA in the meantime. To change it, change
             * php-qa-ci's ManagedSourceGenerator, not this file.
             *
             * Marks a class as factory-sealed: it may be constructed (`new X(...)`) only
             * by the single authorised factory named here. PHP has no friend/
             * package-private visibility, so this sole-producer constraint is expressed
             * via this attribute and enforced statically.
             *
             * Enforced by {@see \\LTS\\PHPQA\\PHPStan\\Rules\\FactorySealedRule} (configured
             * with this attribute's FQCN via its `sealingAttributes` parameter), which
             * flags `new <sealed>(...)` outside the authorised factory (tests are exempt).
             *
             * It lives in your project's own (production) namespace — not php-qa-ci's —
             * because production code annotates with it and must never `use` a
             * `require-dev` package.
             *
             * @internal this managed artefact is not part of your package's public
             *           API surface, so a type:library consumer's API-surface
             *           classification rule passes over it on that basis
             */
            #[Attribute(Attribute::TARGET_CLASS)]
            final readonly class FactorySealedBy
            {
                /**
                 * @param class-string \$factory the sole authorised producer of the sealed type
                 */
                public function __construct(public string \$factory)
                {
                }
            }

            PHP;
    }
}
