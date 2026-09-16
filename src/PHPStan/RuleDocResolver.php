<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;

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

    /**
     * Identifier and class cells, then the rest of the row; the trailing cells
     * vary by bundle. The identifier prefix is not pinned to this package's
     * own: a consuming project publishes its rules under its own prefix, in its
     * own index, and the row shape is the same one.
     */
    private const string ROW_PATTERN = '/^\| `([A-Za-z][A-Za-z0-9]*\.[A-Za-z0-9]+)` +\| `([A-Za-z0-9\/]+)` +\|(.*)\|\s*$/';

    /** Where a project declares the indexes carrying its own identifiers. */
    private const string PROJECT_DECLARATION = '/qaConfig/rule-docs.json';

    /** Where a row's class cell is found when it is a path — how a pipeline lane names itself. */
    private const string SRC_DIR = '/src/';

    /** Everything this package can print starts with this; anything else belongs to another catalogue. */
    private const string OWN_PREFIX = RuleIdentifierInterface::PREFIX . '.';

    /** Where PHPStan documents its own identifiers — online only, which is the recorded gap. */
    private const string PHPSTAN_CATALOGUE = 'https://phpstan.org/error-identifiers/';

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

    /**
     * @param string|null $projectRoot the consuming project, when there is one, so its own
     *                                 identifiers resolve alongside this package's
     */
    public function __construct(private string $repoRoot, private ?string $projectRoot = null)
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
        // A practitioner holds one string and cannot tell whose it is. For an
        // identifier outside this package's prefix, "unknown" is true and useless;
        // name the catalogue it belongs to, and say plainly that it is not carried
        // offline — that is the recorded gap, not something to paper over.
        if (!str_starts_with($identifier, self::OWN_PREFIX) && !isset($this->entries()[$identifier])) {
            return \sprintf(
                "%s\n\nThis is not a php-qa-ci identifier, so there is no remediation page for it here.\n"
                . "If it is one of PHPStan's own, PHPStan documents it online at:\n  %s%s\n\n"
                . "php-qa-ci does not carry PHPStan's catalogue offline; that gap is recorded in\n"
                . "composer.json under extra.defence-before-fix.known-gaps.\n",
                $identifier,
                self::PHPSTAN_CATALOGUE,
                $identifier,
            );
        }

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
        $entries = $this->entriesFrom(
            $this->repoRoot . self::INDEX,
            $this->repoRoot . self::DOCS_DIR,
            $this->repoRoot . self::RULES_DIR,
            $this->repoRoot . self::SRC_DIR,
        );

        // A project catalogue is read second and does not overwrite a shipped
        // identifier: this package owns the phpqaci.* namespace, and a project
        // shadowing one of ours would make a failure resolve to the wrong page.
        foreach ($this->projectCatalogues() as $catalogue) {
            foreach ($this->entriesFrom(...$catalogue) as $identifier => $entry) {
                $entries[$identifier] ??= $entry;
            }
        }

        return $entries;
    }

    /**
     * Every index a project declares, as the four roots a row resolves against.
     *
     * The declaration is `qaConfig/rule-docs.json`: an `indexes` list whose
     * entries are either a project-relative path to the index, or an object
     * with that `path` plus a `rulesDir` for resolving a bare class cell. Doc
     * links always resolve against the index's own directory, exactly as they
     * do in this package's index.
     *
     * Malformed or absent declarations yield nothing rather than throwing. A
     * practitioner runs this holding a failing identifier; taking the whole
     * lookup down over the shape of a config file would answer nothing at all.
     *
     * @return list<array{string, string, string, string}>
     */
    private function projectCatalogues(): array
    {
        if (null === $this->projectRoot || !is_file($this->projectRoot . self::PROJECT_DECLARATION)) {
            return [];
        }

        /** @var mixed $declared */
        $declared = json_decode(\Safe\file_get_contents($this->projectRoot . self::PROJECT_DECLARATION), true);
        if (!\is_array($declared) || !isset($declared['indexes']) || !\is_array($declared['indexes'])) {
            return [];
        }

        $catalogues = [];
        foreach ($declared['indexes'] as $entry) {
            $path     = \is_string($entry) ? $entry : null;
            $rulesDir = null;
            if (\is_array($entry)) {
                $path     = \is_string($entry['path'] ?? null) ? $entry['path'] : null;
                $rulesDir = \is_string($entry['rulesDir'] ?? null) ? $entry['rulesDir'] : null;
            }

            if (null === $path) {
                continue;
            }

            $index = $this->projectRoot . '/' . ltrim($path, '/');
            if (!is_file($index)) {
                continue;
            }

            $rulesRoot = null === $rulesDir
                ? \dirname($index) . '/'
                : $this->projectRoot . '/' . trim($rulesDir, '/') . '/';

            $catalogues[] = [$index, \dirname($index) . '/', $rulesRoot, $this->projectRoot . '/'];
        }

        return $catalogues;
    }

    /** @return array<string, RuleDocEntryDto> */
    private function entriesFrom(string $index, string $docsDir, string $rulesDir, string $srcDir): array
    {
        $entries = [];
        foreach (explode("\n", \Safe\file_get_contents($index)) as $line) {
            $entry = $this->matchRow($line, $docsDir, $rulesDir, $srcDir);
            if ($entry instanceof RuleDocEntryDto) {
                $entries[$entry->identifier] = $entry;
            }
        }

        return $entries;
    }

    private function matchRow(string $line, string $docsDir, string $rulesDir, string $srcDir): ?RuleDocEntryDto
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
                $candidate = $docsDir . $target;
                $docPath   = is_file($candidate) ? \Safe\realpath($candidate) : null;
            }
        }

        return new RuleDocEntryDto(
            identifier: $identifier,
            ruleClass: $ruleClass,
            summary: $summary,
            bundle: $bundle,
            sourcePath: str_contains($ruleClass, '/')
                ? $srcDir . $ruleClass . '.php'
                : $rulesDir . $ruleClass . '.php',
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
