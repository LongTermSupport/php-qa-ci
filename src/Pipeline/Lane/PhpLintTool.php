<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use FilesystemIterator;
use LTS\PHPQA\Filesystem\UnresolvablePath;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Fast parallel syntax check of every checked path via the shipped
 * vendor-phar/parallel-lint.phar. Each ignored path is passed as an --exclude,
 * resolved against the project root. Any non-zero exit is a lint failure.
 *
 * parallel-lint reports a file whose real path cannot be resolved (a dangling
 * symlink) as an "Unknown error" without saying why, so the lane walks the checked
 * paths as parallel-lint does first: the same extensions, any case, an excluded
 * path not entered. Each such file is named as skipped and passed as one more
 * --exclude, which parallel-lint matches against the exact path its walk yields.
 * When such files are all it would lint, it is not run, since it fails on finding
 * nothing to check.
 *
 * @internal
 */
final readonly class PhpLintTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpLint';

    private const string PHAR = 'parallel-lint.phar';

    private const string LINTED_FILE = '`\.(?:php|phtml|php3|php4|php5|phpt)$`iD';

    private const string EXCLUDE = '--exclude';

    private const string LABEL = 'PHP Lint';

    public function name(): string
    {
        return 'phpLint';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $paths    = $context->config->paths;
        $excluded = [];
        foreach ($context->config->pathsToIgnore as $ignore) {
            $excluded[] = $paths->projectRoot . '/' . $ignore;
        }

        $checked = [];
        foreach ($context->config->pathsToCheck as $path) {
            if (UnresolvablePath::is($path)) {
                $context->writeln(UnresolvablePath::skipped(self::LABEL, $path, $paths->projectRoot));

                continue;
            }

            $checked[] = $path;
        }

        if ([] === $checked) {
            return ToolResultDto::passed();
        }

        $args = [];
        foreach ($excluded as $exclude) {
            $args[] = self::EXCLUDE;
            $args[] = $exclude;
        }

        [$unresolvable, $lintable] = $this->walk($excluded, ...$checked);
        foreach ($unresolvable as $file) {
            $context->writeln(UnresolvablePath::skipped(self::LABEL, $file, $paths->projectRoot));
            $args[] = self::EXCLUDE;
            $args[] = $file;
        }

        if ([] !== $unresolvable && !$lintable) {
            return ToolResultDto::passed();
        }

        $result = $context->php->withoutXdebug(
            $paths->pharDir . '/' . self::PHAR,
            [...$args, ...$checked],
            $paths->projectRoot,
        );

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('PHP Lint failed (exit %d)', $result->exitCode));
    }

    /**
     * Each file parallel-lint would lint under the checked directories whose real path
     * cannot be resolved, spelt as its walk spells it, and whether anything else is left
     * for it: a real file it would lint, or a checked path that is not a directory, which
     * parallel-lint judges itself.
     *
     * @param list<string> $excluded
     *
     * @return array{list<string>, bool}
     */
    private function walk(array $excluded, string ...$checked): array
    {
        $found    = [];
        $lintable = false;
        $excluded = array_map(static fn (string $path): string => new SplFileInfo(self::excludeKey($path))->getPathname(), $excluded);
        foreach ($checked as $path) {
            if (!is_dir($path)) {
                $lintable = true;

                continue;
            }

            $walk = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    static fn (SplFileInfo $entry): bool => !\in_array(self::excludeKey($entry->getPathname()), $excluded, true),
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($walk as $entry) {
                if (!$entry instanceof SplFileInfo || 1 !== \Safe\preg_match(self::LINTED_FILE, $entry->getFilename())) {
                    continue;
                }

                if (UnresolvablePath::is($entry->getPathname())) {
                    $found[] = $entry->getPathname();
                } else {
                    $lintable = true;
                }
            }
        }

        return [array_values(array_unique($found)), $lintable];
    }

    /**
     * A path as parallel-lint compares it against its excludes: native separators and a
     * leading `./`. Each exclude is further passed through SplFileInfo, which drops a
     * trailing slash and nothing else.
     */
    private static function excludeKey(string $path): string
    {
        $path = str_replace(['\\', '/'], \DIRECTORY_SEPARATOR, $path);

        return str_starts_with($path, '.' . \DIRECTORY_SEPARATOR) ? $path : '.' . \DIRECTORY_SEPARATOR . $path;
    }
}
