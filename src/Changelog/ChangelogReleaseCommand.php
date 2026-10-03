<?php

declare(strict_types=1);

namespace LTS\PHPQA\Changelog;

use InvalidArgumentException;
use LTS\PHPQA\Changelog\Exception\ChangelogHistoryException;
use LTS\PHPQA\Changelog\Exception\ChangelogReleaseException;
use LTS\PHPQA\Changelog\Exception\InvalidChangelogException;
use LTS\PHPQA\Pipeline\Process\SymfonyProcessRunner;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/changelog-release`: the release steps CI runs, and the entry a
 * workflow adds, over the project's CHANGELOG.md. Run from the project root.
 *
 * Only a result goes to stdout (the version, the notes); everything a human
 * reads goes to stderr, so `$(changelog-release next-version)` is exactly the
 * version or empty. Exit 0 on success, 1 on any refusal or failure.
 *
 * @api
 */
final readonly class ChangelogReleaseCommand
{
    public const string USAGE = <<<'TXT'
        Usage: changelog-release <command> [arguments]   (run from the project root)

          next-version                print the version "## Unreleased" releases as on the PHP line
                                      composer.json names; print nothing when it has no entries
          apply <version> <date>      release "## Unreleased" as "## <version> — <date>" (YYYY-MM-DD)
                                      under a fresh, empty "## Unreleased"
          notes <version>             print the tag annotation for a released version
          pending-tags                print "<version> <commit>" for every released section on the line
                                      newer than its newest tag, oldest first: the commit is the one
                                      that wrote the section, and is what the tag must point at
          add-entry <heading> <text>  add "- <text>" under a heading of "## Unreleased"; the heading
                                      is its label or slug (added, changed, changed-breaking,
                                      deprecated, removed, fixed, security)
          add-tool-updates            add a "Changed" entry naming every bundled tool whose pinned
                                      version differs from HEAD's (phive.xml, the ShellCheck pin,
                                      build/*/composer.lock); add nothing when none moved

        TXT;

    public function __construct(
        private string $projectRoot,
        private ChangelogGit $git,
        private OutputInterface $stdout,
        private OutputInterface $stderr,
        private ChangelogParser $parser = new ChangelogParser(),
        private ReleaseVersionCalculator $calculator = new ReleaseVersionCalculator(),
    ) {
    }

    public static function main(string $projectRoot, OutputInterface $stdout, OutputInterface $stderr, string ...$arguments): int
    {
        $git = new ChangelogGit(new SymfonyProcessRunner(new NullOutput()), $projectRoot);

        return new self($projectRoot, $git, $stdout, $stderr)->run(...$arguments);
    }

    public function run(string ...$arguments): int
    {
        try {
            return $this->dispatch(...array_values($arguments));
        } catch (InvalidChangelogException $invalidChangelogException) {
            $this->error($invalidChangelogException->getMessage());
            $this->error(\sprintf("\n🪪  %s  (vendor/bin/rule-doc %s)", ChangelogCheck::IDENTIFIER, ChangelogCheck::IDENTIFIER));

            return 1;
        } catch (ChangelogReleaseException|ChangelogHistoryException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }
    }

    private function dispatch(string ...$arguments): int
    {
        $arguments = array_values($arguments);
        if (1 === \count($arguments) && 'next-version' === $arguments[0]) {
            return $this->nextVersion();
        }

        if (1 === \count($arguments) && 'pending-tags' === $arguments[0]) {
            return $this->pendingTags();
        }

        if (1 === \count($arguments) && 'add-tool-updates' === $arguments[0]) {
            return $this->addToolUpdates();
        }

        if (2 === \count($arguments) && 'notes' === $arguments[0]) {
            return $this->notes($arguments[1]);
        }

        if (3 === \count($arguments) && 'apply' === $arguments[0]) {
            return $this->apply($arguments[1], $arguments[2]);
        }

        if (3 === \count($arguments) && 'add-entry' === $arguments[0]) {
            return $this->addEntry($arguments[1], $arguments[2]);
        }

        return $this->usage();
    }

    private function nextVersion(): int
    {
        $bump = $this->parser->parse($this->changelog())->bump();
        if (!$bump instanceof ReleaseBumpEnum) {
            $this->error(\sprintf('No release is due: "%s" has no entries.', ChangelogParser::UNRELEASED_HEADING));

            return 0;
        }

        $major = $this->calculator->lineMajor($this->read('composer.json'));
        $this->stdout->write($this->calculator->next($major, $bump, ...$this->git->tags()) . "\n", false, OutputInterface::OUTPUT_RAW);

        return 0;
    }

    private function pendingTags(): int
    {
        $changelog = $this->changelog();
        $major     = $this->calculator->lineMajor($this->read('composer.json'));
        foreach (new ReleasedSections($this->parser, $this->calculator)->untagged($changelog, $major, ...$this->git->tags()) as $version) {
            $heading = ChangelogParser::SECTION_PREFIX . $this->parser->release($changelog, $version)->title;
            $commit  = $this->git->commitChanging($heading, ChangelogCheck::CHANGELOG) ?? throw new ChangelogReleaseException(\sprintf(
                'no commit reachable from HEAD wrote "%s" into %s; commit the release before tagging it',
                $heading,
                ChangelogCheck::CHANGELOG,
            ));
            $this->stdout->write($version . ' ' . $commit . "\n", false, OutputInterface::OUTPUT_RAW);
        }

        return 0;
    }

    private function apply(string $version, string $date): int
    {
        $released = new ChangelogReleaseWriter()->apply($this->parser->parse($this->changelog()), $version, $date);
        $this->write($released);
        $this->error(\sprintf('%s: released "%s" as %s — %s.', ChangelogCheck::CHANGELOG, ChangelogParser::UNRELEASED_HEADING, $version, $date));

        return 0;
    }

    private function notes(string $version): int
    {
        $section = $this->parser->release($this->changelog(), $version);
        $this->stdout->write(new ReleaseNotesRenderer()->render($section), false, OutputInterface::OUTPUT_RAW);

        return 0;
    }

    private function addEntry(string $headingArgument, string $text): int
    {
        $heading = ChangelogHeadingEnum::fromArgument($headingArgument);
        $this->write(new ChangelogEntryAdder()->add($this->parser->parse($this->changelog()), $heading, $text));
        $this->error(\sprintf('%s: added an entry under "### %s".', ChangelogCheck::CHANGELOG, $heading->value));

        return 0;
    }

    private function addToolUpdates(): int
    {
        $tools       = new BundledToolVersions();
        $directories = [];
        foreach (\Safe\glob($this->projectRoot . '/build/*/composer.json') as $manifest) {
            if (\is_string($manifest)) {
                $directories[] = basename(\dirname($manifest));
            }
        }

        $before      = $tools->read(fn (string $path): ?string => $this->git->fileAt('HEAD', $path), ...$directories);
        $after       = $tools->read(fn (string $path): ?string => is_file($this->projectRoot . '/' . $path) ? $this->read($path) : null, ...$directories);

        $entry = $tools->entry($before, $after);
        if (null === $entry) {
            $this->error('No bundled tool version differs from HEAD; nothing to record.');

            return 0;
        }

        return $this->addEntry(ChangelogHeadingEnum::Changed->value, $entry);
    }

    private function usage(): int
    {
        $this->error(rtrim(self::USAGE));

        return 1;
    }

    private function changelog(): string
    {
        return $this->read(ChangelogCheck::CHANGELOG);
    }

    private function read(string $file): string
    {
        $path = $this->projectRoot . '/' . $file;
        if (!is_file($path)) {
            throw new ChangelogReleaseException(\sprintf('no %s in %s', $file, $this->projectRoot));
        }

        return \Safe\file_get_contents($path);
    }

    private function write(string $changelog): void
    {
        \Safe\file_put_contents($this->projectRoot . '/' . ChangelogCheck::CHANGELOG, $changelog);
    }

    private function error(string $message): void
    {
        $this->stderr->write($message . "\n", false, OutputInterface::OUTPUT_RAW);
    }
}
