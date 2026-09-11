<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Markdown\DocumentSelfReferenceFinding;
use LTS\PHPQA\Markdown\DocumentSelfReferenceScanner;
use LTS\PHPQA\Pipeline\Lane\DocsProseTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DocsProseTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\OutputCapture::class)]
#[UsesClass(DocumentSelfReferenceScanner::class)]
#[UsesClass(DocumentSelfReferenceFinding::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\DeadCodeOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\TypeCoverageOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[UsesClass(ToolOutcomeEnum::class)]
#[Small]
final class DocsProseToolTest extends TestCase
{
    private const string README = 'README.md';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function documentationAboutItsSubjectPasses(): void
    {
        $this->factory->project->write(
            self::README,
            "# Tool\n\nPreviously the pipeline was configured in Bash; it is now PHP.\n",
        );

        $result = new DocsProseTool()->run($this->factory->context());

        self::assertTrue($result->isSuccess());
    }

    #[Test]
    public function aDocumentDescribingItsOwnPastFailsAndNamesTheFile(): void
    {
        $this->factory->project->write(
            self::README,
            "# Tool\n\nThe previous version of\nthis section listed seventeen rules.\n",
        );

        $result = new DocsProseTool()->run($this->factory->context());
        $output = $this->factory->output->fetch();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString(self::README, $output);
        self::assertStringContainsString('previous version of this section', $output);
    }

    #[Test]
    public function aFailureCarriesTheStableIdentifier(): void
    {
        $this->factory->project->write(self::README, "# Tool\n\nThis page used to document the Bash entry point.\n");

        new DocsProseTool()->run($this->factory->context());

        self::assertStringContainsString(DocsProseTool::IDENTIFIER, $this->factory->output->fetch());
    }

    #[Test]
    public function exampleProseInsideAFencedBlockIsNotAnInstance(): void
    {
        $this->factory->project->write(
            self::README,
            "# Tool\n\nDo not write this:\n\n```markdown\nThe previous version of this section listed seventeen rules.\n```\n",
        );

        $result = new DocsProseTool()->run($this->factory->context());

        self::assertTrue($result->isSuccess(), "a rule's own documentation must be able to quote what it forbids");
    }

    #[Test]
    public function itIsNamedAndIdentifiedForTheRegistry(): void
    {
        $tool = new DocsProseTool();

        self::assertSame('docsProse', $tool->name());
        self::assertSame('phpqaci.docsProse', $tool->identifier());
    }
}
