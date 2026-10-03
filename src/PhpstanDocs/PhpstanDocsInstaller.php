<?php

declare(strict_types=1);

namespace LTS\PHPQA\PhpstanDocs;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Verifies (install) or refreshes (maintainer update) the PHPStan identifier
 * pages under `vendor-docs/phpstan/`, so `rule-doc` answers a PHPStan
 * identifier offline at the version of the phpstan.phar this package ships.
 *
 * The modes are asymmetric, as for the vendored ShellCheck. An install never
 * fetches: the pages are committed, so a catalogue missing or at another
 * version than the phar is a broken checkout, and fetching behind the user's
 * back would hide it. An update takes the pages from phpstan/phpstan.
 *
 * @internal
 */
final readonly class PhpstanDocsInstaller
{
    /** Verify the committed catalogue; never fetch. */
    public const string MODE_INSTALL = 'install';

    /** The maintainer path: fetch, and rewrite the catalogue when anything changed. */
    public const string MODE_UPDATE = 'update';

    /** Where the fetched checkout keeps the pages. */
    private const string FETCHED_PAGES = '/website/errors';

    /** Where it keeps PHPStan's own list of every identifier. */
    private const string FETCHED_IDENTIFIERS = '/website/src/errorsIdentifiers.json';

    /** Where it keeps the licence the pages are distributed under. */
    private const string FETCHED_LICENSE = '/LICENSE';

    /** A page's file extension; the file name before it is the identifier. */
    private const string PAGE_SUFFIX = '.md';

    public function __construct(
        private OutputInterface $output,
        private PhpstanDocsFetcherInterface $fetcher = new GitPhpstanDocsFetcher(),
        private ?bool $maintainer = null,
    ) {
    }

    /** @return int the process exit code */
    public static function main(string $libraryRoot, string $mode): int
    {
        return new self(new ConsoleOutput())->run($libraryRoot, $mode);
    }

    public function run(string $libraryRoot, string $mode): int
    {
        $catalogue = new PhpstanDocsCatalogue($libraryRoot);
        $carried   = $catalogue->carriedVersion();
        $installed = $catalogue->installedPhpstanVersion();

        if (null === $installed) {
            return $this->fail('phive.xml records no installed phpstan.phar, so there is no version for the PHPStan identifier pages to match.');
        }

        if (self::MODE_UPDATE !== $mode) {
            return $carried === $installed
                ? 0
                : $this->fail(\sprintf(
                    "the PHPStan identifier pages under %s are at %s, but the shipped phpstan.phar is %s.\n"
                    . 'This indicates a corrupted or incomplete checkout. A maintainer refreshes them with: scripts/tool-install.bash update',
                    PhpstanDocsCatalogue::DIRECTORY,
                    $carried ?? '(none)',
                    $installed,
                ));
        }

        if (!$this->isMaintainerEnvironment()) {
            $this->output->writeln('PHPStan identifier pages left as committed — skipping update (not a maintainer build).');

            return 0;
        }

        return $this->update($libraryRoot, $carried, $installed);
    }

    /**
     * Fetched on every maintainer update, not only when the phar moved: an
     * extension update can add identifiers while the phar stays where it was.
     * The committed catalogue is rewritten only when a page or the phar changed,
     * so an update that found nothing new leaves no diff.
     */
    private function update(string $libraryRoot, ?string $carried, string $version): int
    {
        $staging  = $this->stagingDirectory();
        $checkout = $staging . '/phpstan';

        try {
            $source   = 'phpstan/phpstan ' . $this->fetcher->fetch($checkout);
            $pages    = $this->pages($checkout . self::FETCHED_PAGES);
            // The tip lists identifiers no release prints yet, often before their
            // page is written; whether a shipped tool prints one with no page is
            // ForeignIdentifierCatalogueTest's question, so here it is a note.
            $unpaged = array_values(array_diff($this->identifiers($checkout . self::FETCHED_IDENTIFIERS), array_keys($pages)));
            if ([] !== $unpaged) {
                $this->output->writeln(\sprintf(
                    'Note: %s lists %d identifier(s) with no page yet: %s',
                    $source,
                    \count($unpaged),
                    implode(', ', $unpaged),
                ));
            }

            if ($carried === $version && $this->carries($libraryRoot, $pages)) {
                $this->output->writeln(\sprintf('PHPStan identifier pages already current with %s.', $source));

                return 0;
            }

            $this->output->writeln(\sprintf('Updating the PHPStan identifier pages to %s, alongside phpstan.phar %s...', $source, $version));
            $this->replace($libraryRoot, $pages, $checkout . self::FETCHED_LICENSE, $version, $source);
        } catch (Throwable $throwable) {
            return $this->fail('could not update the PHPStan identifier pages: ' . $throwable->getMessage());
        } finally {
            $this->remove($staging);
        }

        $this->output->writeln(\sprintf('IMPORTANT: the PHPStan identifier pages are tracked in git. Commit %s/.', PhpstanDocsCatalogue::DIRECTORY));

        return 0;
    }

    /**
     * The fetched pages by identifier. Only a file named as an identifier is a
     * page: the upstream directory also holds the notes its generator reads.
     *
     * @return array<string, string>
     */
    private function pages(string $directory): array
    {
        $pages = [];
        foreach (\Safe\scandir($directory) as $entry) {
            if (!\is_string($entry) || !str_ends_with($entry, self::PAGE_SUFFIX)) {
                continue;
            }

            $identifier = substr($entry, 0, -\strlen(self::PAGE_SUFFIX));
            if (1 === \Safe\preg_match(PhpstanDocsCatalogue::IDENTIFIER_PATTERN, $identifier)) {
                $pages[$identifier] = $directory . '/' . $entry;
            }
        }

        ksort($pages);

        return $pages;
    }

    /** @return list<string> every identifier PHPStan's own catalogue lists at this release */
    private function identifiers(string $file): array
    {
        $listed = \Safe\json_decode(\Safe\file_get_contents($file), true);
        if (!\is_array($listed)) {
            throw new RuntimeException('errorsIdentifiers.json is not an object');
        }

        return array_map(strval(...), array_keys($listed));
    }

    /**
     * Whether the committed catalogue already holds exactly these pages.
     *
     * @param array<string, string> $pages
     */
    private function carries(string $libraryRoot, array $pages): bool
    {
        $target = $libraryRoot . '/' . PhpstanDocsCatalogue::PAGES;
        if (!is_dir($target) || array_keys($this->pages($target)) !== array_keys($pages)) {
            return false;
        }

        return array_all(
            $pages,
            static fn (string $page, string $identifier): bool => \Safe\file_get_contents($page) === \Safe\file_get_contents($target . '/' . $identifier . self::PAGE_SUFFIX),
        );
    }

    /** @param array<string, string> $pages */
    private function replace(string $libraryRoot, array $pages, string $license, string $version, string $source): void
    {
        $target = $libraryRoot . '/' . PhpstanDocsCatalogue::PAGES;
        $this->remove($target);
        \Safe\mkdir($target, 0o755, true);
        foreach ($pages as $identifier => $page) {
            \Safe\copy($page, $target . '/' . $identifier . self::PAGE_SUFFIX);
        }

        \Safe\copy($license, $libraryRoot . '/' . PhpstanDocsCatalogue::LICENSE_FILE);
        \Safe\file_put_contents($libraryRoot . '/' . PhpstanDocsCatalogue::VERSION_FILE, $version . "\n" . $source . "\n");
    }

    private function fail(string $message): int
    {
        $this->output->writeln('ERROR: ' . $message);

        return 1;
    }

    private function isMaintainerEnvironment(): bool
    {
        return $this->maintainer ?? null !== new ExecutableFinder()->find('phive');
    }

    private function stagingDirectory(): string
    {
        $path = \Safe\tempnam(sys_get_temp_dir(), 'phpqa-phpstan-docs');
        \Safe\unlink($path);
        \Safe\mkdir($path, 0o700, true);

        return $path;
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && !$entry->isLink()) {
                \Safe\rmdir($entry->getPathname());
            } else {
                \Safe\unlink($entry->getPathname());
            }
        }

        \Safe\rmdir($directory);
    }
}
