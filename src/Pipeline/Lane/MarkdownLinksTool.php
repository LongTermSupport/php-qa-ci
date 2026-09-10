<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Markdown\LinksChecker;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Every link in README.md and docs/ must resolve. A project without a
 * README.md fails: the check needs one to have anything to stand on.
 *
 * @internal
 */
final readonly class MarkdownLinksTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.markdownLinks';

    public function name(): string
    {
        return 'markdownLinks';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root = $context->config->paths->projectRoot;
        if (!is_file($root . '/README.md')) {
            $context->writeln('ERROR: The Markdown Links check requires a README.md in the root of the repository');
            $context->writeln('ERROR: You must create a README.md to proceed');
            $context->writeIdentifier(self::IDENTIFIER);

            return ToolResultDto::failed('no README.md in the project root');
        }

        $exit = OutputCapture::run(static fn (): int => LinksChecker::main($root), $context->output);
        if (0 === $exit) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('a markdown link does not resolve');
    }
}
