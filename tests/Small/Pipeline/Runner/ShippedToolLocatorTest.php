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
#[UsesClass(TempDir::class)]
#[Small]
final class ShippedToolLocatorTest extends TestCase
{
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
        $shipped = new StubTool('phpLint', ToolResultDto::passed());
        $locator = new ShippedToolLocator(['phpLint' => $shipped], $this->qaConfig->path);

        self::assertSame($shipped, $locator->locate('phpLint'));
    }

    #[Test]
    public function aProjectOverrideReplacesTheShippedTool(): void
    {
        $this->qaConfig->write('tools/phpLint.php', '<?php return new LTS\PHPQA\Tests\Support\StubTool("phpLint");');
        $locator = new ShippedToolLocator(['phpLint' => new StubTool('phpLint')], $this->qaConfig->path);

        $tool = $locator->locate('phpLint');

        self::assertInstanceOf(StubTool::class, $tool);
        self::assertNotSame($locator->locate('phpLint'), $tool, 'each locate requires the file afresh');
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
        $locator->locate('phpLint');
    }

    #[Test]
    public function anUnknownNameIsRefused(): void
    {
        $this->expectException(UnknownToolException::class);
        new ShippedToolLocator(ShippedTools::all(), $this->qaConfig->path)->locate('nope');
    }
}
