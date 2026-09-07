<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto;

/**
 * Resolves a bundled rule identifier, as printed in a PHPStan failure, to the
 * rule's documentation. Works offline from the installed package: the index in
 * docs/phpstan-rules/README.md is the single source, and each row names the
 * rule class and, where one exists, its remediation page.
 *
 * @internal
 */
final readonly class RuleDocResolver
{
    private const string INDEX = '/docs/phpstan-rules/README.md';

    private const string RULES_DIR = '/src/PHPStan/Rules/';

    private const string DOCS_DIR = '/docs/phpstan-rules/';

    private const string ROW_PATTERN = '/^\| `(phpqaci\.[A-Za-z0-9]+)` +\| `([A-Za-z0-9]+)` +\|(.*)\|\s*$/';

    private const string LINK_PATTERN = '/\[([^\]]+)\]\(([^)]+)\)/';

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
        $requirement = $cells[array_key_last($cells)];
        $bundle      = $this->bundleFrom(...$cells);

        $summary = $requirement;
        $docPath = null;
        $link    = $this->link($requirement);
        if (null !== $link) {
            [$summary, $target] = $link;
            if (str_ends_with($target, '.md') && !str_contains($target, '/')) {
                $docPath = $this->repoRoot . self::DOCS_DIR . $target;
            }
        }

        return new RuleDocEntryDto(
            identifier: $identifier,
            ruleClass: $ruleClass,
            summary: $summary,
            bundle: $bundle,
            sourcePath: $this->repoRoot . self::RULES_DIR . $ruleClass . '.php',
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
