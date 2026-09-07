<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\InfectionConfig;

use LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesCheck;
use LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InfectionConfigSourceDirectoriesCheck::class)]
#[UsesClass(InfectionConfigSourceDirectoriesDetector::class)]
#[Small]
final class InfectionConfigSourceDirectoriesCheckTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/Fixture';
    }

    #[Test]
    public function itReturnsZeroAndPrintsTheExactOkMessageWhenEveryDeclaredDirectoryExists(): void
    {
        $check      = new InfectionConfigSourceDirectoriesCheck();
        $configPath = $this->fixtureDir . '/Real/infection.json';

        $expected = 'infection.json source.directories all resolve to real directories — OK ('
            . $configPath . ').' . \PHP_EOL;

        self::expectOutputString($expected);
        self::assertSame(0, $check->run($configPath));
    }

    #[Test]
    public function itReturnsOneAndPrintsTheExactFailureBlockWhenADirectoryIsMissing(): void
    {
        $check      = new InfectionConfigSourceDirectoriesCheck();
        $configPath = $this->fixtureDir . '/Broken/infection.json';

        $expected = \PHP_EOL . 'ERROR — infection.json declares a source directory that does not exist' . \PHP_EOL
            . '-------------------------------------------------------------------------' . \PHP_EOL
            . 'Config file: ' . $configPath . \PHP_EOL . \PHP_EOL
            . '  - source.directories entry "src" does not resolve to an existing directory '
            . '(resolved, relative to the config file\'s own directory, to "'
            . $this->fixtureDir . '/Broken/src").' . \PHP_EOL
            . \PHP_EOL . '🪪  ' . InfectionConfigSourceDirectoriesCheck::IDENTIFIER
            . '  (vendor/bin/rule-doc ' . InfectionConfigSourceDirectoriesCheck::IDENTIFIER . ')' . \PHP_EOL;

        self::expectOutputString($expected);
        self::assertSame(1, $check->run($configPath));
    }

    #[Test]
    public function itReturnsOneAndPrintsTheExactMissingConfigMessage(): void
    {
        $check      = new InfectionConfigSourceDirectoriesCheck();
        $configPath = $this->fixtureDir . '/nope/infection.json';

        $expected = 'ERROR — infection.json config path does not exist: ' . $configPath . \PHP_EOL;

        self::expectOutputString($expected);
        self::assertSame(1, $check->run($configPath));
    }

    #[Test]
    public function itReturnsOneAndPrintsTheExactInvalidJsonMessage(): void
    {
        $check      = new InfectionConfigSourceDirectoriesCheck();
        $configPath = $this->fixtureDir . '/Invalid/infection.json';

        $expected = 'ERROR — infection.json could not be parsed as valid JSON: ' . $configPath
            . ' (Syntax error)' . \PHP_EOL;

        self::expectOutputString($expected);
        self::assertSame(1, $check->run($configPath));
    }

    #[Test]
    public function theFailureMessageCarriesTheIdentifierAndTheRuleDocCommand(): void
    {
        $check      = new InfectionConfigSourceDirectoriesCheck();
        $configPath = $this->fixtureDir . '/Broken/infection.json';

        $this->expectOutputRegex('#phpqaci\.infectionConfigSourceDirectoriesMustExist.*rule-doc phpqaci\.infectionConfigSourceDirectoriesMustExist#s');
        $check->run($configPath);
    }
}
