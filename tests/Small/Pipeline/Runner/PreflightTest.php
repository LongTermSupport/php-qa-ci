<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Config\Exception\LegacyBashConfigException;
use LTS\PHPQA\Pipeline\Runner\DirectoryPreparer;
use LTS\PHPQA\Pipeline\Runner\Exception\MissingPharException;
use LTS\PHPQA\Pipeline\Runner\HookRunner;
use LTS\PHPQA\Pipeline\Runner\PharToolsVerifier;
use LTS\PHPQA\Tests\Support\ContextFactory;
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
#[CoversClass(DirectoryPreparer::class)]
#[CoversClass(PharToolsVerifier::class)]
#[CoversClass(MissingPharException::class)]
#[CoversClass(HookRunner::class)]
#[CoversClass(LegacyBashConfigException::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\ConfigPathResolver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\PhpUnitOptionsDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\Dto\QaConfigDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\EnvironmentReader::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Config\QaConfigBuilder::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\LogArchiver::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Process\PhpInvoker::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\ToolContext::class)]
#[Small]
final class PreflightTest extends TestCase
{
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
    public function directoriesAreCreatedWithSelfExcludingGitignoresAndTheRootBlockIsAddedOnce(): void
    {
        $paths = $this->factory->paths();
        $this->factory->project->write('.gitignore', '/vendor/');

        new DirectoryPreparer()->prepare($paths);
        new DirectoryPreparer()->prepare($paths);

        self::assertSame("\n*\n!.gitignore\n", $this->factory->project->read('var/qa/.gitignore'));
        self::assertSame("\n*\n!.gitignore\n", $this->factory->project->read('var/qa/cache/.gitignore'));
        $root = $this->factory->project->read('.gitignore');
        self::assertStringStartsWith("/vendor/\n", $root, 'a missing trailing newline is added before the block');
        self::assertSame(1, substr_count($root, DirectoryPreparer::MARKER_START), 'the block is idempotent');
        self::assertStringContainsString('**/.qa-lock/', $root);
    }

    #[Test]
    public function aMissingRootGitignoreIsCreated(): void
    {
        new DirectoryPreparer()->prepare($this->factory->paths());

        self::assertStringContainsString(DirectoryPreparer::MARKER_START, $this->factory->project->read('.gitignore'));
    }

    #[Test]
    public function theShippedLibraryHasEveryPhar(): void
    {
        $this->expectNotToPerformAssertions();

        new PharToolsVerifier()->verify(\dirname(__DIR__, 4));
    }

    #[Test]
    public function aMissingPharIsNamedWithReinstallGuidance(): void
    {
        $library = TempDir::create('phpqa-lib');
        $library->write('phive.xml', '<phive><phar name="phpstan" version="^2" location="./vendor-phar/phpstan.phar"/><phar name="infection/infection" version="^0.35" location="./vendor-phar/infection.phar"/></phive>');
        $library->write('vendor-phar/phpstan.phar', '');

        try {
            new PharToolsVerifier()->verify($library->path);
            self::fail('expected MissingPharException');
        } catch (MissingPharException $missingPharException) {
            self::assertStringContainsString('infection, rector', $missingPharException->getMessage());
            self::assertStringContainsString('composer reinstall lts/php-qa-ci', $missingPharException->getMessage());
        } finally {
            $library->remove();
        }
    }

    #[Test]
    public function aLibraryWithoutPhiveXmlIsRejected(): void
    {
        $library = TempDir::create('phpqa-lib');

        try {
            new PharToolsVerifier()->verify($library->path);
            self::fail('expected MissingPharException');
        } catch (MissingPharException $missingPharException) {
            self::assertStringContainsString('phive.xml is required', $missingPharException->getMessage());
        } finally {
            $library->remove();
        }
    }

    #[Test]
    public function phpHooksRunWithTheContextAndMissingHooksAreSilentlySkipped(): void
    {
        $this->factory->project->write('qaConfig/hookPre.php', '<?php return static function ($context): void { $context->writeln("pre hook says hi"); };');
        $context = $this->factory->context();

        new HookRunner()->runPre($context);
        new HookRunner()->runPost($context);

        $printed = $this->factory->output->fetch();
        self::assertStringContainsString('Running pre hook', $printed);
        self::assertStringContainsString('pre hook says hi', $printed);
        self::assertStringNotContainsString('post hook', $printed);
    }

    #[Test]
    public function aBashHookIsRefusedWithMigrationGuidance(): void
    {
        $this->factory->project->write('qaConfig/hookPost.bash', 'echo hi');

        try {
            new HookRunner()->runPost($this->factory->context());
            self::fail('expected LegacyBashConfigException');
        } catch (LegacyBashConfigException $legacyBashConfigException) {
            self::assertStringContainsString('hookPost.bash is no longer run', $legacyBashConfigException->getMessage());
            self::assertStringContainsString('docs/upgrading-to-8.5.md', $legacyBashConfigException->getMessage());
        }
    }

    #[Test]
    public function aHookThatDoesNotReturnACallableIsRejected(): void
    {
        $this->factory->project->write('qaConfig/hookPre.php', '<?php return 42;');

        $this->expectException(RuntimeException::class);
        new HookRunner()->runPre($this->factory->context());
    }

    #[Test]
    public function theLegacyExceptionsNameEveryBashForm(): void
    {
        self::assertStringContainsString('qaConfig.inc.bash is no longer read', LegacyBashConfigException::qaConfig('/p/qaConfig')->getMessage());
        self::assertStringContainsString('tools/phpstan.inc.bash is no longer sourced', LegacyBashConfigException::toolOverride('phpstan', '/p/qaConfig')->getMessage());
    }
}
