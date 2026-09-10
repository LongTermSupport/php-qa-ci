<?php

declare(strict_types=1);

namespace LTS\PHPQA\ConfigTemplateIgnoreList;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A config template shipped under configDefaults/generic/ is a plain
 * `return ...;` script with no namespace, and the documentation tells a
 * consumer to copy it into the override directory, which php-qa-ci maps as a
 * PSR-4 root. Unless the copy destination is matched by a pattern in
 * psr4-validate-ignore-list.txt, the documented override fails psr4Validate
 * with a "Parse Errors" report. This reads both files as text and names every
 * namespace-less template the list does not cover. It audits the toolchain's
 * own shipped files, not a consuming project's tree, which is why it is
 * separate from {@see \LTS\PHPQA\Psr4Validator}.
 *
 * @internal
 */
final readonly class ConfigTemplateIgnoreListAuditor
{
    /**
     * The directory the documentation tells a consumer to copy a template
     * into; {@see \LTS\PHPQA\Tests\Small\ConfigTemplateIgnoreList\ConfigTemplateIgnoreListAuditorTest}
     * checks docs/configuration.md still names this directory.
     */
    public const string OVERRIDE_DIR = 'qaConfig';

    public function __construct(
        private string $configDefaultsGenericDir,
        private string $ignoreListPath,
        private string $overrideDir = self::OVERRIDE_DIR,
    ) {
    }

    /**
     * @return array<string, string> template path relative to the defaults
     *                               directory, mapped to the override path it
     *                               would occupy once copied, for every
     *                               namespace-less template the list misses
     */
    public function main(): array
    {
        $ignorePatterns = $this->loadIgnorePatterns();
        $violations     = [];

        foreach ($this->namespaceLessTemplates() as $relativePath => $copyDestination) {
            if (!$this->isCovered($copyDestination, ...$ignorePatterns)) {
                $violations[$relativePath] = $copyDestination;
            }
        }

        ksort($violations);

        return $violations;
    }

    /** @return array<string, string> relative path => override-relative copy destination */
    private function namespaceLessTemplates(): array
    {
        $templates = [];
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->configDefaultsGenericDir,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ('php' !== $fileInfo->getExtension()) {
                continue;
            }

            if ($this->hasNamespace(\Safe\file_get_contents($fileInfo->getPathname()))) {
                continue;
            }

            $relativePath             = ltrim(substr($fileInfo->getPathname(), \strlen($this->configDefaultsGenericDir)), '/');
            $templates[$relativePath] = $this->overrideDir . '/' . $relativePath;
        }

        return $templates;
    }

    /**
     * A real namespace declaration is a statement at the start of a line with
     * an identifier and a semicolon. Psr4Validator's looser pattern is fooled
     * by a docblock that mentions the word "namespace" in prose, which one
     * shipped template does.
     */
    private function hasNamespace(string $contents): bool
    {
        return 1 === \Safe\preg_match('%^\s*namespace\s+[A-Za-z_\\\][A-Za-z0-9_\\\]*\s*;%m', $contents);
    }

    private function isCovered(string $copyDestination, string ...$ignorePatterns): bool
    {
        return array_any($ignorePatterns, static fn (string $pattern): bool => 1 === \Safe\preg_match($pattern, $copyDestination));
    }

    /** @return list<string> */
    private function loadIgnorePatterns(): array
    {
        $patterns = [];
        foreach (explode("\n", \Safe\file_get_contents($this->ignoreListPath)) as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $patterns[] = $line;
            }
        }

        return $patterns;
    }
}
