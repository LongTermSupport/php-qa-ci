<?php

declare(strict_types=1);

namespace LTS\PHPQA\Psr4Validate;

use Closure;
use Exception;
use InvalidArgumentException;
use LTS\PHPQA\Filesystem\UnresolvablePath;
use LTS\PHPQA\Helper;
use LTS\PHPQA\Psr4Validator;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/psr4-validate: checks a project's PHP files against its composer.json
 * autoload mapping, skipping files that match the ignore patterns given as
 * arguments. `--help` or `-h` prints the usage instead; neither is a pattern
 * PCRE can compile, so neither can mean one.
 *
 * @internal
 */
final readonly class Psr4ValidateCommand
{
    public const int EXIT_PASSED = 0;

    public const int EXIT_FAILED = 1;

    private const array HELP_OPTIONS = ['--help', '-h'];

    private const string USAGE = <<<'USAGE'
        Usage: psr4-validate [<ignore-pattern> ...]

        Checks that every PHP file under composer.json's autoload psr-4 and psr-0 roots
        declares the namespace and class its path implies. Run it from the project root.

        Each <ignore-pattern> is a PCRE pattern with its delimiters, for example
        '#/src/Legacy/#', matched against a file's real path; a file it matches is not
        checked. A file whose real path cannot be resolved (a symlink whose target does
        not exist) is not checked either, and is named as skipped.

        Exit 0 when every file agrees with the mapping, 1 otherwise.

        USAGE;

    /**
     * @param Closure(): string $projectRoot resolved only to validate, so the usage prints anywhere
     */
    public function __construct(private Closure $projectRoot, private OutputInterface $output)
    {
    }

    public function run(string ...$arguments): int
    {
        if ([] !== array_intersect(self::HELP_OPTIONS, $arguments)) {
            $this->write(self::USAGE);

            return self::EXIT_PASSED;
        }

        $root     = ($this->projectRoot)();
        $composer = $root . '/composer.json';
        if (!is_file($composer)) {
            $this->write("\nNo composer.json in " . $root . "; run psr4-validate from the project root.\n");

            return self::EXIT_FAILED;
        }

        try {
            $validator = new Psr4Validator($arguments, $root, Helper::getComposerJsonDecoded($composer));
            $errors    = $validator->main();
        } catch (InvalidArgumentException $exception) {
            $this->write("\n" . $exception->getMessage() . "\nRun psr4-validate --help for the usage.\n");

            return self::EXIT_FAILED;
        } catch (Exception $exception) {
            $this->write("\n" . $exception->getMessage() . "\n");

            return self::EXIT_FAILED;
        }

        foreach ($validator->unresolvable() as $file) {
            $this->write("\n" . UnresolvablePath::skipped('psr4-validate', $file, $root) . "\n");
        }

        if ([] !== $errors) {
            $this->write("\nErrors found:\n" . var_export($errors, true) . "\nErrors validating PSR4\n");

            return self::EXIT_FAILED;
        }

        $this->write("\nNo errors found\n");

        return self::EXIT_PASSED;
    }

    /** Raw: a path or a pattern may hold what the console would read as a style tag. */
    private function write(string $text): void
    {
        $this->output->write($text, false, OutputInterface::OUTPUT_RAW);
    }
}
