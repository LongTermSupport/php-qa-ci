<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * The rule for how a remote action is named in a workflow this repository runs or ships, and in the
 * documentation that teaches one: a full commit SHA with the release it is beside it, never a tag.
 *
 * A tag is a pointer its owner can move, so whoever controls the action's repository decides what
 * runs. A reference to an action in this repository (`./`) is a path, not a tag, and is exempt.
 */
final readonly class ActionPins
{
    /** `uses: <reference>`, the reference being everything up to a comment or the end of the line. */
    private const string USES = '/^\s*-?\s*uses:\s*([^\s#]+)/';

    /** `owner/repo[/path]@<40 hex digits>` followed by the release it is, as `# v1.2.3`. */
    private const string PINNED = '/^\s*-?\s*uses:\s*[A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+@[0-9a-f]{40}\s+#\s+v\d+(\.\d+)*\s*$/';

    private function __construct()
    {
    }

    /**
     * The remote references on lines that start with `uses:`, each that is not a commit SHA with its
     * release beside it.
     *
     * @return list<string> one message per reference
     */
    public static function unpinnedLines(string $text): array
    {
        $problems = [];
        foreach (explode("\n", $text) as $line) {
            if (1 !== \Safe\preg_match(self::USES, $line, $match) || !isset($match[1])) {
                continue;
            }

            if (str_starts_with($match[1], './')) {
                continue;
            }

            if (1 !== \Safe\preg_match(self::PINNED, $line)) {
                $problems[] = self::message($match[1]);
            }
        }

        return $problems;
    }

    /**
     * Every remote reference the YAML of a workflow holds, at any depth and written any way, that is
     * not matched by a line in the pinned shape.
     *
     * The YAML says what the workflow names; the lines say how it is written. A reference the YAML
     * holds that no pinned line accounts for is a tag, a branch, a commit with no release beside
     * it, or a form (a flow mapping, a quoted value) this repository does not write a step in.
     *
     * @return list<string> one message per reference
     */
    public static function unpinnedInWorkflow(string $workflow): array
    {
        $pinned   = self::pinnedReferences($workflow);
        $problems = [];
        foreach (self::references(Yaml::parse($workflow)) as $reference) {
            if (str_starts_with($reference, './')) {
                continue;
            }

            $index = array_search($reference, $pinned, true);
            if (false === $index) {
                $problems[] = self::message($reference);

                continue;
            }

            unset($pinned[$index]);
        }

        return $problems;
    }

    /** @return list<string> the reference on each line in the pinned shape */
    private static function pinnedReferences(string $workflow): array
    {
        $references = [];
        foreach (explode("\n", $workflow) as $line) {
            if (1 === \Safe\preg_match(self::PINNED, $line) && 1 === \Safe\preg_match(self::USES, $line, $match) && isset($match[1])) {
                $references[] = $match[1];
            }
        }

        return $references;
    }

    /** @return list<string> the value of every `uses` key under a parsed node, in document order */
    private static function references(mixed $node): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $references = [];
        foreach ($node as $key => $value) {
            if ('uses' === $key && \is_string($value)) {
                $references[] = $value;

                continue;
            }

            array_push($references, ...self::references($value));
        }

        return $references;
    }

    private static function message(string $reference): string
    {
        return $reference . ' is not a commit SHA with its release beside it';
    }
}
