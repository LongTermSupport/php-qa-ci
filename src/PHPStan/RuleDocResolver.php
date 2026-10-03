<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;

/**
 * Resolves a bundled rule identifier, as printed in a PHPStan failure, to the
 * rule's documentation. Works offline from the installed package: the index in
 * docs/phpstan-rules/README.md is the single source, and each row names the
 * rule class and, where one exists, its remediation page. Pipeline lanes that
 * print an identifier of their own are rows in the same index, with the class
 * given as a path under src/, so one lookup covers everything the package can
 * print. An identifier from another catalogue resolves too: PHPStan's own and
 * its first-party extensions' from the pages carried under vendor-docs/phpstan/
 * at the shipped phar's version, and an installed extension that publishes no
 * pages from the ones this package writes for it under EXTENSION_DOCS.
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

    /**
     * Column headings that name the cell describing what a rule forbids. A
     * project puts that cell wherever it likes and commonly ends the row with
     * provenance instead, so reading the trailing cell answers "Plan 00032"
     * rather than the rule. An index using none of these keeps the trailing
     * cell, which is what this package's own index relies on.
     */
    private const array SUMMARY_HEADINGS = ['forbids', 'summary', 'description', 'what'];

    /** Identifier and class occupy the first two cells; a trailing-cell index counts from there. */
    private const int LEADING_CELLS = 2;

    /** Where a row's class cell is found when it is a path — how a pipeline lane names itself. */
    private const string SRC_DIR = '/src/';

    /** Everything this package can print starts with this; anything else belongs to another catalogue. */
    private const string OWN_PREFIX = RuleIdentifierInterface::PREFIX . '.';

    /** Where PHPStan documents its own identifiers online, named alongside the page carried offline. */
    private const string PHPSTAN_CATALOGUE = 'https://phpstan.org/error-identifiers/';

    /** Pages, one per identifier, for the installed extensions whose upstream publishes none. */
    private const string EXTENSION_DOCS = '/docs/phpstan-extension-rules/';

    /** A carried PHPStan page opens with front matter; its summary line is the one worth printing. */
    private const string FRONT_MATTER = '/\A---\n(.*?)\n---\n/s';

    /** The front-matter line holding that summary, quoted as YAML. */
    private const string SHORT_DESCRIPTION = '/^shortDescription:\s*"(.*)"\s*$/m';

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

    /**
     * The page for an identifier from a catalogue other than this package's:
     * PHPStan's carried page, else this package's page for an installed
     * extension, else null. Only a string shaped as an identifier is looked
     * up, so none can name a path outside the catalogues.
     */
    public function foreignDocPath(string $identifier): ?string
    {
        $catalogue = new PhpstanDocsCatalogue($this->repoRoot);
        $page      = $catalogue->pagePath($identifier);
        if (null !== $page || 1 !== \Safe\preg_match(PhpstanDocsCatalogue::IDENTIFIER_PATTERN, $identifier)) {
            return $page;
        }

        $extensionPage = $this->repoRoot . self::EXTENSION_DOCS . $identifier . '.md';

        return is_file($extensionPage) ? \Safe\realpath($extensionPage) : null;
    }

    public function render(string $identifier): string
    {
        // A practitioner holds one string and cannot tell whose it is, so an
        // identifier outside this package's prefix is answered from whichever
        // catalogue carries it, and one that none carries says so and names
        // PHPStan's online catalogue rather than "unknown".
        if (!str_starts_with($identifier, self::OWN_PREFIX) && !isset($this->entries()[$identifier])) {
            return $this->renderForeign($identifier);
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

    private function renderForeign(string $identifier): string
    {
        $catalogue = new PhpstanDocsCatalogue($this->repoRoot);
        $page      = $this->foreignDocPath($identifier);
        if (null === $page) {
            return \sprintf(
                "%s\n\nThis identifier is in none of the catalogues php-qa-ci carries offline: its own,\n"
                . "PHPStan's at %s, and the pages for the PHPStan extensions it installs. A newer PHPStan\n"
                . "or an extension php-qa-ci does not ship may print it. PHPStan documents its own at:\n  %s%s\n",
                $identifier,
                $catalogue->carriedVersion() ?? '(none carried)',
                self::PHPSTAN_CATALOGUE,
                $identifier,
            );
        }

        $markdown = \Safe\file_get_contents($page);
        if (!str_starts_with($page, \Safe\realpath($this->repoRoot . '/' . PhpstanDocsCatalogue::PAGES) . '/')) {
            return \sprintf("%s\n\nCatalogue: php-qa-ci's page for a PHPStan extension it installs\n\n%s", $identifier, $markdown);
        }

        $summary = '';
        if (1 === \Safe\preg_match(self::FRONT_MATTER, $markdown, $frontMatter) && isset($frontMatter[0], $frontMatter[1])) {
            $markdown = substr($markdown, \strlen($frontMatter[0]));
            if (1 === \Safe\preg_match(self::SHORT_DESCRIPTION, $frontMatter[1], $description) && isset($description[1])) {
                $summary = \sprintf("Summary:   %s\n", stripslashes($description[1]));
            }
        }

        return \sprintf(
            "%s\n\nCatalogue: PHPStan's, carried offline in %s (%s, alongside phpstan.phar %s)\n%sOnline:    %s%s\n\n%s",
            $identifier,
            PhpstanDocsCatalogue::DIRECTORY,
            $catalogue->carriedSource()  ?? 'source unrecorded',
            $catalogue->carriedVersion() ?? '(none)',
            $summary,
            self::PHPSTAN_CATALOGUE,
            $identifier,
            ltrim($markdown),
        );
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

        $declared = \Safe\json_decode(\Safe\file_get_contents($this->projectRoot . self::PROJECT_DECLARATION), true);
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

        // Tracked per table, not per file: one index can carry several tables
        // with different columns, and a header held across a blank line would
        // read the next table by the previous one's shape.
        $summaryIndex = null;

        foreach (explode("\n", \Safe\file_get_contents($index)) as $line) {
            if (!str_starts_with(ltrim($line), '|')) {
                $summaryIndex = null;

                continue;
            }

            $entry = $this->matchRow($line, $docsDir, $rulesDir, $srcDir, $summaryIndex);
            if ($entry instanceof RuleDocEntryDto) {
                $entries[$entry->identifier] = $entry;

                continue;
            }

            $heading = $this->summaryIndexFrom($line);
            if (null !== $heading) {
                $summaryIndex = $heading;
            }
        }

        return $entries;
    }

    /**
     * The trailing-cell index a header row names as the summary, or null when
     * the row is not a header or names none of the recognised headings.
     */
    private function summaryIndexFrom(string $line): ?int
    {
        $cells = array_values(array_filter(
            array_map(trim(...), explode('|', $line)),
            static fn (string $cell): bool => '' !== $cell,
        ));

        foreach ($cells as $position => $cell) {
            if ($position >= self::LEADING_CELLS && \in_array(strtolower($cell), self::SUMMARY_HEADINGS, true)) {
                return $position - self::LEADING_CELLS;
            }
        }

        return null;
    }

    private function matchRow(string $line, string $docsDir, string $rulesDir, string $srcDir, ?int $summaryIndex): ?RuleDocEntryDto
    {
        if (1 !== \Safe\preg_match(self::ROW_PATTERN, $line, $matches) || !isset($matches[1], $matches[2], $matches[3])) {
            return null;
        }

        $identifier = $matches[1];
        $ruleClass  = $matches[2];

        $cells       = array_map(trim(...), explode('|', $matches[3]));
        $requirement = null !== $summaryIndex && isset($cells[$summaryIndex])
            ? $cells[$summaryIndex]
            : array_last($cells);
        $bundle = $this->bundleFrom($summaryIndex, ...$cells);

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
     *
     * Where a header named the summary column, that cell is removed first: a
     * project whose summary sits in cell 0 would otherwise have its own rule
     * description reported as the bundle it belongs to.
     */
    private function bundleFrom(?int $summaryIndex, string ...$cells): string
    {
        $cells = array_values($cells);
        if (null !== $summaryIndex && isset($cells[$summaryIndex])) {
            unset($cells[$summaryIndex]);
            $cells = array_values($cells);
        }

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
