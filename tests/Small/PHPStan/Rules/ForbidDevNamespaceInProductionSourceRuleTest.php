<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PackageType\AutoloadRootReader;
use LTS\PHPQA\PHPStan\Rules\DevCodeInShippedRootDetector;
use LTS\PHPQA\PHPStan\Rules\ForbidDevNamespaceInProductionSourceRule;
use LTS\PHPQA\PHPStan\Rules\VendoredCodeDetector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 *
 * @extends RuleTestCase<ForbidDevNamespaceInProductionSourceRule>
 */
#[CoversClass(ForbidDevNamespaceInProductionSourceRule::class)]
#[Medium]
final class ForbidDevNamespaceInProductionSourceRuleTest extends RuleTestCase
{
    private const string FIXTURE_NAMESPACE = 'LTS\PHPQA\Tests\Assets\PHPStan\DevNamespace\\';

    private const string FIXTURE_DIRECTORY = 'tests/assets/PHPStan/DevNamespace/';

    private const string NESTED_DEV_FIXTURE = 'Command/Dev/SandboxPushCommand.php';

    private const string ADVICE = ' is dev-only code (a Dev segment) yet lives under the shipped composer autoload root "'
        . self::FIXTURE_NAMESPACE
        . '", so it installs into production and into every consumer. Move it to a src-dev/ tree mapped under '
        . 'autoload-dev and register it for the dev environment only.';

    private string $projectRoot = '';

    /** @var array<int|string, mixed> decoded composer.json driving the autoload roots */
    private array $composerJson = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot  = $this->repositoryRoot();
        $this->composerJson = [
            'autoload' => ['psr-4' => [self::FIXTURE_NAMESPACE => self::FIXTURE_DIRECTORY]],
        ];
    }

    #[Test]
    public function aDevSegmentUnderAShippedAutoloadRootIsReportedAtAnyDepth(): void
    {
        $this->analyse(
            [
                $this->fixture(self::NESTED_DEV_FIXTURE),
                $this->fixture('Dev/ToolRunner.php'),
            ],
            [
                ['Class ' . self::FIXTURE_NAMESPACE . 'Command\Dev\SandboxPushCommand' . self::ADVICE, 11],
                ['Class ' . self::FIXTURE_NAMESPACE . 'Dev\ToolRunner' . self::ADVICE, 11],
            ],
        );
    }

    #[Test]
    public function theSameTreeIsQuietWhenTheRootIsDeclaredUnderAutoloadDev(): void
    {
        // The fix the message names: the identical files, mapped under autoload-dev
        // instead, are not shipped — so there is nothing left to report.
        $this->composerJson = [
            'autoload-dev' => ['psr-4' => [self::FIXTURE_NAMESPACE => self::FIXTURE_DIRECTORY]],
        ];

        $this->analyse(
            [
                $this->fixture(self::NESTED_DEV_FIXTURE),
                $this->fixture('Dev/ToolRunner.php'),
            ],
            [],
        );
    }

    #[Test]
    public function aDevPrefixedSegmentOrClassNameIsNotADevSegment(): void
    {
        $this->analyse([$this->fixture('DevTools/DevModeSwitch.php')], []);
    }

    #[Test]
    public function aDependencysOwnDevTreeIsNotThisProjectsToMove(): void
    {
        // Anchored at the fixture's own root, `vendor/Dev/PackagedTool.php` is
        // vendored code: the analysed project cannot move a file it does not own.
        $vendoredRoot       = $this->repositoryRoot() . '/tests/assets/PHPStan/DevNamespaceVendored';
        $this->projectRoot  = $vendoredRoot;
        $this->composerJson = [
            'autoload' => [
                'psr-4' => [
                    'LTS\PHPQA\Tests\Assets\PHPStan\DevNamespaceVendored\\' => './',
                ],
            ],
        ];

        $this->analyse([$vendoredRoot . '/vendor/Dev/PackagedTool.php'], []);
    }

    #[Test]
    public function aProjectDeclaringNoShippedRootsHasNothingToReport(): void
    {
        $this->composerJson = ['name' => 'acme/widget'];

        $this->analyse([$this->fixture(self::NESTED_DEV_FIXTURE)], []);
    }

    protected function getRule(): Rule
    {
        return new ForbidDevNamespaceInProductionSourceRule(
            new DevCodeInShippedRootDetector(new AutoloadRootReader($this->composerJson), $this->projectRoot),
            new VendoredCodeDetector($this->projectRoot),
        );
    }

    private function fixture(string $relativePath): string
    {
        return $this->repositoryRoot() . '/' . self::FIXTURE_DIRECTORY . $relativePath;
    }

    private function repositoryRoot(): string
    {
        return \Safe\realpath(__DIR__ . '/../../../..');
    }
}
