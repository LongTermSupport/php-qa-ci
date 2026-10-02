<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

/**
 * The `subagent_full_qa_blocker` handler block php-qa-ci declares in a
 * consuming project's hooks-daemon config.
 *
 * What counts as full is the full pipeline only: `qa` with no `-t` (a single
 * lane is a working tool, and the gate is the pipeline), and the pipeline
 * pointed at the whole tree with `-p src`, `-p tests` or `-p .`. The daemon's
 * matcher cannot tell one `-t` value from another unless the value itself makes
 * a run full, which no path could then narrow, so per-lane patterns would deny
 * targeted runs; see docs/hooks-daemon-full-qa-blocker.md for the reasoning.
 *
 * @internal
 */
final readonly class FullQaBlockerBlockRenderer
{
    /** The daemon's config key for the handler, under `handlers.pre_tool_use`. */
    public const string HANDLER_KEY = 'subagent_full_qa_blocker';

    /** The comment that marks a block as php-qa-ci's to rewrite. */
    public const string MARKER = '# Managed by php-qa-ci';

    /** A lane (`-t`) or a path (`-p`) makes the run something other than the bare pipeline. */
    private const string NOT_FULL_WITH_ANY_LANE_OR_PATH = '  read_only_flags: ["-t", "-p", "-h"]';

    /** Matches the qa binary by its basename, whichever bin dir it is run from. */
    private const string COMMAND_QA = '  command: qa';

    /** @return list<string> the block's lines, the handler key at `$indent` spaces, nested by `$unit` */
    public function render(int $indent, int $unit, string $binDir): array
    {
        $qa = $binDir . '/qa';
        $at = static fn (int $depth, string $text): string => str_repeat(' ', $indent + $depth * $unit) . $text;

        return [
            $at(0, self::HANDLER_KEY . ':'),
            $at(1, self::MARKER . ': rewritten on composer install/update while this'),
            $at(1, '# comment stays. Delete the comment to own the block, or set enabled: false'),
            $at(1, '# to opt out. See php-qa-ci docs/hooks-daemon-full-qa-blocker.md.'),
            $at(1, 'enabled: true'),
            $at(1, 'priority: 32'),
            $at(1, 'options:'),
            $at(2, 'full_qa_patterns:'),
            $at(3, '# The full pipeline: qa with neither -t nor -p, from any directory.'),
            $at(3, '- id: php-qa-ci-full-pipeline'),
            $at(3, self::COMMAND_QA),
            $at(3, self::NOT_FULL_WITH_ANY_LANE_OR_PATH),
            $at(3, '# The full pipeline pointed at the whole tree: -p src, -p tests, -p .'),
            $at(3, '- id: php-qa-ci-full-pipeline-whole-tree'),
            $at(3, self::COMMAND_QA),
            $at(3, '  full_args: [src, tests, test]'),
            $at(3, '  read_only_flags: ["-t", "-h"]'),
            $at(3, '# The full pipeline through the PHP binary: php ' . $qa),
            $at(3, '- id: php-qa-ci-full-pipeline-via-php'),
            $at(3, '  command: php'),
            $at(3, \sprintf('  full_words: [%1$s, ./%1$s]', $qa)),
            $at(3, self::NOT_FULL_WITH_ANY_LANE_OR_PATH),
            $at(2, 'targeted_qa_commands:'),
            $at(3, \sprintf('- "%s -t <tool> -p <file or directory you changed>"', $qa)),
            $at(3, \sprintf('- "%s --agent-mode -t phpstan -p <file you changed>"', $qa)),
            $at(3, \sprintf('- "%s -t <tool>   (one lane; never the bare pipeline)"', $qa)),
            $at(2, 'unseen_sink_description: "php-qa-ci\'s run lock refuses a second concurrent qa run in the same project tree (exit 75); nothing else here stops a full run this handler could not read."'),
        ];
    }
}
