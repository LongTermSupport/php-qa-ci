<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use FilesystemIterator;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every .php / .phtml file under the checked paths must declare
 * strict_types. A read-only run lists the offenders and fails; a writable run
 * adds the declaration to the opening tag and reports each fixed file, and
 * fails only for a file with no opening tag to fix.
 *
 * @internal
 */
final readonly class PhpStrictTypesTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpStrictTypes';

    private const string DECLARATION = '<?php declare(strict_types=1);';

    public function name(): string
    {
        return 'phpStrictTypes';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $missing = [];
        foreach ($context->config->pathsToCheck as $path) {
            foreach ($this->phpFiles($path) as $file) {
                if (!str_contains(\Safe\file_get_contents($file), 'strict_types')) {
                    $missing[] = $file;
                }
            }
        }

        if ([] === $missing) {
            $context->writeln('All PHP files declare strict_types');

            return ToolResultDto::passed();
        }

        $context->writeln('Files missing declare(strict_types=1):');
        foreach ($missing as $file) {
            $context->writeln('  ' . $file);
        }

        if ($context->config->readOnly) {
            $context->writeln('');
            $context->writeln('This is a READ-ONLY run, so nothing was modified. Add the declaration');
            $context->writeln("(or run 'QA_READONLY=0 vendor/bin/qa -t st' where writes are allowed), then commit.");
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed(\sprintf('%d file(s) missing declare(strict_types=1)', \count($missing)));
        }

        $unfixable = [];
        foreach ($missing as $file) {
            $contents = \Safe\file_get_contents($file);
            $position = strpos($contents, '<?php');
            if (false === $position) {
                $unfixable[] = $file;

                continue;
            }

            \Safe\file_put_contents($file, substr_replace($contents, self::DECLARATION, $position, \strlen('<?php')));
            $context->writeln('fixed: ' . $file);
        }

        if ([] !== $unfixable) {
            $context->writeln('');
            $context->writeln('ERROR: could not add declare(strict_types=1) to the following files (no opening <?php tag found) — fix them manually:');
            foreach ($unfixable as $file) {
                $context->writeln('  ' . $file);
            }

            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed(\sprintf('%d file(s) could not be given a strict_types declaration', \count($unfixable)));
        }

        $context->writeln('');
        $context->writeln(\sprintf('Added declare(strict_types=1) to %d file(s) — review and commit the changes.', \count($missing)));

        return ToolResultDto::passed();
    }

    /** @return list<string> absolute paths, sorted */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return $this->isPhp($path) ? [$path] : [];
        }

        if (!is_dir($path)) {
            return [];
        }

        $files    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isFile() && $this->isPhp($item->getPathname())) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function isPhp(string $file): bool
    {
        return str_ends_with($file, '.php') || str_ends_with($file, '.phtml');
    }
}
