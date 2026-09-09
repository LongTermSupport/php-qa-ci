<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Forbids any Symfony-consumed env var whose NAME starts with HTTP_.
 *
 * THE BUG CLASS: Symfony's EnvVarProcessor::getEnv() resolves a %env(NAME)%
 * reference as:
 *
 *     $_ENV[$name] ?? (str_starts_with($name, 'HTTP_') ? null : ($_SERVER[$name] ?? null))
 *
 * (vendor/symfony/dependency-injection/EnvVarProcessor.php ~line 170). For a
 * NAME starting with HTTP_ this SKIPS $_SERVER entirely — Symfony treats it
 * as an HTTP *request header*, not configuration — and falls through to the
 * thread-unsafe getenv() last resort and then the committed default.
 * Dotenv::populate()/doLoad() (vendor/symfony/dotenv/Dotenv.php ~lines 217,
 * 679) apply the identical carve-out. A CLI process (worker, console
 * command, cron) exposes the OS environment in $_SERVER, not $_ENV (the
 * default variables_order has no E), so an HTTP_-prefixed name resolves
 * EMPTY there no matter how correctly it is set by deploy/compose config —
 * silently, with no exception and no log line.
 *
 * WHY THIS IS A PHPSTAN RULE DESPITE THE VIOLATION LIVING IN YAML/.env:
 * PHPStan only ever parses PHP source, so it has no native path to a YAML
 * key or a .env line. This rule reaches those files itself — via plain
 * file/directory reads, not by shelling out (php-qa-ci's own
 * ForbidDangerousFunctionsRule bans shell_exec/proc_open/exec) — triggered
 * once per PHPStan run by binding to {@see FileNode}, which fires once for
 * every PHP file PHPStan analyses. Binding to FileNode (rather than some
 * rarer node type) is deliberate: FileNode is guaranteed to fire at least
 * once for any project with >=1 analysed PHP file, so this check can never
 * silently go quiet the way a rule bound to an uncommon node could. The
 * $alreadyScanned instance flag then limits the actual filesystem scan to
 * once per rule instance (once per PHPStan worker process — PHPStan may run
 * several parallel workers, each independently performing one full scan;
 * see the docblock on $alreadyScanned).
 *
 * See: docs/phpstan-rules/forbid-http-prefixed-env-vars.md for fix documentation.
 *
 * @implements Rule<FileNode>
 */
final class ForbidHttpPrefixedEnvVarsRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.httpPrefixedEnvVars';

    private bool $alreadyScanned = false;

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->alreadyScanned) {
            return [];
        }

        $this->alreadyScanned = true;

        if (!$this->symfonyEnvCarveOutApplies()) {
            return [];
        }

        $offenders = [
            ...$this->scanConfigDir($this->projectRoot . '/config'),
            ...$this->scanEnvFiles($this->projectRoot),
        ];

        // Deterministic order: RecursiveDirectoryIterator does not guarantee a
        // stable file order across filesystems, so without sorting, otherwise
        // byte-identical runs could report the same offenders in a different
        // sequence. Sorting by (file, line, name) makes the report reproducible.
        usort($offenders, static fn (array $a, array $b): int => $a <=> $b);

        $errors = [];
        foreach ($offenders as $offender) {
            [$file, $line, $name] = $offender;
            $errors[]             = RuleErrorBuilder::message(\sprintf(
                '%s:%d: env var \'%s\' is consumed by Symfony but named with an HTTP_ prefix — '
                . 'Symfony treats HTTP_* as an HTTP request header and will NOT read it from '
                . '$_SERVER, so a CLI process (worker/console/cron) resolves it EMPTY. Rename it '
                . 'off the HTTP_ prefix (e.g. HTTP_API_KEY -> APP_API_KEY).',
                $file,
                $line,
                $name,
            ))->identifier(self::IDENTIFIER)->build();
        }

        return $errors;
    }

    /**
     * The carve-out is Symfony-specific, so a consumer with neither
     * dependency-injection nor dotenv installed has nothing for this rule to
     * check — skip cleanly rather than scanning a config/ dir that has
     * nothing to do with Symfony env resolution.
     */
    private function symfonyEnvCarveOutApplies(): bool
    {
        return is_dir($this->projectRoot . '/vendor/symfony/dependency-injection')
            || is_dir($this->projectRoot . '/vendor/symfony/dotenv');
    }

    /**
     * Scans every file under $dir for `%env(NAME)%` references (any
     * processor prefix — int:/bool:/resolve:/csv:/... — the bug bites
     * regardless of processor) and `env(NAME):` parameter defaults, flagging
     * every HTTP_-prefixed NAME. reference.php is a generated PHPDoc
     * reference dump (env tokens appear only in comments there), not active
     * config, so it is excluded — mirroring the project-local bash gate this
     * rule generalises.
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function scanConfigDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $offenders = [];
        foreach ($this->walkFiles($dir) as $fileInfo) {
            if ('reference.php' === $fileInfo->getFilename()) {
                continue;
            }

            $path  = $fileInfo->getPathname();
            $lines = explode("\n", \Safe\file_get_contents($path));

            foreach ($lines as $lineIndex => $lineContent) {
                $lineNumber = $lineIndex + 1;

                foreach ($this->matchAllGroup1('/%env\(([^%]*)\)%/', $lineContent) as $expr) {
                    $name = $this->bareNameFromEnvExpression($expr);
                    if (str_starts_with($name, 'HTTP_')) {
                        $offenders[] = [$path, $lineNumber, $name];
                    }
                }

                foreach ($this->matchAllGroup1('/env\(([A-Za-z_]\w*)\):/', $lineContent) as $name) {
                    if (str_starts_with($name, 'HTTP_')) {
                        $offenders[] = [$path, $lineNumber, $name];
                    }
                }
            }
        }

        return $offenders;
    }

    /**
     * Scans every `.env`/`.env.*` file at the project root for `HTTP_*=`
     * declarations. Dotenv applies the identical HTTP_ carve-out at LOAD
     * time, so a name can be broken before it is ever referenced from
     * config.
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private function scanEnvFiles(string $projectRoot): array
    {
        $offenders = [];
        foreach ($this->findEnvFilePaths($projectRoot) as $path) {
            $lines = explode("\n", \Safe\file_get_contents($path));
            foreach ($lines as $lineIndex => $lineContent) {
                if (1 !== \Safe\preg_match('/^HTTP_\w*=/', $lineContent, $matches)) {
                    continue;
                }

                // \Safe\preg_match's stub types $matches loosely (unlike PHPStan core's
                // conditional-return narrowing for native preg_match); a 1 === return
                // GUARANTEES $matches[0] is the matched string — mirrors the identical,
                // established narrowing in Markdown/LinksChecker.php::getLinks().
                /** @var array<int|string, string> $matches */
                $name        = substr($matches[0], 0, -1);
                $offenders[] = [$path, $lineIndex + 1, $name];
            }
        }

        return $offenders;
    }

    /**
     * Every `.env`/`.env.*` file at the project root that is actually a
     * file — a defensive runtime guard (not a type override) against
     * \Safe\glob's loosely-typed return, so callers get a real
     * list<string> without a suppression.
     *
     * @return list<string>
     */
    private function findEnvFilePaths(string $projectRoot): array
    {
        $candidates = [...\Safe\glob($projectRoot . '/.env'), ...\Safe\glob($projectRoot . '/.env.*')];

        $paths = [];
        foreach ($candidates as $candidate) {
            if (\is_string($candidate) && is_file($candidate)) {
                $paths[] = $candidate;
            }
        }

        return $paths;
    }

    /**
     * Strips a %env(...)% expression down to its bare NAME — the last
     * colon-separated segment, so a processor chain like
     * int:default:HTTP_FOO:FOO still yields HTTP_FOO.
     */
    private function bareNameFromEnvExpression(string $expr): string
    {
        $segments = explode(':', $expr);

        return array_last($segments);
    }

    /**
     * Runs preg_match_all and returns capture group 1 from every match,
     * filtered to genuine strings — a defensive runtime guard (not a type
     * override) against \Safe\preg_match_all's loosely-typed $matches
     * out-param, so callers get a real list<string> without a suppression.
     *
     * @return list<string>
     */
    private function matchAllGroup1(string $pattern, string $subject): array
    {
        \Safe\preg_match_all($pattern, $subject, $matches);

        $group1 = $matches[1] ?? [];
        if (!\is_array($group1)) {
            return [];
        }

        $names = [];
        foreach ($group1 as $value) {
            if (\is_string($value)) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function walkFiles(string $dir): iterable
    {
        $directoryIterator = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator          = new RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $current) {
            if (!$current instanceof SplFileInfo) {
                continue;
            }

            if ($current->isFile()) {
                yield $current;
            }
        }
    }
}
