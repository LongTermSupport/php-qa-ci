<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Markdown\QaCommandTokenScanner;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\RegistryAwareToolInterface;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;

/**
 * Every `qa -t <token>` command in the documentation and the agent files names
 * a tool bin/qa accepts.
 *
 * Separate from markdownLinks and docsProse despite reading markdown: those
 * ask whether a link resolves and what a sentence is about, this asks whether
 * a command runs, over a wider corpus (the agent and skill files), against the
 * tool registry. A lane prints one identifier, and a token finding must resolve
 * to a page about tokens.
 *
 * The registry is the run's own, handed over by PipelineBuilder::build() after
 * qaConfig/pipeline.php is applied, so a token the project adds is a token its
 * documents may use, and that file is required once per run.
 *
 * @internal
 */
final readonly class DocumentedQaCommandsTool implements RegistryAwareToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.documentedQaCommands';

    public function __construct(private ToolRegistry $registry)
    {
    }

    public function withRegistry(ToolRegistry $registry): self
    {
        return new self($registry);
    }

    public function name(): string
    {
        return 'documentedQaCommands';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $findings = new QaCommandTokenScanner($this->registry)->scan($context->config->paths->projectRoot);
        if ([] === $findings) {
            return ToolResultDto::passed();
        }

        foreach ($findings as $finding) {
            $context->writeln($finding->message());
            $context->writeln();
        }

        $context->writeln(\sprintf('%d documented qa command(s) name a tool bin/qa rejects.', \count($findings)));
        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('a documented qa command names a tool bin/qa rejects');
    }
}
