<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\ProjectRecord;

use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonIncludeChainDto;
use LTS\PHPQA\PHPStan\ProjectRecord\Dto\NeonRecordFileDto;
use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;

/**
 * Every NEON file PHPStan reads for a project, found by following `includes:`
 * from its phpstan.neon depth-first, each file once, so that an ignoreErrors
 * entry cannot leave the project record by moving into an included file. The
 * files come in PHPStan's merge order: a file's includes before the file.
 * Both the justification lane and `bin/rules` walk the chain through here.
 *
 * An include resolves against the including file's directory, or is absolute
 * (a path, or a `phar://` URL into an archive), or starts with
 * `%currentWorkingDirectory%` (the project root, where the pipeline runs
 * PHPStan). PHPStan's own configuration is not followed: anything under
 * `%rootDir%`, and anything inside an archive named `phpstan.phar`, which is
 * how PHPStan ships and how its documented `phar://phpstan.phar/conf/...`
 * includes address it. Any other archive is read like any other file.
 * Anything that cannot be followed is a problem rather than a
 * silent gap: another `%parameter%`, a missing file, a file that is not NEON,
 * and a PHP include that sets ignoreErrors or includes, which can carry no
 * comment to justify them.
 *
 * @internal
 */
final readonly class NeonIncludeChain
{
    private const string WORKING_DIRECTORY = '%currentWorkingDirectory%';

    private const string PHPSTAN_ROOT = '%rootDir%';

    private const string PHAR_SCHEME = 'phar://';

    private const string PHPSTAN_PHAR = 'phpstan.phar';

    public function resolve(string $entryFile, string $projectRoot): NeonIncludeChainDto
    {
        $files    = [];
        $problems = [];
        $seen     = [];
        $this->visit(\Safe\realpath($entryFile), \Safe\realpath($projectRoot), $files, $problems, $seen);

        return new NeonIncludeChainDto($files, $problems);
    }

    /**
     * @param list<NeonRecordFileDto> $files
     * @param list<string>            $problems
     * @param array<string, string>   $seen     every file visited, keyed by itself
     */
    private function visit(string $path, string $root, array &$files, array &$problems, array &$seen): void
    {
        $seen[$path] = $path;
        $display     = $this->display($path, $root);
        $neon        = \Safe\file_get_contents($path);

        try {
            $decoded = Neon::decode($neon);
        } catch (NeonException $neonException) {
            $problems[] = \sprintf('%s is not valid NEON: %s', $display, $neonException->getMessage());

            return;
        }

        $includes = \is_array($decoded) ? ($decoded['includes'] ?? []) : [];
        if (!\is_array($includes) || !array_is_list($includes) || [] !== array_filter($includes, static fn (mixed $include): bool => !\is_string($include))) {
            $problems[] = \sprintf('%s has an includes key that is not a list of paths', $display);
            $includes   = [];
        }

        /** @var list<string> $includes */
        foreach ($includes as $include) {
            $target = $this->target($include, \dirname($path), $root, $display, $problems);
            if (null !== $target && !isset($seen[$target])) {
                $this->visit($target, $root, $files, $problems, $seen);
            }
        }

        $files[] = new NeonRecordFileDto($path, $display, $neon, $this->declaredEntries($decoded));
    }

    /**
     * The include's real path when it is a NEON file to read next; null when
     * it is not followed, with a problem recorded if that leaves a gap.
     *
     * @param list<string> $problems
     */
    private function target(string $include, string $directory, string $root, string $display, array &$problems): ?string
    {
        if (str_starts_with($include, self::PHPSTAN_ROOT) || $this->isInsidePhpstanPhar($include)) {
            return null;
        }

        $path = str_starts_with($include, self::WORKING_DIRECTORY)
            ? $root . substr($include, \strlen(self::WORKING_DIRECTORY))
            : $include;
        if (str_contains($path, '%')) {
            $problems[] = \sprintf('%s includes %s, whose %%parameter%% cannot be resolved outside PHPStan, so nothing it reaches can be checked', $display, $include);

            return null;
        }

        $inArchive = str_starts_with($path, self::PHAR_SCHEME);
        $absolute  = $inArchive || str_starts_with($path, '/') ? $path : $directory . '/' . $path;
        if (!is_file($absolute)) {
            $problems[] = \sprintf('%s includes %s, which does not exist', $display, $include);

            return null;
        }

        if (str_ends_with($absolute, '.php')) {
            if (1 === \Safe\preg_match('/ignoreErrors|includes/', \Safe\file_get_contents($absolute))) {
                $problems[] = \sprintf('%s includes %s, a PHP configuration file that sets ignoreErrors or includes; a PHP file cannot carry the justification comment, so move them into a .neon file', $display, $include);
            }

            return null;
        }

        // realpath() cannot canonicalise a phar:// URL; the URL is already absolute.
        return $inArchive ? $absolute : \Safe\realpath($absolute);
    }

    /**
     * Whether the include is a `phar://` URL into an archive named `phpstan.phar`:
     * the archive is the text up to the first `.phar/` segment, by alias
     * (`phar://phpstan.phar/...`, the running PHPStan) or by path.
     */
    private function isInsidePhpstanPhar(string $include): bool
    {
        return 1 === \Safe\preg_match('#^phar://(.*?\.phar)/#', $include, $matches)
            && isset($matches[1])
            && self::PHPSTAN_PHAR === basename($matches[1]);
    }

    private function declaredEntries(mixed $decoded): int
    {
        $parameters = \is_array($decoded) ? ($decoded['parameters'] ?? null) : null;
        $entries    = \is_array($parameters) ? ($parameters['ignoreErrors'] ?? null) : null;

        return \is_array($entries) ? \count($entries) : 0;
    }

    private function display(string $path, string $root): string
    {
        return str_starts_with($path, $root . '/') ? substr($path, \strlen($root) + 1) : $path;
    }
}
