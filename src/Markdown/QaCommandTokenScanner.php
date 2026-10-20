<?php

declare(strict_types=1);

namespace LTS\PHPQA\Markdown;

use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every `qa -t <token>` command written in the documentation names a tool
 * `bin/qa` accepts.
 *
 * Agents copy these commands verbatim. A token the registry rejects stops the
 * agent with "Invalid tool" at the moment it was told to verify its work, and
 * nothing else reads the strings: the registry is the truth and the documents
 * are hand-written. The registry passed in is the run's own, so a token a
 * project adds in qaConfig/pipeline.php is accepted in that project.
 *
 * Only an unmistakable invocation is read: `qa` as a command word, optionally
 * behind a bin-dir prefix (`vendor/bin/`, `bin/`, `{bin}/`), followed by
 * whitespace-separated options and then `-t` with a literal token. A
 * placeholder (`<tool>`, `{tool}`) is not a token.
 *
 * @internal
 */
final readonly class QaCommandTokenScanner
{
    /**
     * The command up to `-t` (group 1), what follows `-t` (group 2) and the
     * options after it (group 3). The options read on either side of `-t` are
     * the ones bin/qa takes: long flags and `-p <path>`.
     */
    private const string COMMAND = '~(?<![\w./{}$-])((?:[\w.{}$-]+/)*qa' . self::OPTIONS . '[ \t]+-t[ \t]+)([^\s`\'"]+)(' . self::OPTIONS . ')~';

    /** Zero or more of bin/qa's options other than `-t`: long flags and `-p <path>`. */
    private const string OPTIONS = '(?:[ \t]+(?:--[\w-]+|-p[ \t]+[^\s`\'"]+))*';

    /** A `-p` option among the options read. */
    private const string PATH_OPTION = '~(?:^|[ \t])-p[ \t]~';

    /** A literal token, and only trailing punctuation after it; anything else is a placeholder. */
    private const string LITERAL_TOKEN = '~^([A-Za-z]\w*)[.,;:)\]]*$~';

    /** A line of a document that registers tools or phases, as a qaConfig/pipeline.php example does. */
    private const string REGISTRATION = '~new\s+(?:ToolDefinitionDto|PhaseDto)\s*\(~';

    /** Single files in scope, project-relative. */
    private const array FILES = ['README.md', 'CLAUDE.md'];

    /** Trees in scope, project-relative: the documentation and everything an agent is handed. */
    private const array TREES = ['docs', 'CLAUDE', '.claude/agents', '.claude/skills', 'qaConfig', 'templates'];

    /**
     * Plan folders record what was run on the day the work was done; they are a
     * record, not an instruction, and a renamed token in one is history.
     */
    private const string PLANS = 'CLAUDE/Plan/';

    public function __construct(private ToolRegistry $registry)
    {
    }

    /**
     * @return list<QaCommandTokenFinding>
     */
    public function scan(string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $findings    = [];
        foreach ($this->files($projectRoot) as $relative) {
            foreach ($this->scanText(\Safe\file_get_contents($projectRoot . '/' . $relative), $relative) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Scan one document. Fenced blocks are read: that is where commands are written.
     *
     * @return list<QaCommandTokenFinding>
     */
    public function scanText(string $markdown, string $file = ''): array
    {
        $lines    = explode("\n", $markdown);
        $declared = $this->declaredTokens(...$lines);
        $findings = [];
        foreach ($lines as $index => $line) {
            foreach ($this->matches(self::COMMAND, $line) as [, $before, $written, $after]) {
                $literal = $this->matches(self::LITERAL_TOKEN, $written);
                if ([] === $literal) {
                    continue;
                }

                $token   = $literal[0][1];
                $refusal = $this->refusal($token, 1 === \Safe\preg_match(self::PATH_OPTION, $before . $after), ...$declared);
                if (null !== $refusal) {
                    $findings[] = new QaCommandTokenFinding($file, $index + 1, $token, $before . $written . $after, $refusal[0], $refusal[1]);
                }
            }
        }

        return $findings;
    }

    /**
     * Every match of $pattern in $subject, each as its full match followed by
     * every group, an unmatched group as ''.
     *
     * @param literal-string $pattern one of this class's own patterns
     *
     * @return list<list<string>>
     */
    private function matches(string $pattern, string $subject): array
    {
        $sets = [];
        \Safe\preg_match_all($pattern, $subject, $sets, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        if (!\is_array($sets)) {
            return [];
        }

        $matches = [];
        foreach ($sets as $set) {
            if (!\is_array($set)) {
                continue;
            }

            $groups = [];
            foreach ($set as $group) {
                $groups[] = \is_string($group) ? $group : '';
            }

            $matches[] = $groups;
        }

        return $matches;
    }

    /**
     * Why bin/qa refuses `-t $token`, and the hint, or null when it runs it.
     *
     * @return array{string, ?string}|null
     */
    private function refusal(string $token, bool $hasPath, string ...$declared): ?array
    {
        if (\in_array($token, $declared, true)) {
            return null;
        }

        if (!$this->registry->accepts($token)) {
            $meant = $this->sameTokenInAnotherCase($token);
            $hint  = null === $meant ? $this->suggestion($token) : \sprintf('did you mean -t %s?', $meant);
            if (null !== $meant && $hasPath && !$this->registry->supportsPaths($meant)) {
                $hint .= \sprintf(' It takes no -p; %s', $this->pathTakingTools($meant) ?? 'run it without -p');
            }

            return [\sprintf("'%s' is not a tool bin/qa accepts", $token), $hint];
        }

        if ($hasPath && !$this->registry->supportsPaths($token)) {
            return [\sprintf("'%s' takes no -p, so bin/qa refuses the command", $token), $this->pathTakingTools($token)];
        }

        return null;
    }

    /** For a phase runner, the tools of its phase that take a path; null for a leaf tool. */
    private function pathTakingTools(string $token): ?string
    {
        $definition = $this->registry->resolve($token);
        if (!$definition->isPhaseRunner || null === $definition->phase) {
            return null;
        }

        $tokens = [];
        foreach ($this->registry->toolsForPhase($definition->phase) as $tool) {
            if ($tool->supportsPaths && [] !== $tool->aliases) {
                $tokens[] = '-t ' . $tool->aliases[0];
            }
        }

        return [] === $tokens ? null : 'its path-taking tools take -p: ' . implode(', ', $tokens);
    }

    /**
     * The tokens the document itself registers, in a pipeline.php example: a
     * reader is shown how to make them valid, so they are valid in that document.
     *
     * @return list<string>
     */
    private function declaredTokens(string ...$lines): array
    {
        $tokens = [];
        foreach ($lines as $line) {
            if (1 !== \Safe\preg_match(self::REGISTRATION, $line)) {
                continue;
            }

            foreach ($this->matches('~\[([^\]]*)\]~', $line) as [, $array]) {
                foreach ($this->matches("~'([^']+)'~", $array) as [, $token]) {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /** The accepted token spelled the same but for case: tokens are case-sensitive. */
    private function sameTokenInAnotherCase(string $token): ?string
    {
        foreach ($this->registry->all() as $definition) {
            foreach ($definition->aliases as $alias) {
                if (0 === strcasecmp($alias, $token)) {
                    return $alias;
                }
            }
        }

        return null;
    }

    /** The tokens of the lane whose canonical name the writer used instead of a token. */
    private function suggestion(string $token): ?string
    {
        foreach ($this->registry->all() as $definition) {
            if (0 === strcasecmp($definition->name, $token) && [] !== $definition->aliases) {
                $first = $definition->aliases[0];
                $rest  = array_map(static fn (string $alias): string => '-t ' . $alias, \array_slice($definition->aliases, 1));

                return \sprintf('did you mean -t %s%s?', $first, [] === $rest ? '' : ' (or ' . implode(', ', $rest) . ')');
            }
        }

        return null;
    }

    /**
     * @return list<string> project-relative, sorted
     */
    private function files(string $projectRoot): array
    {
        $files = [];
        foreach (self::FILES as $file) {
            if (is_file($projectRoot . '/' . $file)) {
                $files[] = $file;
            }
        }

        foreach (self::TREES as $tree) {
            if (!is_dir($projectRoot . '/' . $tree)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot . '/' . $tree, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (!$entry instanceof SplFileInfo || !$entry->isFile() || 'md' !== $entry->getExtension()) {
                    continue;
                }

                $relative = substr($entry->getPathname(), \strlen($projectRoot) + 1);
                if (!str_starts_with($relative, self::PLANS)) {
                    $files[] = $relative;
                }
            }
        }

        sort($files);

        return $files;
    }
}
