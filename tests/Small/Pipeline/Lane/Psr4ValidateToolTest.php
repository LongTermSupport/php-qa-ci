<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane;

use LTS\PHPQA\Helper;
use LTS\PHPQA\Pipeline\Lane\Psr4ValidateTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Psr4Validator;
use LTS\PHPQA\Regex\RegexPattern;
use LTS\PHPQA\Tests\Support\ContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Psr4ValidateTool::class)]
#[UsesClass(Psr4Validator::class)]
#[UsesClass(RegexPattern::class)]
#[UsesClass(Helper::class)]
#[UsesClass(\LTS\PHPQA\Filesystem\UnresolvablePath::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\IgnoredPaths::class)]
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
#[UsesClass(\LTS\PHPQA\Changelog\ReleaseVersionPolicy::class)]
#[Small]
final class Psr4ValidateToolTest extends TestCase
{
    private const string IGNORE_LIST = 'qaConfig/psr4-validate-ignore-list.txt';

    private ContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = ContextFactory::create();
        $this->factory->project->write(
            'composer.json',
            "{\n  \"name\": \"fixture/psr4\",\n  \"type\": \"project\",\n  \"autoload\": {\"psr-4\": {\"Fixture\\\\\": \"src/\"}}\n}\n",
        );
        $this->factory->project->write('src/Good.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class Good\n{\n}\n");
    }

    protected function tearDown(): void
    {
        $this->factory->project->remove();
    }

    #[Test]
    public function aProjectWhoseFilesMatchTheirNamespacesPasses(): void
    {
        $this->factory->project->write(self::IGNORE_LIST, "#/src/Legacy/#\n");

        $result = new Psr4ValidateTool()->run($this->factory->context());

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('No errors found', $this->factory->output->fetch());
    }

    #[Test]
    public function anIgnoreListEntryThatDoesNotCompileFailsTheLaneNamingTheFileAndTheEntry(): void
    {
        $this->factory->project->write(self::IGNORE_LIST, "#/src/Legacy/#\n#/src/(unclosed#\n");

        $result = new Psr4ValidateTool()->run($this->factory->context());
        $output = $this->factory->output->fetch();

        self::assertFalse($result->isSuccess());
        self::assertStringContainsString(
            self::IGNORE_LIST . ' entry "#/src/(unclosed#" is not a valid regular expression: preg_match(): Compilation failed: missing closing parenthesis',
            $output,
        );
        self::assertStringNotContainsString('PREG_INTERNAL_ERROR', $output);
        self::assertStringContainsString(Psr4ValidateTool::IDENTIFIER, $output);
    }
}
