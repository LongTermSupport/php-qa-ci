<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Arkitect;

use LTS\PHPQA\Arkitect\ArkitectRuleProbe;
use LTS\PHPQA\Arkitect\Exception\ProbeFailedException;
use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\Dto\ProjectPathsDto;
use LTS\PHPQA\Pipeline\Config\PlatformEnum;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Pipeline\Process\PhpInvoker;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The PHPArkitect single-rule probe against the real phar.
 *
 * The fixture project (tests/assets/arkitect/ruleProbe) is the case the probe
 * exists for: an entry config shaped like the shipped template, hard-coding its
 * class set to src/, extending the default tier and adding one bespoke rule,
 * with zero instances of any of them in src/. A green run of that project
 * proves nothing about whether a rule can fire. The probe runs the same rules
 * over a fixture and each one is seen to fire on its violating subject and to
 * stay silent on its conforming one.
 *
 * @internal
 */
#[Large]
#[CoversNothing]
final class ArkitectRuleProbeTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private const string PROJECT = self::ROOT . '/tests/assets/arkitect/ruleProbe';

    private const string VIOLATING = self::PROJECT . '/tests/Fixtures/Arkitect/Violating';

    private const string CONFORMING = self::PROJECT . '/tests/Fixtures/Arkitect/Conforming';

    private const string INTERFACE_BECAUSE = 'an Interface suffix makes the symbol kind obvious at every use site';

    private const string CONFORMING_DTO = 'Dto/AddressDto.php';

    private const string USAGE = 'Usage:';

    private TempDir $var;

    protected function setUp(): void
    {
        $this->var = TempDir::create('phpqa-arkprobe');
    }

    protected function tearDown(): void
    {
        $this->var->remove();
    }

    /** @return iterable<string, array{string, string, string}> because clause, violating file, conforming file */
    public static function provideEveryRuleTheProjectEnforces(): iterable
    {
        yield 'default: interface suffix' => [self::INTERFACE_BECAUSE, 'Gateway.php', 'GatewayInterface.php'];
        yield 'default: enum suffix' => ['an Enum suffix makes the symbol kind obvious at every use site', 'Colour.php', 'ColourEnum.php'];
        yield 'default: trait suffix' => ['a Trait suffix makes the symbol kind obvious at every use site', 'Loggable.php', 'LoggableTrait.php'];
        yield 'default: Dto namespace implies suffix' => ['a Dto namespace holds data transfer objects', 'Dto/Address.php', self::CONFORMING_DTO];
        yield 'default: Dto suffix implies namespace' => ['keeping every Dto in a Dto namespace', 'Model/PostcodeDto.php', self::CONFORMING_DTO];
        yield 'default: Dto is final' => ['a Dto is a value carrier, not an extension point', 'Dto/OpenDto.php', self::CONFORMING_DTO];
        yield 'default: Dto is readonly' => ['a readonly Dto cannot be mutated after construction', 'Dto/MutableDto.php', self::CONFORMING_DTO];
        yield 'project: controller suffix' => ['a Controller suffix tells routing code apart from the services it calls', 'Controller/Home.php', 'Controller/HomeController.php'];
    }

    /** The premise: nothing in the project's own src/ violates any rule, so the lane alone cannot show a rule works. */
    #[Test]
    public function theProjectItselfHasNoInstanceOfAnyRule(): void
    {
        $result = $this->php()->withoutXdebug(
            self::ROOT . '/vendor-phar/phparkitect.phar',
            ['check', '--config=' . self::PROJECT . '/qaConfig/phparkitect.php', '--autoload=' . self::PROJECT . '/autoload.php', '--no-interaction', '--skip-baseline'],
            self::PROJECT,
            ['PHPQACI_ARKITECT_RULES_DEFAULT' => self::ROOT . '/configDefaults/generic/phparkitect-rules-default.php'],
            streamOutput: false,
        );

        self::assertSame(0, $result->exitCode, $result->output);
    }

    #[Test]
    #[DataProvider('provideEveryRuleTheProjectEnforces')]
    public function eachRuleFiresOnItsViolatingFixture(string $because, string $violating, string $conforming): void
    {
        $result = $this->probe()->probe($because, self::VIOLATING . '/' . $violating);

        self::assertTrue($result->fired(), $result->render());
        self::assertCount(1, $result->firings, $result->render());
        self::assertSame('tests/Fixtures/Arkitect/Violating/' . $violating, $result->firings[0]->file);
    }

    #[Test]
    #[DataProvider('provideEveryRuleTheProjectEnforces')]
    public function eachRuleStaysSilentOnItsConformingFixture(string $because, string $violating, string $conforming): void
    {
        $result = $this->probe()->probe($because, self::CONFORMING . '/' . $conforming);

        self::assertFalse($result->fired(), $result->render());
    }

    /** A directory probe reports every class in it that broke the rule. */
    #[Test]
    public function aDirectoryProbeReportsEveryFiringInIt(): void
    {
        $result = $this->probe()->probe('Dto', self::VIOLATING . '/Dto');

        self::assertCount(3, $result->firings, $result->render());
    }

    /**
     * The shipped entry config returns before registering anything when the
     * source directory is missing. A probe through it would otherwise report
     * "did not fire" for every rule, which reads as a verdict.
     */
    #[Test]
    public function anEntryConfigThatRegistersNoRuleIsAFailureNotAMiss(): void
    {
        $this->expectException(ProbeFailedException::class);

        $this->probe(srcDir: $this->var->path . '/no-such-src', projectConfigDir: $this->var->path . '/no-qaConfig')
            ->probe(self::INTERFACE_BECAUSE, self::VIOLATING . '/Gateway.php');
    }

    /**
     * The command, from this package's root: the package has no
     * qaConfig/phparkitect.php, so the shipped entry config and its default
     * tier are the rules probed.
     */
    #[Test]
    public function theCommandExitsOneWhenTheRuleFires(): void
    {
        [$exitCode, $output] = $this->runCommand(self::INTERFACE_BECAUSE, self::VIOLATING . '/Gateway.php');

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('FIRED (1)', $output);
        self::assertStringContainsString('ArkitectProbe\Fixture\Gateway', $output);
    }

    #[Test]
    public function theCommandExitsZeroWhenTheRuleDoesNotFire(): void
    {
        [$exitCode, $output] = $this->runCommand(self::INTERFACE_BECAUSE, self::CONFORMING . '/GatewayInterface.php');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('did not fire', $output);
    }

    #[Test]
    public function theCommandExitsTwoOnAUsageError(): void
    {
        [$exitCode, $output] = $this->runCommand(self::INTERFACE_BECAUSE);

        self::assertSame(2, $exitCode, $output);
        self::assertStringContainsString(self::USAGE, $output);
    }

    #[Test]
    public function theCommandExitsTwoOnAMissingPath(): void
    {
        [$exitCode, $output] = $this->runCommand(self::INTERFACE_BECAUSE, self::VIOLATING . '/Nowhere.php');

        self::assertSame(2, $exitCode, $output);
        self::assertStringContainsString('Nowhere.php', $output);
    }

    private function probe(?string $srcDir = null, ?string $projectConfigDir = null): ArkitectRuleProbe
    {
        $paths = new ProjectPathsDto(
            projectRoot: \Safe\realpath(self::PROJECT),
            libraryRoot: \Safe\realpath(self::ROOT),
            binDir: self::PROJECT . '/vendor/bin',
            srcDir: $srcDir ?? \Safe\realpath(self::PROJECT . '/src'),
            testsDir: \Safe\realpath(self::PROJECT . '/tests'),
            projectConfigDir: $projectConfigDir ?? \Safe\realpath(self::PROJECT . '/qaConfig'),
            varDir: $this->var->path,
            cacheDir: $this->var->path . '/cache',
            pharDir: \Safe\realpath(self::ROOT . '/vendor-phar'),
            configDefaultsDir: \Safe\realpath(self::ROOT . '/configDefaults'),
        );

        return new ArkitectRuleProbe(
            $paths,
            new ConfigPathResolver($paths->projectConfigDir, $paths->configDefaultsDir, PlatformEnum::Generic),
            $this->php(),
            self::PROJECT . '/autoload.php',
        );
    }

    private function php(): PhpInvoker
    {
        return new PhpInvoker(new SymfonyProcessRunner(new NullOutput()), \PHP_BINARY, '1G', $this->var->path);
    }

    /** @return array{int, string} */
    private function runCommand(string ...$args): array
    {
        $result = new SymfonyProcessRunner(new NullOutput())->run(new ProcessSpecDto(
            command: [\PHP_BINARY, self::ROOT . '/bin/arkitect-rule', ...$args],
            cwd: \Safe\realpath(self::ROOT),
            streamOutput: false,
        ));

        return [$result->exitCode, $result->output];
    }
}
