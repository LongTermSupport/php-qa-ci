<?php

declare(strict_types=1);

namespace LTS\PHPQA\Markdown;

use LTS\PHPQA\Helper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Documentation must describe its subject, not itself.
 *
 * A reader opens a page to learn what the tool does now. A sentence about what the
 * page used to say returns nothing they can act on, and rots in a way ordinary prose
 * does not: it is a claim about a state that recedes with every commit, and nothing
 * will ever flag it stale because it was never true of the present. Git records that
 * losslessly and a commit message is where it belongs.
 *
 * The class is drawn at SELF-reference, deliberately. "Previously the pipeline was
 * Bash, now it is PHP" is the entire job of an upgrade guide and must never be
 * reported; "the previous version of this section listed seventeen rules" is about
 * the paperwork. The patterns below therefore all require the document to refer to
 * itself, never merely to the past.
 *
 * @internal
 */
final readonly class DocumentSelfReferenceScanner
{
    /**
     * Each pattern names a shape of self-reference. They are matched against a
     * paragraph flattened to one line, because this corpus is hard-wrapped and the
     * phrases routinely straddle a newline — a line-oriented match is blind to most
     * real instances while still passing a single-line fixture.
     *
     * @var list<non-empty-string>
     */
    private const array PATTERNS = [
        // "the previous version of this section/page/README ..."
        '/previous version of (?:this|the) (?:section|page|readme|document|file)/',
        // "17 rules at time of writing" — a hedge about the page's own currency.
        '/at (?:the )?time of writing/',
        // "this page used to ...", "this section has been moved", "this README no longer lists"
        '/this (?:section|page|readme|document|file) (?:used to|previously|was (?:previously|formerly)'
            . '|has been (?:moved|rewritten|renamed|replaced|removed)'
            . '|no longer (?:lists|documents|describes|contains|mentions))/',
        // "a numbered copy here was maintained by hand", "the list here had already lost ..."
        '/(?:copy|list|version|table) here (?:was maintained by hand|was maintained|had already lost|has drifted)/',
    ];

    /** The consumer-facing documentation tree; plan folders and remote-docs are out of scope by design. */
    private const string DOCS_DIR = '/docs';

    /** The one document in scope that lives outside the docs tree. */
    private const string README = '/README.md';

    /**
     * Entry point for the lane and for `bin/`: prints findings and returns an exit code.
     */
    public static function main(?string $projectRoot = null): int
    {
        $projectRoot ??= Helper::getProjectRootDirectory();
        $findings = new self()->scan($projectRoot);
        if ([] === $findings) {
            return 0;
        }

        foreach ($findings as $finding) {
            printf(
                '%s:%d
    says %s — that is about this document, not about what it documents
    %s

',
                $finding->file,
                $finding->line,
                var_export($finding->matched, true),
                $finding->context,
            );
        }

        printf("%d place(s) where a document describes itself rather than its subject.\n", \count($findings));

        return 1;
    }

    /**
     * @return list<DocumentSelfReferenceFinding>
     */
    public function scan(string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $findings    = [];
        foreach ($this->files($projectRoot) as $file) {
            $relative = ltrim(str_replace($projectRoot, '', $file), '/');
            foreach ($this->scanOneFile(\Safe\file_get_contents($file), $relative) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Scan a single blob of markdown, for a caller that already has the text.
     *
     * @return list<DocumentSelfReferenceFinding>
     */
    public function scanText(string $markdown, string $file = ''): array
    {
        return $this->scanOneFile($markdown, $file);
    }

    /**
     * @return list<DocumentSelfReferenceFinding>
     */
    private function scanOneFile(string $markdown, string $file): array
    {
        $lines    = explode("\n", $this->withoutFencedBlocks($markdown));
        $findings = [];
        $current  = [];
        $startsAt = 1;

        foreach ($lines as $index => $line) {
            if ('' === trim($line)) {
                foreach ($this->scanParagraph($startsAt, $file, ...$current) as $finding) {
                    $findings[] = $finding;
                }

                $current = [];

                continue;
            }

            if ([] === $current) {
                $startsAt = $index + 1;
            }

            $current[] = $line;
        }

        foreach ($this->scanParagraph($startsAt, $file, ...$current) as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * @return list<DocumentSelfReferenceFinding>
     */
    private function scanParagraph(int $startsAt, string $file, string ...$paragraphLines): array
    {
        $collapsed = \Safe\preg_replace('/\s+/', ' ', implode(' ', $paragraphLines));
        if (!\is_string($collapsed)) {
            return [];
        }

        $flat = trim($collapsed);
        if ('' === $flat) {
            return [];
        }

        // ASCII-only by design: every pattern is English prose, so this buys the same
        // result as mb_strtolower() without adding ext-mbstring to a consumer's requires.
        // Inline code spans go the same way as fenced blocks: a phrase in backticks is
        // being quoted, not asserted, which is how a page names the construction it forbids.
        $withoutInlineCode = \Safe\preg_replace('/`[^`]*`/', ' ', $flat);
        if (!\is_string($withoutInlineCode)) {
            return [];
        }

        $haystack = strtolower($withoutInlineCode);
        $findings = [];
        foreach (self::PATTERNS as $pattern) {
            $matches = [];
            if (1 === \Safe\preg_match($pattern, $haystack, $matches) && isset($matches[0])) {
                $findings[] = new DocumentSelfReferenceFinding($file, $startsAt, $matches[0], $flat);
            }
        }

        return $findings;
    }

    /**
     * Blank out fenced code blocks, preserving the line count so reported line numbers
     * stay true. A rule about how prose is written will otherwise report the examples in
     * its own documentation page, and those are not instances.
     */
    private function withoutFencedBlocks(string $markdown): string
    {
        $lines  = explode("\n", $markdown);
        $inside = false;
        foreach ($lines as $index => $line) {
            if (1 === \Safe\preg_match('/^\s*(?:```|~~~)/', $line)) {
                $inside        = !$inside;
                $lines[$index] = '';

                continue;
            }

            if ($inside) {
                $lines[$index] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function files(string $projectRoot): array
    {
        $files = [];
        if (is_file($projectRoot . self::README)) {
            $files[] = $projectRoot . self::README;
        }

        $docs = $projectRoot . self::DOCS_DIR;
        if (!is_dir($docs)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            $path = $entry->getPathname();
            if (str_ends_with($path, '.md')) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }
}
