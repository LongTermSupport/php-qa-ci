<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\PhpArkitect;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Lane\PhpArkitect\ArkitectEnvironment;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * What a PHPArkitect entry config reads from its environment. The lane and the
 * single-rule probe both build it here, so a probe sees the rule tiers the
 * pipeline would.
 *
 * @internal
 */
#[CoversClass(ArkitectEnvironment::class)]
#[UsesClass(ConfigPathResolver::class)]
#[UsesClass(IgnoredPaths::class)]
#[Small]
final class ArkitectEnvironmentTest extends TestCase
{
    private const string SRC_DIR = '/project/src';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-arkenv');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function itExportsTheSourceDirTheResolvedTiersAndTheExcludePaths(): void
    {
        $override = $this->project->write('qaConfig/phparkitect-rules-default.php', "<?php\n");
        $defaults = \dirname(__DIR__, 5) . '/configDefaults';

        $ignored  = new IgnoredPaths('/project', 'src/Legacy', './tests/assets/');

        $env = new ArkitectEnvironment()->variables($this->resolver($defaults), self::SRC_DIR, $ignored, 'Quote/API', 'Generated/Client');

        self::assertSame(
            [
                'PHPQACI_ARKITECT_SRC_DIR'                => self::SRC_DIR,
                'PHPQACI_ARKITECT_RULES_DEFAULT'          => $override,
                'PHPQACI_ARKITECT_RULES_OPTIONAL'         => $defaults . '/generic/phparkitect-rules-optional.php',
                'PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY' => $defaults . '/generic/phparkitect-rules-optional-symfony.php',
                'PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY'  => $defaults . '/generic/phparkitect-consumer-api-boundary.php',
                ArkitectEnvironment::CLASS_SET            => $defaults . '/generic/phparkitect-class-set.php',
                'PHPQACI_ARKITECT_IGNORED_PATHS'          => "/project/src/Legacy\n/project/tests/assets",
                'PHPQACI_ARKITECT_EXCLUDE_PATHS'          => "Quote/API\nGenerated/Client",
            ],
            $env,
        );
    }

    #[Test]
    public function noExcludeOrIgnoredPathsExportEmptyValues(): void
    {
        $env = new ArkitectEnvironment()->variables($this->resolver(\dirname(__DIR__, 5) . '/configDefaults'), self::SRC_DIR, new IgnoredPaths('/project'));

        self::assertSame('', $env['PHPQACI_ARKITECT_EXCLUDE_PATHS']);
        self::assertSame('', $env['PHPQACI_ARKITECT_IGNORED_PATHS']);
    }

    private function resolver(string $defaults): ConfigPathResolver
    {
        return new ConfigPathResolver($this->project->path . '/qaConfig', $defaults, PlatformEnum::Generic);
    }
}
