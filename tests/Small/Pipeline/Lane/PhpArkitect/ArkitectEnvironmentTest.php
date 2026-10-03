<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\PhpArkitect;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
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
#[Small]
final class ArkitectEnvironmentTest extends TestCase
{
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

        $env = new ArkitectEnvironment()->variables($this->resolver($defaults), '/project/src', 'Quote/API', 'Generated/Client');

        self::assertSame(
            [
                'PHPQACI_ARKITECT_SRC_DIR'                => '/project/src',
                'PHPQACI_ARKITECT_RULES_DEFAULT'          => $override,
                'PHPQACI_ARKITECT_RULES_OPTIONAL'         => $defaults . '/generic/phparkitect-rules-optional.php',
                'PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY' => $defaults . '/generic/phparkitect-rules-optional-symfony.php',
                'PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY'  => $defaults . '/generic/phparkitect-consumer-api-boundary.php',
                'PHPQACI_ARKITECT_EXCLUDE_PATHS'          => "Quote/API\nGenerated/Client",
            ],
            $env,
        );
    }

    #[Test]
    public function noExcludePathsExportsAnEmptyValue(): void
    {
        $env = new ArkitectEnvironment()->variables($this->resolver(\dirname(__DIR__, 5) . '/configDefaults'), '/project/src');

        self::assertSame('', $env['PHPQACI_ARKITECT_EXCLUDE_PATHS']);
    }

    private function resolver(string $defaults): ConfigPathResolver
    {
        return new ConfigPathResolver($this->project->path . '/qaConfig', $defaults, PlatformEnum::Generic);
    }
}
