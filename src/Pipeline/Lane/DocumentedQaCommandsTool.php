<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Markdown\QaCommandTokenScanner;
use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Config\PipelineConfigLoader;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\PipelineBuilder;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use Symfony\Component\Console\Output\NullOutput;

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
 * The registry is the project's own, with qaConfig/pipeline.php applied, so a
 * token the project adds is a token its documents may use.
 *
 * @internal
 */
final readonly class DocumentedQaCommandsTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.documentedQaCommands';

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
        $paths    = $context->config->paths;
        $registry = new PipelineConfigLoader(new NullOutput())->apply(PipelineBuilder::defaults(), $paths->projectConfigDir)->build()->registry;
        $findings = new QaCommandTokenScanner($registry)->scan($paths->projectRoot);
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
