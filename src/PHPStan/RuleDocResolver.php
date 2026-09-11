<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto;

/**
 * Resolves a bundled rule identifier, as printed in a PHPStan failure, to the
 * rule's documentation. Works offline from the installed package: the index in
 * docs/phpstan-rules/README.md is the single source, and each row names the
 * rule class and, where one exists, its remediation page. Pipeline lanes that
 * print an identifier of their own are rows in the same index, with the class
 * given as a path under src/, so one lookup covers everything the package can
 * print.
 *
 * @internal
 */
final readonly class RuleDocResolver
{
    /** The only source of identifiers: a table read at run time, never a compiled-in map. */
    private const string INDEX = '/docs/phpstan-rules/README.md';

    /** Where a row's class cell is found when it is a bare class name (a bundled rule). */
    private const string RULES_DIR = '/src/PHPStan/Rules/';

    /** Doc targets are written relative to the index, so they resolve against its directory. */
    private const string DOCS_DIR = '/docs/phpstan-rules/';

    /** Identifier and class cells, then the rest of the row; the trailing cells vary by bundle. */
    private const string ROW_PATTERN = '/^\| `(phpqaci\.[A-Za-z0-9]+)` +\| `([A-Za-z0-9\/]+)` +\|(.*)\|\s*$/';

    /** Where a row's class cell is found when it is a path — how a pipeline lane names itself. */
    private const string SRC_DIR = '/src/';

    /**
     * A markdown link whose TEXT may itself contain a bracketed span, which is
     * routine here because a summary quotes the construction it is about —
     * `[`#[\SensitiveParameter]` is used somewhere in src/](../tools/…md)`. A
     * plain `[^\]]+` stops at the inner `]` and never reaches the `](` pair, so
     * the row resolves to no page while looking perfectly correct. One level of
     * nesting is enough for every summary we write; greedy matching is not an
     * option, because a row carries a bundle link as well as a doc link.
     */
    private const string LINK_PATTERN = '/\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(([^)]+)\)/';

    public function __construct(private string $repoRoot)
    {
    }

    public function resolve(string $identifier): RuleDocEntryDto
    {
        $entries = $this->entries();
        if (!isset($entries[$identifier])) {
            throw new InvalidArgumentException(\sprintf(
                'Unknown rule identifier "%s". Known identifiers are listed in %s',
                $identifier,
                $this->repoRoot . self::INDEX,
            ));
        }

        return $entries[$identifier];
    }

    /** @return list<string> */
    public function identifiers(): array
    {
        return array_keys($this->entries());
    }

    /**
     * The page an identifier resolves to, or null if it resolves to no page — or
     * is not in the index at all.
     *
     * Distinct from resolve() because the two callers want opposite things. A rule
     * declaring an identifier the index does not carry is a defect worth an
     * exception; a lane is asked about speculatively, including phase runners that
     * are not defences and document nothing, so absence is an answer rather than an
     * error.
     */
    public function docPathFor(string $identifier): ?string
    {
        $entry = $this->entries()[$identifier] ?? null;

        return $entry?->docPath;
    }

    public function render(string $identifier): string
    {
        $entry = $this->resolve($identifier);

        $out = \sprintf(
            "%s\n\nRule:    %s\nBundle:  %s\nSource:  %s\nSummary: %s\n",
            $entry->identifier,
            $entry->ruleClass,
            $entry->bundle,
            $entry->sourcePath,
            $entry->summary,
        );

        if (null === $entry->docPath) {
            return $out . "\nNo remediation page exists for this rule; the summary above is the whole guidance.\n";
        }

        return $out . "\n" . \Safe\file_get_contents($entry->docPath);
    }

    /** @return array<string, RuleDocEntryDto> */
    private function entries(): array
    {
        $entries = [];
        foreach (explode("\n", \Safe\file_get_contents($this->repoRoot . self::INDEX)) as $line) {
            $entry = $this->matchRow($line);
            if ($entry instanceof RuleDocEntryDto) {
                $entries[$entry->identifier] = $entry;
            }
        }

        return $entries;
    }

    private function matchRow(string $line): ?RuleDocEntryDto
    {
        if (1 !== \Safe\preg_match(self::ROW_PATTERN, $line, $matches) || !isset($matches[1], $matches[2], $matches[3])) {
            return null;
        }

        $identifier = $matches[1];
        $ruleClass  = $matches[2];

        $cells       = array_map(trim(...), explode('|', $matches[3]));
        $requirement = array_last($cells);
        $bundle      = $this->bundleFrom(...$cells);

        $summary = $requirement;
        $docPath = null;
        $link    = $this->link($requirement);
        if (null !== $link) {
            [$summary, $target] = $link;
            if (str_ends_with($target, '.md')) {
                // Existence is checked before resolving, not discovered by the resolve
                // failing. A row pointing at a page that is not there is a defect in THAT
                // row, and RuleDocumentationTest is the guard that reports it; letting it
                // raise here would take the whole resolver down with it, because entries()
                // parses every row on any lookup. One dead link would stop bin/rule-doc
                // answering for every other identifier — and the practitioner, who came to
                // have a failure explained, would get "An error occurred".
                $candidate = $this->repoRoot . self::DOCS_DIR . $target;
                $docPath   = is_file($candidate) ? \Safe\realpath($candidate) : null;
            }
        }

        return new RuleDocEntryDto(
            identifier: $identifier,
            ruleClass: $ruleClass,
            summary: $summary,
            bundle: $bundle,
            sourcePath: str_contains($ruleClass, '/')
                ? $this->repoRoot . self::SRC_DIR . $ruleClass . '.php'
                : $this->repoRoot . self::RULES_DIR . $ruleClass . '.php',
            docPath: $docPath,
        );
    }

    /**
     * An opt-in row carries a bundle cell before the requirement; an always-on
     * row carries none and is therefore in rules-default.neon.
     */
    private function bundleFrom(string ...$cells): string
    {
        $cells = array_values($cells);
        if (\count($cells) < 2) {
            return 'rules-default.neon';
        }

        $bundleCell = $cells[0];
        $link       = $this->link($bundleCell);
        if (null !== $link) {
            return basename($link[1]);
        }

        return $bundleCell;
    }

    /**
     * The first markdown link in a cell, as [text, target].
     *
     * @return array{string, string}|null
     */
    private function link(string $cell): ?array
    {
        if (1 !== \Safe\preg_match(self::LINK_PATTERN, $cell, $link) || !isset($link[1], $link[2])) {
            return null;
        }

        return [$link[1], $link[2]];
    }
}
