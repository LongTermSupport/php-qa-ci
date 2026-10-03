<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use Exception;
use LTS\PHPQA\Helper;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Psr4Validator;

/**
 * Every PHP file's namespace and class must match the composer.json autoload
 * mapping. Ignore patterns (one PHP regex per line) come from
 * psr4-validate-ignore-list.txt, project override first, and each of the
 * project's ignored paths adds one more, anchored to its real path.
 *
 * @internal
 */
final readonly class Psr4ValidateTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.psr4Validate';

    public function name(): string
    {
        return 'psr4Validate';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root     = $context->config->paths->projectRoot;
        $patterns = [
            ...$this->ignorePatterns($context->configPath('psr4-validate-ignore-list.txt')),
            ...$this->ignoredPathPatterns(IgnoredPaths::of($context->config)),
        ];

        try {
            $errors = new Psr4Validator($patterns, $root, Helper::getComposerJsonDecoded($root . '/composer.json'))->main();
        } catch (Exception $exception) {
            $context->writeln('');
            $context->writeln($exception->getMessage());
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('PSR-4 validation failed: ' . $exception->getMessage());
        }

        if ([] === $errors) {
            $context->writeln('');
            $context->writeln('No errors found');

            return ToolResultDto::passed();
        }

        $context->writeln('');
        $context->writeln('Errors found:');
        $context->writeln(var_export($errors, true));
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed(\sprintf('PSR-4 validation found %d problem group(s)', \count($errors)));
    }

    /**
     * The validator matches each pattern against a file's real path, so an
     * ignored path is resolved the same way and anchored at both ends: it
     * covers itself and what is below it, never a sibling sharing its prefix.
     *
     * @return list<string>
     */
    private function ignoredPathPatterns(IgnoredPaths $ignored): array
    {
        return array_map(
            static fn (string $path): string => '#^' . preg_quote(file_exists($path) ? \Safe\realpath($path) : $path, '#') . '(?:/|$)#',
            $ignored->absolute,
        );
    }

    /** @return list<string> */
    private function ignorePatterns(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $patterns = [];
        foreach (explode("\n", \Safe\file_get_contents($file)) as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $patterns[] = $line;
            }
        }

        return $patterns;
    }
}
