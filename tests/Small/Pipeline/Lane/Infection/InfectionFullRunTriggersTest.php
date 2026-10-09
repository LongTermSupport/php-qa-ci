<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Lane\Infection\InfectionFullRunTriggers;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InfectionFullRunTriggers::class)]
#[Small]
final class InfectionFullRunTriggersTest extends TestCase
{
    private const string PHPUNIT_XML = 'qaConfig/phpunit.xml';

    private const string INFECTION_JSON = 'qaConfig/infection.json';

    private TempDir $project;

    private string $root;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-full-run-triggers');
        $this->root    = $this->project->path;
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function theTriggersAreTheInfectionAndPhpunitConfigsAndTheBootstrapTheXmlNames(): void
    {
        $infection = $this->project->write(self::INFECTION_JSON, '{}');
        $phpunit   = $this->project->write(self::PHPUNIT_XML, '<phpunit bootstrap="../tests/support/boot.php"/>');

        self::assertSame(
            [
                $this->root . '/' . self::INFECTION_JSON,
                $this->root . '/qaConfig/infection.json5',
                $this->root . '/qaConfig/infection.json.dist',
                $this->root . '/qaConfig/infection.json5.dist',
                $this->root . '/' . self::PHPUNIT_XML,
                $this->root . '/qaConfig/phpunit.xml.dist',
                $this->root . '/qaConfig/phpunit.dist.xml',
                $this->root . '/tests/support/boot.php',
            ],
            $this->triggers($infection, $phpunit),
            'the bootstrap is read from the XML, not assumed to be tests/bootstrap.php',
        );
    }

    #[Test]
    public function composerFilesAndOtherQaConfigFilesAreNeverTriggers(): void
    {
        $triggers = $this->triggers($this->project->write(self::INFECTION_JSON, '{}'), $this->project->write(self::PHPUNIT_XML, '<phpunit/>'));

        foreach (['/composer.json', '/composer.lock', '/qaConfig', '/qaConfig/qa.php', '/qaConfig/phpstan.neon', '/qaConfig/PHPStan/Rule.php'] as $path) {
            self::assertNotContains($this->root . $path, $triggers);
        }
    }

    #[Test]
    public function aPhpunitXmlWithNoBootstrapNamesNone(): void
    {
        $triggers = $this->triggers($this->project->write(self::INFECTION_JSON, '{}'), $this->project->write(self::PHPUNIT_XML, '<phpunit colors="true"/>'));

        self::assertNotContains($this->root . '/tests/bootstrap.php', $triggers);
        self::assertCount(7, $triggers);
    }

    #[Test]
    public function aPhpunitXmlThatDoesNotParseIsStillATriggerButNamesNoBootstrap(): void
    {
        $phpunit  = $this->project->write(self::PHPUNIT_XML, '<phpunit bootstrap="x.php"');
        $triggers = $this->triggers($this->project->write(self::INFECTION_JSON, '{}'), $phpunit);

        self::assertContains($phpunit, $triggers);
        self::assertCount(7, $triggers);
    }

    #[Test]
    public function theShippedDefaultsInsideTheProjectAreTriggersAndTheirBootstrapResolvesAgainstThem(): void
    {
        $infection = $this->project->write('vendor/lts/php-qa-ci/configDefaults/generic/infection.json', '{}');
        $phpunit   = $this->project->write('vendor/lts/php-qa-ci/configDefaults/generic/phpunit.xml', '<phpunit bootstrap="../../../../../tests/bootstrap.php"/>');

        $triggers = $this->triggers($infection, $phpunit);

        self::assertContains($infection, $triggers, 'the resolved default is what Infection reads');
        self::assertContains($phpunit, $triggers);
        self::assertContains($this->root . '/qaConfig/phpunit.xml', $triggers, 'adding a project override changes the resolved config');
        self::assertContains($this->root . '/tests/bootstrap.php', $triggers);
    }

    #[Test]
    public function resolvedConfigsOutsideTheProjectAreNotGitPathspecs(): void
    {
        $library = TempDir::create('phpqa-full-run-library');

        try {
            $phpunit  = $library->write('configDefaults/generic/phpunit.xml', '<phpunit bootstrap="../../../elsewhere/bootstrap.php"/>');
            $triggers = $this->triggers($library->path . '/configDefaults/generic/infection.json', $phpunit);
        } finally {
            $library->remove();
        }

        foreach ($triggers as $trigger) {
            self::assertStringStartsWith($this->root . '/', $trigger);
        }

        self::assertCount(7, $triggers);
    }

    /** @return list<string> */
    private function triggers(string $infection, string $phpunit): array
    {
        return new InfectionFullRunTriggers()->paths($this->root, $this->root . '/qaConfig', $infection, $phpunit);
    }
}
