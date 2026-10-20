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
 *
 * @internal
 */
final readonly class PhpLintTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpLint';

    private const string PHAR = 'parallel-lint.phar';

    /** The file names parallel-lint lints by default, as its own walk matches them. */
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

        foreach ($this->unresolvable($excluded, ...$checked) as $file) {
            $context->writeln(UnresolvablePath::skipped(self::LABEL, $file, $paths->projectRoot));
            $args[] = self::EXCLUDE;
            $args[] = $file;
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
     * cannot be resolved, spelt as its walk spells it.
     *
     * @param list<string> $excluded
     *
     * @return list<string>
     */
    private function unresolvable(array $excluded, string ...$checked): array
    {
        $found = [];
        foreach ($checked as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $walk = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    static fn (SplFileInfo $entry): bool => !\in_array($entry->getPathname(), $excluded, true),
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($walk as $entry) {
                if ($entry instanceof SplFileInfo
                    && 1 === \Safe\preg_match(self::LINTED_FILE, $entry->getFilename())
                    && UnresolvablePath::is($entry->getPathname())) {
                    $found[] = $entry->getPathname();
                }
            }
        }

        return array_values(array_unique($found));
    }
}
