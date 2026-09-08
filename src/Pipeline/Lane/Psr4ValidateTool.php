<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use Exception;
use LTS\PHPQA\Helper;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\Psr4Validator;

/**
 * Every PHP file's namespace and class must match the composer.json autoload
 * mapping. Ignore patterns (one PHP regex per line) come from
 * psr4-validate-ignore-list.txt, project override first.
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
        $patterns = $this->ignorePatterns($context->configPath('psr4-validate-ignore-list.txt'));

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
