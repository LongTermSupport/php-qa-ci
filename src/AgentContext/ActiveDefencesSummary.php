<?php

declare(strict_types=1);

namespace LTS\PHPQA\AgentContext;

use LTS\PHPQA\PHPStan\Dto\ActiveDefencesListingDto;

/**
 * Toolchain specification 7.1: the active defences as an agent should read
 * them, one terse line each, phrased as a standing rule, with the identifier
 * and the page it resolves to. Built from the same listing `bin/rules`
 * prints, so it describes the project's configuration rather than a
 * document about it. A phase runner is not a defence and has no line. Rules
 * with no identifier of their own are gathered rather than listed one by one,
 * which keeps the section bounded: the project's own by class name in one
 * line, an extension's (phpstan-strict-rules registers dozens) as a count in
 * one line per package.
 *
 * @api
 */
final readonly class ActiveDefencesSummary
{
    private const string HEADER = "## php-qa-ci — Active defences\n\n"
        . "Generated from this project's active configuration by `rules --write-agent-summary`; do not\n"
        . "edit. Each line is a standing rule the pipeline enforces; `rule-doc <identifier>` (Composer bin\n"
        . "dir) prints its page offline.\n\n";

    public function render(ActiveDefencesListingDto $listing, string $projectRoot): string
    {
        $lines      = [];
        $unnamed    = [];
        $byPackage  = [];
        foreach ($listing->rules as $rule) {
            if (null !== $rule->identifier) {
                $summary = $rule->summary ?? (null === $rule->package ? 'no summary declared' : \sprintf('a rule from `%s`', $rule->package));
                $lines[] = $this->line($rule->identifier, $summary, $this->page($rule->docPath, $projectRoot));
            } elseif (null === $rule->package) {
                $unnamed[] = '`' . $rule->ruleClass . '`';
            } else {
                $byPackage[$rule->package] = ($byPackage[$rule->package] ?? 0) + 1;
            }
        }

        if ([] !== $unnamed) {
            $lines[] = \sprintf("- %d rules in this project's own configuration declare no identifier: %s", \count($unnamed), implode(', ', $unnamed));
        }

        foreach ($byPackage as $package => $count) {
            $lines[] = \sprintf('- `%s` — %d rules from this PHPStan extension; their findings carry PHPStan identifiers, which `rule-doc` routes', $package, $count);
        }

        foreach ($listing->pipelineLanes as $lane) {
            if (null === $lane->identifier) {
                continue;
            }

            $notes = $this->page($lane->docPath, $projectRoot);
            if (null !== $lane->optInVariable) {
                $notes .= ('' === $notes ? '' : '; ') . \sprintf('opt-in: `%s`', $lane->optInVariable);
            }

            $lines[] = $this->line($lane->identifier, $lane->summary, $notes);
        }

        return AgentContextRegion::START . "\n" . self::HEADER . implode("\n", $lines) . "\n" . AgentContextRegion::END;
    }

    private function line(string $identifier, string $summary, string $notes): string
    {
        return \sprintf('- `%s` — %s', $identifier, $summary) . ('' === $notes ? '' : \sprintf(' (%s)', $notes));
    }

    /** The page relative to the project root when it lies inside it, so the line reads the same on every machine. */
    private function page(?string $docPath, string $projectRoot): string
    {
        if (null === $docPath) {
            return '';
        }

        $prefix = rtrim($projectRoot, '/') . '/';

        return '`' . (str_starts_with($docPath, $prefix) ? substr($docPath, \strlen($prefix)) : $docPath) . '`';
    }
}
