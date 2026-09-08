<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Cli;

use LTS\PHPQA\Pipeline\Cli\ArgumentsParser;
use LTS\PHPQA\Pipeline\Cli\Dto\RunRequestDto;
use LTS\PHPQA\Pipeline\Cli\Exception\UsageException;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ArgumentsParser::class)]
#[CoversClass(RunRequestDto::class)]
#[CoversClass(UsageException::class)]
#[Small]
final class ArgumentsParserTest extends TestCase
{
    private ArgumentsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ArgumentsParser(ToolRegistry::shipped(), 'vendor/bin');
    }

    #[Test]
    public function noArgumentsMeansTheFullPipeline(): void
    {
        $request = $this->parser->parse();

        self::assertNull($request->tool);
        self::assertNull($request->path);
        self::assertFalse($request->json);
    }

    #[Test]
    public function aToolTokenResolvesToItsCanonicalName(): void
    {
        self::assertSame('phpstan', $this->parser->parse('-t', 'stan')->tool);
        self::assertSame('phpstan', $this->parser->parse('-tstan')->tool);
        self::assertSame('allLintingTools', $this->parser->parse('-t', 'allLints')->tool);
    }

    #[Test]
    public function aPseudoToolRecordsTheTokenThatWasTyped(): void
    {
        $request = $this->parser->parse('-t', 'uniterate');

        self::assertSame('phpunit', $request->tool);
        self::assertSame('uniterate', $request->selectedToken);
        self::assertNull($this->parser->parse('-t', 'unit')->selectedToken);
    }

    #[Test]
    public function pathsComeFromDashPOrASingleBareArgument(): void
    {
        self::assertSame('src/Domain', $this->parser->parse('-t', 'stan', '-p', 'src/Domain')->path);
        self::assertSame('src/Domain', $this->parser->parse('-t', 'stan', 'src/Domain')->path);
        self::assertSame('src', $this->parser->parse('-psrc')->path);
    }

    #[Test]
    public function jsonIsAcceptedAnywhereForPhpstan(): void
    {
        self::assertTrue($this->parser->parse('--json', '-t', 'stan')->json);
        self::assertTrue($this->parser->parse('-t', 'phpstan', '--json')->json);
    }

    #[Test]
    public function helpRaisesAUsageErrorThatShowsTheUsage(): void
    {
        $this->assertUsage(['-h'], '', showUsage: true);
    }

    #[Test]
    public function anUnknownToolShowsTheUsage(): void
    {
        $this->assertUsage(['-t', 'nope'], 'Invalid tool: nope', showUsage: true);
        $this->assertUsage(['-t', 'psr4Validate'], 'Invalid tool: psr4Validate', showUsage: true);
    }

    #[Test]
    public function aMissingOptionValueShowsTheUsage(): void
    {
        $this->assertUsage(['-t'], 'Option -t requires an argument', showUsage: true);
        $this->assertUsage(['-p'], 'Option -p requires an argument', showUsage: true);
    }

    #[Test]
    public function unknownFlagsAndExtraArgumentsAreRefused(): void
    {
        $this->assertUsage(['-t', 'stan', '--help'], 'Unsupported argument: --help', showUsage: false);
        $this->assertUsage(['-x'], 'Unsupported argument: -x', showUsage: false);
        $this->assertUsage(['src', 'tests'], 'Multiple paths not supported: src tests', showUsage: false);
        $this->assertUsage(['-p', 'src', 'extra'], 'Unsupported argument: extra', showUsage: false);
    }

    #[Test]
    public function aPathWithANonPathToolIsRefusedAndListsThePathSupportingTokens(): void
    {
        try {
            $this->parser->parse('-t', 'cr', '-p', 'src');
            self::fail('expected UsageException');
        } catch (UsageException $usageException) {
            self::assertStringContainsString("Tool 'cr' does not support path-specific execution", $usageException->getMessage());
            self::assertStringContainsString('  stan', $usageException->getMessage());
            self::assertStringContainsString('vendor/bin/qa -t cr', $usageException->getMessage());
        }
    }

    #[Test]
    public function jsonNeedsAToolThatSupportsIt(): void
    {
        $this->assertUsage(['--json'], '--json requires a single tool (-t)', showUsage: false);
        $this->assertUsage(['--json', '-t', 'unit'], "--json is not yet supported for 'phpunit'", showUsage: false);
    }

    #[Test]
    public function theUsageTextListsEveryToolFromTheRegistry(): void
    {
        $usage = $this->parser->usage();

        self::assertStringStartsWith("Usage:\nvendor/bin/qa [-t tool to run ] [ -p path to scan ] [ --json ]", $usage);
        self::assertStringContainsString(\sprintf('     %-26s %s', 'allCS', 'all coding standards tools'), $usage);
        self::assertStringContainsString('vp|versionPins', $usage);
    }

    /** @param list<string> $argv */
    private function assertUsage(array $argv, string $expectedMessage, bool $showUsage): void
    {
        try {
            $this->parser->parse(...$argv);
        } catch (UsageException $usageException) {
            self::assertStringContainsString($expectedMessage, $usageException->getMessage());
            self::assertSame($showUsage, $usageException->showUsage);

            return;
        }

        self::fail('expected a UsageException for ' . implode(' ', $argv));
    }
}
