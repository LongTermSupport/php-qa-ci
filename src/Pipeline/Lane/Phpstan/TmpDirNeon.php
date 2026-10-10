<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use LTS\PHPQA\PHPStan\ProjectRecord\NeonIncludeChain;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use Nette\Neon\Neon;

/**
 * Where PHPStan keeps its cache for the phpstan and deadCode lanes. PHPStan's
 * default `tmpDir` is `sys_get_temp_dir()/phpstan`, which every checkout and
 * project on the host shares, so a stale entry written by one can decide the
 * verdict in another and nothing in the project resets it (#123). Each lane
 * therefore writes a `tmpDir` of its own under `var/qa/cache/` into its wrapper
 * neon, and deleting `var/qa/` resets it.
 *
 * A `tmpDir` the project's own config chain already sets is the project's
 * decision (a CI cache directory, say) and is left in place: the wrapper is the
 * outermost file, so a line written there would override it silently. The
 * chain is read first the way PHPStan merges it, includes and all, through
 * NeonIncludeChain, which names the file that sets it. That walk cannot follow
 * an include built from a `%parameter%` other than `%currentWorkingDirectory%`,
 * a PHP config file, or a missing or non-NEON file, so when it finds nothing
 * PHPStan itself is asked for the merged value (TmpDirProbeInterface); one
 * other than PHPStan's default is the project's too. When PHPStan cannot
 * answer, the walk's "none" stands.
 *
 * @internal
 */
final readonly class TmpDirNeon
{
    private const string PARAMETERS = 'parameters';

    private const string TMP_DIR = 'tmpDir';

    public function __construct(private TmpDirProbeInterface $probe = new DumpParametersTmpDirProbe())
    {
    }

    /**
     * The wrapper-neon line for a lane whose cache is `var/qa/cache/<cacheDir>`,
     * or nothing when the project sets its own; says which, under `$label`.
     * Each lane passes its own directory: PHPStan keeps one result cache per
     * tmpDir, so two configurations sharing one would invalidate each other's
     * on every alternation. `$autoloadFile` is the `--autoload-file` the lane
     * passes PHPStan, so PHPStan is asked about the configuration it will run.
     */
    public function forLane(ToolContext $context, string $label, string $cacheDir, ?string $autoloadFile = null): string
    {
        $paths     = $context->config->paths;
        $config    = $context->configPath('phpstan.neon');
        $declaring = $this->declaredBy($config, $paths->projectRoot);
        if (null !== $declaring) {
            $context->writeln(\sprintf('%s: cache in the tmpDir %s sets', $label, $declaring));

            return '';
        }

        $resolved = $this->probe->projectTmpDir($context, $config, $autoloadFile);
        if (null !== $resolved) {
            $context->writeln(\sprintf("%s: cache in the tmpDir the project's PHPStan configuration sets, %s", $label, $resolved));

            return '';
        }

        $tmpDir   = $paths->cacheDir . '/' . $cacheDir;
        $relative = str_starts_with($tmpDir, $paths->projectRoot . '/') ? substr($tmpDir, \strlen($paths->projectRoot) + 1) : $tmpDir;
        $context->writeln(\sprintf('%s: cache in %s', $label, $relative));

        return $this->parameters($tmpDir);
    }

    /**
     * The project-relative name of the last file in the resolved config's
     * include chain that sets `parameters.tmpDir`, which is the one PHPStan
     * uses; null when none does.
     */
    public function declaredBy(string $resolvedConfig, string $projectRoot): ?string
    {
        if (!is_file($resolvedConfig)) {
            return null;
        }

        $declaring = null;
        // The chain lists only files that decoded as NEON; the rest are its problems.
        foreach (new NeonIncludeChain()->resolve($resolvedConfig, $projectRoot)->files as $file) {
            $decoded    = Neon::decode($file->neon);
            $parameters = \is_array($decoded) ? ($decoded[self::PARAMETERS] ?? null) : null;
            if (\is_array($parameters) && \array_key_exists(self::TMP_DIR, $parameters)) {
                $declaring = $file->display;
            }
        }

        return $declaring;
    }

    /** The `tmpDir` line, indented to sit under `parameters:`, with the directory created. */
    public function parameters(string $tmpDir): string
    {
        if (!is_dir($tmpDir)) {
            \Safe\mkdir($tmpDir, 0o777, true);
        }

        return \sprintf("    %s: '%s'\n", self::TMP_DIR, str_replace("'", "''", $tmpDir));
    }
}
