<?php

declare(strict_types=1);

namespace LTS\PHPQA\Arkitect;

use LTS\PHPQA\Arkitect\Dto\ProbeFiringDto;
use LTS\PHPQA\Arkitect\Dto\ProbeResultDto;
use LTS\PHPQA\Arkitect\Exception\ProbeFailedException;
use LTS\PHPQA\Pipeline\Agent\ArkitectJsonParser;
use LTS\PHPQA\Pipeline\Agent\ClassFileLocator;
use LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException;
use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Config\Exception\ProjectLayoutException;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Config\PlatformDetector;
use LTS\PHPQA\Pipeline\Config\ProjectPathsResolver;
use LTS\PHPQA\Pipeline\Lane\PhpArkitect\ArkitectEnvironment;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The single-rule harness for PHPArkitect, behind `bin/arkitect-rule <because>
 * <path>`: run the project's own rules over one fixture path and say whether
 * the rule named by its `because` clause fired there, and on which classes.
 *
 * A rule with no instance in the project's code passes the arch lane whether
 * or not it can fire. Proving it takes a fixture that breaks it, but the
 * project's entry config names its own class sets (the template hard-codes
 * `src/`), so the probe loads that config, takes every rule it registered and
 * re-adds them against the probed directory (see {@see ProbeConfigRenderer}).
 * The phar, the entry config, the rule tiers and the environment are the ones
 * the lane uses, and no baseline is read.
 *
 * A rule is named by its `because` clause because that is the only name
 * PHPArkitect gives one, and every violation it prints ends in it. Nothing is
 * parsed into an identifier: the clause is matched as text against the
 * messages, and a file path counts only the classes that file declares.
 *
 * @internal
 */
final readonly class ArkitectRuleProbe
{
    /** Under the project's `var/qa`, where the generated config is written. */
    public const string CONFIG_DIR = 'arkitect-rule';

    /** The generated config, rewritten on every run. */
    public const string CONFIG_FILE = 'probe-config.php';

    /** The rule did not fire on the probed path. */
    public const int EXIT_NOT_FIRED = 0;

    /** The rule fired: the proof a fixture is written for. */
    public const int EXIT_FIRED = 1;

    /** A usage error, or a run that produced no verdict. */
    public const int EXIT_NO_VERDICT = 2;

    /** Printed on stderr for a usage error. */
    public const string USAGE = <<<'TXT'
        Usage: arkitect-rule <because> <path>

          e.g. arkitect-rule 'controllers must be named consistently' tests/Fixtures/Arkitect/Home.php

        Runs the project's PHPArkitect rules over <path> (a fixture file or directory) instead of
        their own class sets, and reports whether the rule whose ->because(...) clause contains
        <because> fired there. Exit 0: it did not fire. Exit 1: it fired. Exit 2: no verdict.

        TXT;

    /** The entry config the lane resolves, through the same cascade. */
    private const string ENTRY_CONFIG = 'phparkitect.php';

    /** The phar the lane runs, under the library's `vendor-phar/`. */
    private const string PHAR = 'phparkitect.phar';

    public function __construct(
        private ProjectPathsDto $paths,
        private ConfigPathResolver $configPaths,
        private PhpInvoker $php,
        private string $autoloadPath,
        private ProbeConfigRenderer $renderer = new ProbeConfigRenderer(),
        private ArkitectEnvironment $environment = new ArkitectEnvironment(),
        private ArkitectJsonParser $parser = new ArkitectJsonParser(),
    ) {
    }

    /**
     * The command: the project is the one whose autoloader `bin/bootstrap.php`
     * loaded, as for `bin/qa`.
     *
     * @return int {@see self::EXIT_NOT_FIRED}, {@see self::EXIT_FIRED} or {@see self::EXIT_NO_VERDICT}
     */
    public static function main(string $libraryRoot, string $autoloadPath, string ...$args): int
    {
        if (2 !== \count($args) || str_starts_with($args[0], '-')) {
            \Safe\fwrite(\STDERR, self::USAGE);

            return self::EXIT_NO_VERDICT;
        }

        try {
            $paths = new ProjectPathsResolver()->resolve(\dirname($autoloadPath, 2), $libraryRoot);
            $env   = new EnvironmentReader(EnvironmentReader::fromProcess());
            $probe = new self(
                $paths,
                new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, new PlatformDetector()->detect($paths->projectRoot)),
                new PhpInvoker(
                    new SymfonyProcessRunner(new NullOutput()),
                    $env->string('PHP_QA_CI_PHP_EXECUTABLE') ?? 'php',
                    $env->string('phpqaMemoryLimit')         ?? '4G',
                    $paths->varDir,
                ),
                $autoloadPath,
            );
            $result = $probe->probe($args[0], $args[1]);
        } catch (ProbeFailedException|ProjectLayoutException $exception) {
            \Safe\fwrite(\STDERR, $exception->getMessage() . "\n");

            return self::EXIT_NO_VERDICT;
        }

        \Safe\fwrite(\STDOUT, $result->render());

        return $result->fired() ? self::EXIT_FIRED : self::EXIT_NOT_FIRED;
    }

    /** @throws ProbeFailedException when the run produced no verdict */
    public function probe(string $because, string $path): ProbeResultDto
    {
        if ('' === trim($because)) {
            throw ProbeFailedException::because('The because clause is empty: name the rule by the text of its ->because(...).');
        }

        if (!file_exists($path)) {
            throw ProbeFailedException::because(\sprintf('No such path: %s', $path));
        }

        $target = $this->absolute($path);
        $dir    = is_dir($target) ? $target : \dirname($target);

        $entryConfig = $this->configPaths->resolve(self::ENTRY_CONFIG);
        if (!is_file($entryConfig)) {
            throw ProbeFailedException::because(\sprintf('No PHPArkitect entry config resolved at %s.', $entryConfig));
        }

        $result = $this->php->withoutXdebug(
            $this->paths->pharDir . '/' . self::PHAR,
            [
                'check',
                '--config=' . $this->writeConfig($entryConfig, $dir),
                '--autoload=' . $this->autoloadPath,
                '--no-interaction',
                '--skip-baseline',
                '--format=json',
            ],
            $this->paths->projectRoot,
            $this->environment->variables($this->configPaths, $this->paths->srcDir, new IgnoredPaths($this->paths->projectRoot)),
            streamOutput: false,
        );

        if ($result->exitCode > 1) {
            throw ProbeFailedException::because(\sprintf("PHPArkitect crashed (exit %d), so there is no verdict:\n%s", $result->exitCode, rtrim($result->output)));
        }

        try {
            $parsed = $this->parser->parse($result->stdout);
        } catch (UnreadableReportException $unreadableReportException) {
            throw ProbeFailedException::because(\sprintf("%s\n%s", $unreadableReportException->getMessage(), rtrim($result->output)));
        }

        $locator = new ClassFileLocator($dir);
        $firings = [];
        foreach ($parsed->classes as $fqcn => $violations) {
            $file = $locator->locate($fqcn);
            if ($target !== $dir && (null === $file || \Safe\realpath($file) !== \Safe\realpath($target))) {
                continue;
            }

            foreach ($violations as $violation) {
                if (str_contains($violation->message, $because)) {
                    $firings[] = new ProbeFiringDto($fqcn, null === $file ? null : $this->relative($file), $violation->message);
                }
            }
        }

        return new ProbeResultDto($because, $this->relative($target), $this->relative($entryConfig), ...$firings);
    }

    private function writeConfig(string $entryConfig, string $dir): string
    {
        $configDir = $this->paths->varDir . '/' . self::CONFIG_DIR;
        if (!is_dir($configDir)) {
            \Safe\mkdir($configDir, 0o777, true);
        }

        $configFile = $configDir . '/' . self::CONFIG_FILE;
        \Safe\file_put_contents($configFile, $this->renderer->render($entryConfig, $dir));

        return $configFile;
    }

    private function absolute(string $path): string
    {
        $path = rtrim($path, '/');

        return str_starts_with($path, '/') ? $path : \Safe\getcwd() . '/' . $path;
    }

    /** Project-relative where the path lies inside the project, as given otherwise. */
    private function relative(string $path): string
    {
        $real = \Safe\realpath($path);
        $root = \Safe\realpath($this->paths->projectRoot) . '/';

        return str_starts_with($real, $root) ? substr($real, \strlen($root)) : $real;
    }
}
