<?php

declare(strict_types=1);

namespace LTS\PHPQA\AgentContext;

/**
 * The generated active-defences region inside a document an agent loads
 * (toolchain specification 7.2: "a delimited region marked as generated").
 * The region runs from START to END inclusive and is replaced whole. A
 * document with no region, two of them, or an END before its START is not
 * touched: null says so, and the caller reports it rather than guessing
 * where the region should go.
 *
 * @api
 */
final readonly class AgentContextRegion
{
    public const string START = '<!-- phpqaci-active-defences:start -->';

    public const string END = '<!-- phpqaci-active-defences:end -->';

    /** $document with its region replaced by $section, or null when it has no single well-formed region. */
    public function replace(string $document, string $section): ?string
    {
        $bounds = $this->bounds($document);
        if (null === $bounds) {
            return null;
        }

        return substr($document, 0, $bounds[0]) . $section . substr($document, $bounds[1]);
    }

    /** The region as it stands, markers included, or null when there is no single well-formed one. */
    public function current(string $document): ?string
    {
        $bounds = $this->bounds($document);

        return null === $bounds ? null : substr($document, $bounds[0], $bounds[1] - $bounds[0]);
    }

    /** @return array{int, int}|null the region's start offset and the offset just past its END */
    private function bounds(string $document): ?array
    {
        $aroundStart = explode(self::START, $document);
        if (2 !== \count($aroundStart) || str_contains($aroundStart[0], self::END) || 1 !== substr_count($aroundStart[1], self::END)) {
            return null;
        }

        $start = \strlen($aroundStart[0]);
        $inner = explode(self::END, $aroundStart[1])[0];

        return [$start, $start + \strlen(self::START) + \strlen($inner) + \strlen(self::END)];
    }
}
