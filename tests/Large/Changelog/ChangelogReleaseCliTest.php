<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Changelog;

use LTS\PHPQA\Tests\Support\GitSandbox;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `bin/changelog-release` end to end, the way the release job drives it: from
 * the project root of a real repository, with the version on stdout alone, and
 * an annotated tag whose message survives git's default cleanup intact.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ChangelogReleaseCliTest extends TestCase
{
    private const string BIN = __DIR__ . '/../../../bin/changelog-release';

    private const string CHANGELOG_FILE = 'CHANGELOG.md';

    private const string NEXT_VERSION = 'next-version';

    private const string VERSION = '85.1.0';

    private const string TAG = 'tag';

    private const string COMMIT = 'commit';

    private const string QA_PHP = 'qaConfig/qa.php';

    // php-qa-ci's own release policy: the major is the PHP line, so a breaking change moves the minor.
    private const string LOCKED_MAJOR = '<?php
return static fn (\LTS\PHPQA\Pipeline\Config\QaConfigBuilder $qa) => $qa->withReleaseVersionPolicy(' . \LTS\PHPQA\Changelog\ReleaseVersionPolicy::class . '::lockedMajorFromPhpRequirement());
';

    private const string CHANGELOG = <<<'MD'
        # Changelog

        Intro.

        ## Unreleased

        ### Changed — breaking

        - **Exit 75 on contention.** A caller that treated any non-zero exit as a
          failure now sees contention as its own outcome.

        ### Fixed

        - A fix.

        ## 85.0.0 — 2026-09-01

        ### Added

        - The first release.

        MD;

    private GitSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = GitSandbox::create([
            'composer.json'      => "{\"require\": {\"php\": \"^8.5\"}}\n",
            self::CHANGELOG_FILE => self::CHANGELOG,
            self::QA_PHP         => self::LOCKED_MAJOR,
        ]);
        $this->sandbox->git(self::TAG, '85.0.0');
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    #[Test]
    public function aReleaseIsComputedAppliedAndTaggedWithNotesGitKeepsWhole(): void
    {
        $next = $this->cli(self::NEXT_VERSION);
        self::assertSame(0, $next->getExitCode(), $next->getErrorOutput());
        self::assertSame("85.1.0\n", $next->getOutput());

        $apply = $this->cli('apply', self::VERSION, '2026-10-02');
        self::assertSame(0, $apply->getExitCode(), $apply->getErrorOutput());
        $released = $this->sandbox->read(self::CHANGELOG_FILE);
        self::assertStringContainsString("## Unreleased\n\n## 85.1.0 — 2026-10-02\n\n### Changed — breaking\n", $released);
        self::assertStringEndsWith("## 85.0.0 — 2026-09-01\n\n### Added\n\n- The first release.\n", $released);

        $notes = $this->cli('notes', self::VERSION);
        self::assertSame(0, $notes->getExitCode(), $notes->getErrorOutput());
        self::assertStringStartsWith("85.1.0 — 2026-10-02\n\nBREAKING: ", $notes->getOutput());

        $this->sandbox->root->write('notes.txt', $notes->getOutput());
        $this->sandbox->git(self::COMMIT, '-am', 'Release 85.1.0 [skip ci]');
        $this->sandbox->git(self::TAG, '-a', self::VERSION, '-F', $this->sandbox->root->path . '/notes.txt');

        self::assertSame(rtrim($notes->getOutput()), rtrim($this->sandbox->git(self::TAG, '-l', '--format=%(contents)', self::VERSION)));

        $after = $this->cli(self::NEXT_VERSION);
        self::assertSame('', $after->getOutput());
        self::assertSame(0, $after->getExitCode());
    }

    #[Test]
    public function anEntryAddedFromTheCommandLineDecidesThePatchRelease(): void
    {
        $this->cli('apply', self::VERSION, '2026-10-02');
        $this->sandbox->git(self::COMMIT, '-am', 'Release 85.1.0');
        $this->sandbox->git(self::TAG, self::VERSION);

        $add = $this->cli('add-entry', 'security', 'Bundled tool versions updated.');
        self::assertSame(0, $add->getExitCode(), $add->getErrorOutput());
        self::assertStringContainsString("## Unreleased\n\n### Security\n\n- Bundled tool versions updated.\n\n## 85.1.0", $this->sandbox->read(self::CHANGELOG_FILE));

        self::assertSame("85.1.1\n", $this->cli(self::NEXT_VERSION)->getOutput());
    }

    #[Test]
    public function anInvalidChangelogFailsWithItsIdentifier(): void
    {
        $this->sandbox->commit(self::CHANGELOG_FILE, "## Unreleased\n\n### Fixed\n\n- One.\n\n### Fixed\n\n- Two.\n");

        $result = $this->cli(self::NEXT_VERSION);

        self::assertSame(1, $result->getExitCode());
        self::assertSame('', $result->getOutput());
        self::assertStringContainsString('"### Fixed" repeats the heading at line 3', $result->getErrorOutput());
        self::assertStringContainsString('phpqaci.changelog', $result->getErrorOutput());
    }

    #[Test]
    public function withoutTheOverrideTheSameBreakingEntryReleasesANewMajor(): void
    {
        $this->sandbox->git('rm', '-q', self::QA_PHP);
        $this->sandbox->git(self::COMMIT, '-m', 'Release by semantic versioning');

        self::assertSame("86.0.0\n", $this->cli(self::NEXT_VERSION)->getOutput());
    }

    private function cli(string ...$arguments): Process
    {
        $process = new Process(['php', '-d', 'xdebug.mode=off', \Safe\realpath(self::BIN), ...array_values($arguments)], $this->sandbox->work, GitSandbox::environment());
        $process->run();

        return $process;
    }
}
