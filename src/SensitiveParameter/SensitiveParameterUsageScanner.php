<?php

declare(strict_types=1);

namespace LTS\PHPQA\SensitiveParameter;

use LTS\PHPQA\Helper;
use PhpParser\Node\Attribute;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Always-on php-qa-ci pipeline tool: asserts that the native #[\SensitiveParameter]
 * attribute is used at least once in the consumer project's src/ directory.
 *
 * WHY THIS IS A PIPELINE TOOL, NOT A PHPSTAN RULE
 * ===============================================
 * PHPStan rules (even those in php-qa-ci's rules-default.neon) are opt-in: a
 * consumer project must explicitly include the rules neon. They therefore cannot
 * be relied upon estate-wide. A pipeline tool invoked by `bin/qa` runs for every
 * consumer unconditionally, which is exactly what a security baseline check needs.
 *
 * WHAT IT DOES
 * ============
 * PHP 8.2+ ships #[\SensitiveParameter]. When set on a parameter, PHP replaces the
 * real argument with a SensitiveParameterValue placeholder in Throwable::getTrace(),
 * so credentials never leak into logs / error reporters. This tool fails the build
 * if the attribute appears NOWHERE in src/ — almost always a sign that a real
 * password / token / secret has been left unprotected.
 *
 * The scan is AST-based (nikic/php-parser), so the attribute is never matched in
 * strings or comments.
 *
 * ESCAPE HATCH
 * ============
 * A small number of projects (e.g. pure tooling libraries — php-qa-ci itself is
 * one) genuinely never handle a sensitive parameter. They opt out by setting
 * `export useSensitiveParameterCheck=0` in qaConfig/qaConfig.inc.bash.
 */
final readonly class SensitiveParameterUsageScanner
{
    /**
     * Environment variable name used as the escape hatch (1 = on, 0 = off).
     */
    public const string ESCAPE_HATCH_ENV = 'useSensitiveParameterCheck';

    /**
     * Attribute short name (last namespace segment) that marks a parameter sensitive.
     */
    private const string ATTRIBUTE_SHORT_NAME = 'SensitiveParameter';

    private Parser $parser;

    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->parser     = new ParserFactory()->createForHostVersion();
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * Pipeline entrypoint. Resolves the project's src/ directory, honours the
     * escape hatch, scans, prints a human-readable summary and returns a shell
     * exit code (0 = pass / skipped, 1 = no usage found).
     *
     * @param bool|null $useCheck when null, resolved from the ESCAPE_HATCH_ENV
     *                            environment variable (default on)
     */
    public static function main(?string $projectRootDirectory = null, ?bool $useCheck = null): int
    {
        $projectRootDirectory ??= Helper::getProjectRootDirectory();
        $useCheck             ??= self::resolveUseCheckFromEnv();

        if (false === $useCheck) {
            echo PHP_EOL
                . 'SensitiveParameter usage check is disabled for this project ('
                . self::ESCAPE_HATCH_ENV . '=0) — skipping.' . PHP_EOL;

            return 0;
        }

        $srcDirectory = $projectRootDirectory . '/src';
        if (!is_dir($srcDirectory)) {
            throw new RuntimeException(
                \sprintf('Expected a src directory to scan but none found at %s', $srcDirectory)
            );
        }

        $result = new self()->scan($srcDirectory);

        if ($result->hasUsage()) {
            echo PHP_EOL
                . \sprintf(
                    'SensitiveParameter usage check PASSED — found %d use(s) of #[\SensitiveParameter]:',
                    $result->count
                ) . PHP_EOL;
            foreach ($result->locations as $location) {
                echo '  - ' . $location . PHP_EOL;
            }

            return 0;
        }

        echo self::buildFailureMessage($srcDirectory);

        return 1;
    }

    /**
     * Scan a directory tree for #[\SensitiveParameter] occurrences.
     *
     * @throws RuntimeException on unreadable or unparseable PHP files
     */
    public function scan(string $directory): SensitiveParameterUsageResult
    {
        $locations = [];

        foreach ($this->findPhpFiles($directory) as $file) {
            foreach ($this->findSensitiveParameterAttributes($file) as $line) {
                $locations[] = $file . ':' . $line;
            }
        }

        return new SensitiveParameterUsageResult(\count($locations), $locations);
    }

    /**
     * @return list<string> absolute paths to every .php file under $directory
     */
    private function findPhpFiles(string $directory): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if (!$fileInfo->isFile()) {
                continue;
            }

            if ('php' !== $fileInfo->getExtension()) {
                continue;
            }

            $files[] = $fileInfo->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<int> the start line of every #[\SensitiveParameter] occurrence in $file
     *
     * @throws RuntimeException if the file cannot be parsed
     */
    private function findSensitiveParameterAttributes(string $file): array
    {
        $code  = \Safe\file_get_contents($file);
        $stmts = $this->parser->parse($code);

        if (null === $stmts) {
            throw new RuntimeException(\sprintf('Failed to parse PHP file %s', $file));
        }

        $lines = [];
        /** @var list<Attribute> $attributes */
        $attributes = $this->nodeFinder->findInstanceOf($stmts, Attribute::class);
        foreach ($attributes as $attribute) {
            if (self::ATTRIBUTE_SHORT_NAME === $attribute->name->getLast()) {
                $lines[] = $attribute->getStartLine();
            }
        }

        return $lines;
    }

    private static function resolveUseCheckFromEnv(): bool
    {
        $raw = getenv(self::ESCAPE_HATCH_ENV);

        // Unset or any value other than the explicit opt-out "0" means enabled.
        return '0' !== $raw;
    }

    private static function buildFailureMessage(string $srcDirectory): string
    {
        return PHP_EOL
            . '==============================================================================' . PHP_EOL
            . 'SensitiveParameter usage check FAILED' . PHP_EOL
            . '==============================================================================' . PHP_EOL
            . PHP_EOL
            . 'No #[\SensitiveParameter] attribute was found anywhere in:' . PHP_EOL
            . '  ' . $srcDirectory . PHP_EOL
            . PHP_EOL
            . 'PHP 8.2+ replaces a #[\SensitiveParameter] argument with a redacted' . PHP_EOL
            . 'placeholder in stack traces, keeping passwords / tokens / secrets out of' . PHP_EOL
            . 'logs and error reporters. Most projects handle such a value somewhere and' . PHP_EOL
            . 'should mark that parameter, e.g.:' . PHP_EOL
            . PHP_EOL
            . '    public function login(string $user, #[\SensitiveParameter] string $password) {}' . PHP_EOL
            . PHP_EOL
            . 'If this project genuinely never handles a sensitive parameter, opt out by' . PHP_EOL
            . 'adding the following to qaConfig/qaConfig.inc.bash:' . PHP_EOL
            . PHP_EOL
            . '    export ' . self::ESCAPE_HATCH_ENV . '=0' . PHP_EOL
            . PHP_EOL
            . '==============================================================================' . PHP_EOL;
    }
}
