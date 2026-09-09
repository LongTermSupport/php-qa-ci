<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Config\Exception\LegacyBashConfigException;
use LTS\PHPQA\Pipeline\Runner\ShippedToolLocator;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownToolException;
use LTS\PHPQA\Pipeline\Tool\ShippedTools;
use LTS\PHPQA\Tests\Support\StubTool;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(ShippedToolLocator::class)]
#[CoversClass(ShippedTools::class)]
#[UsesClass(LegacyBashConfigException::class)]
#[UsesClass(\LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesCheck::class)]
#[UsesClass(\LTS\PHPQA\PHPStan\ProjectRecord\IgnoreErrorsJustificationCheck::class)]
#[UsesClass(\LTS\PHPQA\PackageType\ExplicitPackageTypeCheck::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\BranchNamePolicyTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerChecksTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ComposerRequireCheckerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\ConfigTemplateIgnoreListTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionConfigSourceDirsTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\InfectionTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\MarkdownLinksTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PackageTypeTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpArkitectTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpCsFixerTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpStrictTypesTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhplocTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpstanIgnoreJustificationTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpstanTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\PhpunitTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\Psr4ValidateTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\RectorTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\SensitiveParameterUsageTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\TwigLintTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\VersionPinsTool::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Lane\YamlLintTool::class)]
#[UsesClass(UnknownToolException::class)]
#[UsesClass(ToolResultDto::class)]
#[Small]
final class ShippedToolLocatorTest extends TestCase
{
    private const string PHP_LINT = 'phpLint';

    private TempDir $qaConfig;

    protected function setUp(): void
    {
        $this->qaConfig = TempDir::create('phpqa-locator');
    }

    protected function tearDown(): void
    {
        $this->qaConfig->remove();
    }

    #[Test]
    public function aShippedToolIsReturnedByName(): void
    {
        $shipped = new StubTool(self::PHP_LINT, ToolResultDto::passed());
        $locator = new ShippedToolLocator([self::PHP_LINT => $shipped], $this->qaConfig->path);

        self::assertSame($shipped, $locator->locate(self::PHP_LINT));
    }

    #[Test]
    public function aProjectOverrideReplacesTheShippedTool(): void
    {
        $this->qaConfig->write('tools/phpLint.php', '<?php return new LTS\PHPQA\Tests\Support\StubTool("phpLint");');
        $locator = new ShippedToolLocator([self::PHP_LINT => new StubTool(self::PHP_LINT)], $this->qaConfig->path);

        $tool = $locator->locate(self::PHP_LINT);

        self::assertInstanceOf(StubTool::class, $tool);
        self::assertNotSame($locator->locate(self::PHP_LINT), $tool, 'each locate requires the file afresh');
    }

    #[Test]
    public function aBashOverrideIsRefusedWithGuidance(): void
    {
        $this->qaConfig->write('tools/phpstan.inc.bash', 'echo custom');
        $locator = new ShippedToolLocator(['phpstan' => new StubTool('phpstan')], $this->qaConfig->path);

        try {
            $locator->locate('phpstan');
            self::fail('expected LegacyBashConfigException');
        } catch (LegacyBashConfigException $legacyBashConfigException) {
            self::assertStringContainsString('tools/phpstan.inc.bash is no longer sourced', $legacyBashConfigException->getMessage());
        }
    }

    #[Test]
    public function anOverrideThatIsNotAToolIsRejected(): void
    {
        $this->qaConfig->write('tools/phpLint.php', '<?php return "nope";');
        $locator = new ShippedToolLocator([], $this->qaConfig->path);

        $this->expectException(RuntimeException::class);
        $locator->locate(self::PHP_LINT);
    }

    #[Test]
    public function anUnknownNameIsRefused(): void
    {
        $this->expectException(UnknownToolException::class);
        new ShippedToolLocator(ShippedTools::all(), $this->qaConfig->path)->locate('nope');
    }
}
