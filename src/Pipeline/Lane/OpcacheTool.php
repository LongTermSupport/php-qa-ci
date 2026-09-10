<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use FilesystemIterator;
use LTS\PHPQA\Pipeline\Config\OpcacheDefects;
use LTS\PHPQA\Pipeline\Lane\Opcache\Dto\ConstComparisonDto;
use LTS\PHPQA\Pipeline\Lane\Opcache\DumpParser;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Compiles every checked PHP file through OPcache and checks the resulting
 * bytecode against the OPcache defects php-qa-ci knows about (see
 * OpcacheDefects). One lane for all of them: they share the compile, and a
 * further defect of the same kind belongs here as another assertion rather
 * than as another tool (CLAUDE/tool-boundaries.md).
 *
 * Checked now: a comparison opcode the optimizer left with two constant
 * operands, which the VM has no handler for.
 *
 * The optimizer mask is passed rather than inherited so the verdict says what
 * an unmitigated production host would compile, even on a QA host whose
 * operator has already cleared the DFA pass. Files are compiled with
 * `opcache_compile_file()` by bin/opcache-optimizer-dump, never executed, so a
 * bootstrap or a bin script is as safe to check as a class.
 *
 * @internal
 */
final readonly class OpcacheTool implements ToolInterface
{
    public const string IDENTIFIER = OpcacheDefects::IDENTIFIER;

    private const string DUMP_SCRIPT = '/bin/opcache-optimizer-dump';

    private const int FILES_PER_PROCESS = 200;

    private const int EXIT_NO_OPCACHE = 2;

    public function name(): string
    {
        return 'opcache';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $version = $context->php->version();
        if (!OpcacheDefects::affects($version)) {
            return ToolResultDto::skipped(\sprintf('PHP %s is outside the affected range', $version));
        }

        $files = $this->files($context);
        if ([] === $files) {
            $context->writeln('No PHP files to compile');

            return ToolResultDto::passed();
        }

        $paths    = $context->config->paths;
        $parser   = new DumpParser();
        $findings = [];
        $dumped   = [];
        foreach (array_chunk($files, self::FILES_PER_PROCESS) as $chunk) {
            $result = $context->php->withoutXdebug(
                $paths->libraryRoot . self::DUMP_SCRIPT,
                $chunk,
                $paths->projectRoot,
                streamOutput: false,
                ini: [
                    'opcache.enable_cli'             => '1',
                    'opcache.optimization_level'     => \sprintf('0x%X', OpcacheDefects::DEFAULT_LEVEL),
                    'opcache.opt_debug_level'        => '0x20000',
                    // A file written in the last couple of seconds is not cached,
                    // so it is never optimised or dumped. The fixers rewrite files
                    // moments before this lane runs, which would turn a just-fixed
                    // file into a silent pass.
                    'opcache.file_update_protection' => '0',
                ],
            );
            if (self::EXIT_NO_OPCACHE === $result->exitCode) {
                return ToolResultDto::skipped('OPcache is not loaded for the CLI, so nothing can be compiled through the optimizer');
            }

            if (!$result->succeeded()) {
                $context->writeln($result->output);

                return ToolResultDto::crashed(\sprintf('optimizer dump crashed (exit %d)', $result->exitCode));
            }

            $findings = [...$findings, ...$parser->parse($result->output)];
            $dumped   = [...$dumped, ...$parser->filesDumped($result->output)];
        }

        $missed = array_values(array_diff($files, $dumped));
        if ([] !== $missed) {
            return $this->reportMissed($context, ...$missed);
        }

        if ([] === $findings) {
            $context->writeln(\sprintf('%d file(s) compiled through the optimizer; no known OPcache defect found in the bytecode', \count($files)));

            return ToolResultDto::passed();
        }

        $this->reportFindings($context, ...$findings);
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('%d comparison(s) the optimizer left as constant-vs-constant', \count($findings)));
    }

    /**
     * A file OPcache declined to cache is compiled but never dumped, so
     * nothing checked it. A pass on an unchecked file is the one outcome this
     * lane must never give.
     */
    private function reportMissed(ToolContext $context, string ...$missed): ToolResultDto
    {
        $context->writeln('OPcache produced no optimizer dump for these files, so nothing verified them:');
        foreach ($missed as $file) {
            $context->writeln('  ' . $file);
        }

        $context->writeln('');
        $context->writeln('OPcache skips a file it will not cache: an opcache.blacklist_filename entry, or');
        $context->writeln('exhausted shared memory (raise opcache.memory_consumption). Clear the cause and re-run.');

        return ToolResultDto::crashed(\sprintf('%d file(s) produced no optimizer dump, so nothing verified them', \count($missed)));
    }

    private function reportFindings(ToolContext $context, ConstComparisonDto ...$findings): void
    {
        $context->writeln('The optimizer left these comparisons with two constant operands (file:lines  function  opcode):');
        foreach ($findings as $finding) {
            $context->writeln('  ' . $finding->display());
        }

        $context->writeln('');
        $context->writeln('HOW TO FIX');
        $context->writeln('----------');
        $context->writeln('The VM has no handler for a constant-vs-constant comparison: it segfaults or compares');
        $context->writeln('garbage. The optimizer produces one when an earlier check on the same path proves a');
        $context->writeln('variable is a constant (usually `null === $x`) and a later comparison of that variable');
        $context->writeln('with a literal (usually `[] !== $x`) cannot be folded. Rewrite the condition so the');
        $context->writeln('second comparison does not sit on the branch where the first has already decided the');
        $context->writeln('value, e.g. `null === $x || [] === $x` in that order, and re-run this lane.');
        $context->writeln(\sprintf('Hosts that must run this PHP should also set opcache.optimization_level=0x%X.', OpcacheDefects::RECOMMENDED_LEVEL));
    }

    /** @return list<string> absolute paths of every checked .php file outside the ignored paths, sorted per path */
    private function files(ToolContext $context): array
    {
        $ignored = array_map(
            static fn (string $path): string => $context->config->paths->projectRoot . '/' . $path,
            $context->config->pathsToIgnore,
        );
        $files = [];
        foreach ($context->config->pathsToCheck as $path) {
            foreach ($this->phpFiles($path) as $file) {
                if (!$this->under($file, ...$ignored)) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    private function under(string $file, string ...$directories): bool
    {
        return array_any($directories, static fn (string $directory): bool => $file === $directory || str_starts_with($file, $directory . '/'));
    }

    /** @return list<string> */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') ? [$path] : [];
        }

        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item instanceof SplFileInfo && $item->isFile() && str_ends_with($item->getPathname(), '.php')) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
