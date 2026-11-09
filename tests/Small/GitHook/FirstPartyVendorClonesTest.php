<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\GitHook;

use LTS\PHPQA\GitHook\FirstPartyVendorClones;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\FirstPartyVendors;
use LTS\PHPQA\Pipeline\Lane\DependencyFreshness\Tracking;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The pre-commit vendor hook asks which of the vendor clones it found are first
 * party: the answer is those packages, one per line, and nothing else on stdout.
 *
 * @internal
 */
#[CoversClass(FirstPartyVendorClones::class)]
#[UsesClass(FirstPartyVendors::class)]
#[UsesClass(Tracking::class)]
#[Small]
final class FirstPartyVendorClonesTest extends TestCase
{
    private const string FIRST_PARTY = 'acme/widget';

    private const string THIRD_PARTY = 'thirdparty/installer';

    private const string SETTING = 'extra.php-qa-ci.firstPartyVendors';

    private const string OWN_NAME = 'acme/app';

    private TempDir $project;

    private BufferedOutput $stdout;

    private BufferedOutput $stderr;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-first-party-clones');
        $this->stdout  = new BufferedOutput();
        $this->stderr  = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function onlyTheProjectsOwnVendorIsPrintedWhenNothingIsListed(): void
    {
        $this->manifest(['name' => self::OWN_NAME]);

        self::assertSame(0, $this->ask(self::FIRST_PARTY, self::THIRD_PARTY));
        self::assertSame(self::FIRST_PARTY . "\n", $this->stdout->fetch());
        self::assertSame('', $this->stderr->fetch());
    }

    #[Test]
    public function theListedVendorsReplaceTheOwnVendor(): void
    {
        $this->manifest(['name' => 'example/site', 'extra' => ['php-qa-ci' => ['firstPartyVendors' => ['acme', 'thirdparty']]]]);

        self::assertSame(0, $this->ask(self::FIRST_PARTY, 'example/lib', self::THIRD_PARTY));
        self::assertSame(self::FIRST_PARTY . "\n" . self::THIRD_PARTY . "\n", $this->stdout->fetch());
    }

    #[Test]
    public function noFirstPartyVendorIsOneNoteOnStderrAndNothingOnStdout(): void
    {
        $this->manifest(['type' => 'project']);

        self::assertSame(0, $this->ask(self::FIRST_PARTY));
        self::assertSame('', $this->stdout->fetch());
        $note = $this->stderr->fetch();
        self::assertStringStartsWith('NOTE: ', $note);
        self::assertSame(1, substr_count($note, "\n"), $note);
        self::assertStringContainsString(self::SETTING, $note);
    }

    #[Test]
    public function anEmptyListProtectsNothing(): void
    {
        $this->manifest(['name' => self::OWN_NAME, 'extra' => ['php-qa-ci' => ['firstPartyVendors' => []]]]);

        self::assertSame(0, $this->ask(self::FIRST_PARTY));
        self::assertSame('', $this->stdout->fetch());
        self::assertStringContainsString('is empty', $this->stderr->fetch());
    }

    #[Test]
    public function aMalformedSettingIsAnErrorWithTheSettingsMessage(): void
    {
        $this->manifest(['name' => self::OWN_NAME, 'extra' => ['php-qa-ci' => ['firstPartyVendors' => 'acme']]]);

        self::assertSame(1, $this->ask(self::FIRST_PARTY));
        self::assertSame('', $this->stdout->fetch());
        self::assertStringContainsString(self::SETTING . ' must be a list of vendor names', $this->stderr->fetch());
    }

    #[Test]
    public function anUnreadableComposerJsonIsAnError(): void
    {
        $this->project->write('composer.json', '{');

        self::assertSame(1, $this->ask(self::FIRST_PARTY));
        self::assertSame('', $this->stdout->fetch());
        self::assertStringContainsString('composer.json is not valid JSON', $this->stderr->fetch());
    }

    /** @param array<string, mixed> $manifest */
    private function manifest(array $manifest): void
    {
        $this->project->write('composer.json', \Safe\json_encode($manifest, \JSON_THROW_ON_ERROR));
    }

    private function ask(string ...$packages): int
    {
        return FirstPartyVendorClones::main($this->stdout, $this->stderr, $this->project->path, ...$packages);
    }
}
