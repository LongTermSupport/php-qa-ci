<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Markdown\DocumentSelfReferenceScanner;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Documentation prose describes its subject, not itself.
 *
 * Separate from markdownLinks despite sharing the corpus: that lane asks whether a
 * link resolves, this one asks what a sentence is about, and a lane prints one
 * identifier. Folding this in would make `phpqaci.markdownLinks` resolve to a page
 * about links when a prose finding fired, which method clause 3.6 forbids.
 *
 * @internal
 */
final readonly class DocsProseTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.docsProse';

    public function name(): string
    {
        return 'docsProse';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root = $context->config->paths->projectRoot;
        $exit = OutputCapture::run(static fn (): int => DocumentSelfReferenceScanner::main($root), $context->output);
        if (0 === $exit) {
            return ToolResultDto::passed();
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('a document describes itself rather than its subject');
    }
}
