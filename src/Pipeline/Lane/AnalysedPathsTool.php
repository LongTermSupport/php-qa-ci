<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto;
use LTS\PHPQA\Pipeline\Lane\AnalysedPaths\AnalysedPathsAudit;
use LTS\PHPQA\Pipeline\Lane\AnalysedPaths\Dto\AnalysedPathsVerdictDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Every PHP file in the project is analysed, or declared unanalysed with a
 * reason. PHPStan, Rector, PHP-CS-Fixer and the lint lanes read only the
 * checked paths, so PHP outside them escapes every rule without a word: no
 * rule can report a file it is never shown. This lane is the one place that
 * looks at the whole project, through git, and asks the question.
 *
 * Its own lane, not an assertion inside phpstan: the input (the project's file
 * list and its path configuration) and the mechanism (accounting for files
 * against paths) are not static analysis, the checked paths it audits feed
 * every scanning lane rather than PHPStan alone, and it has to run in a quick
 * run and in seconds, where phpstan is gated off and takes minutes.
 *
 * @internal
 */
final readonly class AnalysedPathsTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.analysedPaths';

    private const string PHP_EXTENSION = '.php';

    private const string PROJECT_CONFIG = 'qaConfig/qa.php';

    private const string SEPARATOR = '/';

    public function name(): string
    {
        return 'analysedPaths';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $config = $context->config;
        if (null !== $config->specifiedPath) {
            return ToolResultDto::skipped('a run narrowed with -p analyses that path alone, so the rest of the project would all read as unanalysed');
        }

        $phpFiles = $this->phpFiles($context);
        if (null === $phpFiles) {
            return ToolResultDto::skipped('the project is not a git work tree, so there is no file list to account for');
        }

        $analysed = $this->analysedPaths($config);
        $verdict  = new AnalysedPathsAudit($analysed, $config->pathsToIgnore, $config->unanalysedPaths)->audit(...$phpFiles);
        if ($verdict->passes()) {
            $this->reportPass($context, $verdict, ...$analysed);

            return ToolResultDto::passed();
        }

        $this->reportUnclassified($context, $verdict, ...$analysed);
        $this->reportStale($context, ...$verdict->stale);
        $this->reportContradicted($context, $verdict);
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('PHP that is neither analysed nor declared unanalysed, or a declaration that does not hold');
    }

    /**
     * The project's PHP files, tracked or untracked-but-not-ignored, that are
     * on disk; null when git cannot answer.
     *
     * @return list<string>|null
     */
    private function phpFiles(ToolContext $context): ?array
    {
        $root   = $context->config->paths->projectRoot;
        $result = $context->processes->run(new ProcessSpecDto(
            ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
            $root,
            streamOutput: false,
        ));

        if (!$result->succeeded()) {
            return null;
        }

        // git lists what the index holds, which on a dirty tree names files no
        // longer on disk; a deleted directory must not demand a declaration.
        return array_values(array_filter(
            explode("\0", $result->output),
            static fn (string $file): bool => str_ends_with($file, self::PHP_EXTENSION) && is_file($root . self::SEPARATOR . $file),
        ));
    }

    /**
     * The checked paths, project-relative; one outside the project accounts
     * for none of its files and is dropped.
     *
     * @return list<string>
     */
    private function analysedPaths(QaConfigDto $config): array
    {
        $root     = $config->paths->projectRoot;
        $relative = [];
        foreach ($config->pathsToCheck as $path) {
            if ($path === $root) {
                $relative[] = '';
            } elseif (str_starts_with($path, $root . self::SEPARATOR)) {
                $relative[] = substr($path, \strlen($root) + 1);
            }
        }

        return $relative;
    }

    private function reportPass(ToolContext $context, AnalysedPathsVerdictDto $verdict, string ...$analysed): void
    {
        $context->writeln(\sprintf(
            '%d PHP file(s), every one under an analysed path (%s) or declared unanalysed',
            $verdict->phpFiles,
            $this->displayPaths(...$analysed),
        ));

        if ([] === $verdict->declaredInUse) {
            return;
        }

        $context->writeln('Declared unanalysed in ' . self::PROJECT_CONFIG . ':');
        foreach ($context->config->unanalysedPaths as $path => $reason) {
            $count = $verdict->declaredInUse[$path] ?? null;
            if (null !== $count) {
                $context->writeln(\sprintf('  %s (%d PHP file(s)): %s', $this->display($context, $path), $count, $reason));
            }
        }
    }

    private function reportUnclassified(ToolContext $context, AnalysedPathsVerdictDto $verdict, string ...$analysed): void
    {
        if ([] === $verdict->unclassified) {
            return;
        }

        $context->writeln('PHP that no analysed path reaches and no declaration accounts for:');
        foreach ($verdict->unclassified as $unit => $count) {
            $path = rtrim($unit, self::SEPARATOR);
            $context->writeln(\sprintf('  %s (%d PHP file(s))', $unit, $count));
            $context->writeln(\sprintf("      analyse it:   ->withCheckedPaths('%s')", $path));
            $context->writeln(\sprintf("      or declare:   ->withUnanalysedPath('%s', '<why this PHP is not analysed>')", $path));
        }

        $context->writeln('');
        $context->writeln(\sprintf(
            'PHPStan, Rector, PHP-CS-Fixer and the lint lanes read only the checked paths (%s), so no rule',
            $this->displayPaths(...$analysed),
        ));
        $context->writeln('has seen these files. Classify each in ' . self::PROJECT_CONFIG . ': analyse it, which is right for');
        $context->writeln('code you maintain, or declare it unanalysed with the reason, which every run prints.');
    }

    private function reportStale(ToolContext $context, string ...$stale): void
    {
        foreach ($stale as $path) {
            $context->writeln(\sprintf(
                "withUnanalysedPath('%s', ...) names a path with no PHP file: remove the declaration, or it would excuse PHP added there later unexamined.",
                $path,
            ));
        }
    }

    private function reportContradicted(ToolContext $context, AnalysedPathsVerdictDto $verdict): void
    {
        foreach ($verdict->contradicted as $path => $analysedBy) {
            $context->writeln(\sprintf(
                "withUnanalysedPath('%s', ...) names a path inside the checked path %s: it is analysed, so the declaration is false. Remove it.",
                $path,
                $this->displayPaths($analysedBy),
            ));
        }
    }

    /** A declared path as the reader knows it: a directory with a trailing slash. */
    private function display(ToolContext $context, string $path): string
    {
        return is_dir($context->config->paths->projectRoot . self::SEPARATOR . $path) ? $path . self::SEPARATOR : $path;
    }

    private function displayPaths(string ...$paths): string
    {
        return implode(', ', array_map(
            static fn (string $path): string => '' === $path ? 'the whole project' : $path . self::SEPARATOR,
            $paths,
        ));
    }
}
