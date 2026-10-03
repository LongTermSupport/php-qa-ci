<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PhpstanDocs;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use LTS\PHPQA\PhpstanDocs\PhpstanDocsFetcherInterface;
use LTS\PHPQA\PhpstanDocs\PhpstanDocsInstaller;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The two modes are asymmetric, as for the vendored ShellCheck: an install
 * never fetches, because the catalogue is committed and its absence is a
 * broken checkout; an update is the maintainer path, and moves the catalogue
 * to the version of the shipped phpstan.phar or leaves it alone.
 *
 * @internal
 */
#[CoversClass(PhpstanDocsInstaller::class)]
#[UsesClass(PhpstanDocsCatalogue::class)]
#[UsesClass(TemporaryDirectory::class)]
#[Small]
final class PhpstanDocsInstallerTest extends TestCase
{
    private const string PAGES = 'vendor-docs/phpstan/errors';

    private const string VERSION_FILE = 'vendor-docs/phpstan/version';

    private const string OLD_PAGE = self::PAGES . '/old.identifier.md';

    private const string NEW_PAGE_FILE = 'argument.type.md';

    private const string NEW_PAGE = self::PAGES . '/' . self::NEW_PAGE_FILE;

    private const string INSTALLED_PHAR = '2.2.16';

    private const string OLDER_PHAR = '2.2.10';

    private TempDir $library;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->library = TempDir::create('phpqa-phpstan-docs-installer');
        $this->output  = new BufferedOutput();
        $this->library->write('phive.xml', '<phive><phar name="phpstan" version="^2.2" location="./vendor-phar/phpstan.phar" copy="true" installed="' . self::INSTALLED_PHAR . '"/></phive>');
    }

    protected function tearDown(): void
    {
        $this->library->remove();
    }

    #[Test]
    public function anInstallOfAConsistentCatalogueSaysNothing(): void
    {
        $this->carry(self::INSTALLED_PHAR);

        self::assertSame(0, $this->installer($this->failingFetcher(), true)->run($this->library->path, PhpstanDocsInstaller::MODE_INSTALL));
        self::assertSame('', $this->output->fetch());
    }

    #[Test]
    public function anInstallOfACatalogueAtAnotherVersionFailsWithoutFetching(): void
    {
        $this->carry(self::OLDER_PHAR);

        self::assertSame(1, $this->installer($this->failingFetcher(), true)->run($this->library->path, PhpstanDocsInstaller::MODE_INSTALL));
        $printed = $this->output->fetch();
        self::assertStringContainsString(self::OLDER_PHAR, $printed);
        self::assertStringContainsString(self::INSTALLED_PHAR, $printed);
        self::assertStringContainsString('scripts/tool-install.bash update', $printed);
    }

    #[Test]
    public function anInstallWithNoCatalogueFails(): void
    {
        self::assertSame(1, $this->installer($this->failingFetcher(), true)->run($this->library->path, PhpstanDocsInstaller::MODE_INSTALL));
    }

    #[Test]
    public function anUpdateOutsideAMaintainerBuildLeavesTheCatalogueAlone(): void
    {
        $this->carry(self::OLDER_PHAR);

        self::assertSame(0, $this->installer($this->failingFetcher(), false)->run($this->library->path, PhpstanDocsInstaller::MODE_UPDATE));
        self::assertSame(self::OLDER_PHAR . "\n", $this->library->read(self::VERSION_FILE));
    }

    #[Test]
    public function anUpdateThatFindsNothingNewLeavesTheCatalogueAsCommitted(): void
    {
        $this->carry(self::INSTALLED_PHAR);
        $fetcher = $this->fetcher(['old.identifier' => true]);

        self::assertSame(0, $this->installer($fetcher, true)->run($this->library->path, PhpstanDocsInstaller::MODE_UPDATE));

        self::assertStringContainsString('already current', $this->output->fetch());
        self::assertSame(self::INSTALLED_PHAR . "\n", $this->library->read(self::VERSION_FILE));
    }

    #[Test]
    public function anUpdateTakesNewPagesEvenWhenThePharDidNotMove(): void
    {
        $this->carry(self::INSTALLED_PHAR);
        $fetcher = $this->fetcher(['argument.type' => true]);

        self::assertSame(0, $this->installer($fetcher, true)->run($this->library->path, PhpstanDocsInstaller::MODE_UPDATE));

        self::assertSame([self::NEW_PAGE_FILE], $this->library->files(self::PAGES));
    }

    #[Test]
    public function anUpdateReplacesTheCatalogueAndRecordsThePharAndTheSource(): void
    {
        $this->carry(self::OLDER_PHAR);
        $fetcher = $this->fetcher(['argument.type' => true, 'return.type' => true]);

        self::assertSame(0, $this->installer($fetcher, true)->run($this->library->path, PhpstanDocsInstaller::MODE_UPDATE));

        self::assertSame(self::INSTALLED_PHAR . "\nphpstan/phpstan 2.3.x@0123456789ab\n", $this->library->read(self::VERSION_FILE));
        self::assertSame([self::NEW_PAGE_FILE, 'return.type.md'], $this->library->files(self::PAGES));
        self::assertSame("MIT\n", $this->library->read('vendor-docs/phpstan/LICENSE'));
        self::assertStringContainsString('Commit vendor-docs/phpstan/', $this->output->fetch());
    }

    #[Test]
    public function anUpdateNotesTheIdentifiersUpstreamListsWithNoPageYet(): void
    {
        $this->carry(self::OLDER_PHAR);
        $fetcher = $this->fetcher(['argument.type' => true, 'return.type' => false]);

        self::assertSame(0, $this->installer($fetcher, true)->run($this->library->path, PhpstanDocsInstaller::MODE_UPDATE));

        self::assertStringContainsString('no page yet: return.type', $this->output->fetch());
        self::assertSame([self::NEW_PAGE_FILE], $this->library->files(self::PAGES));
        self::assertFileExists($this->library->path . '/' . self::NEW_PAGE);
    }

    #[Test]
    public function anUpdateWhoseFetchFailsReportsTheCauseAndChangesNothing(): void
    {
        $this->carry(self::OLDER_PHAR);

        self::assertSame(1, $this->installer($this->failingFetcher(), true)->run($this->library->path, PhpstanDocsInstaller::MODE_UPDATE));

        self::assertStringContainsString('no network here', $this->output->fetch());
        self::assertFileExists($this->library->path . '/' . self::OLD_PAGE);
    }

    private function carry(string $version): void
    {
        $this->library->write(self::VERSION_FILE, $version . "\n");
        $this->library->write(self::OLD_PAGE, "# old.identifier\n");
    }

    private function installer(PhpstanDocsFetcherInterface $fetcher, bool $maintainer): PhpstanDocsInstaller
    {
        return new PhpstanDocsInstaller($this->output, $fetcher, $maintainer);
    }

    private function failingFetcher(): PhpstanDocsFetcherInterface
    {
        return new class implements PhpstanDocsFetcherInterface {
            public function fetch(string $into): string
            {
                throw new RuntimeException('no network here');
            }
        };
    }

    /**
     * A fetcher laying out phpstan/phpstan as it is: every listed identifier
     * in errorsIdentifiers.json, a page for those marked true, and the agent
     * notes file the upstream directory also holds.
     *
     * @param array<string, bool> $identifiers
     */
    private function fetcher(array $identifiers): PhpstanDocsFetcherInterface
    {
        return new readonly class($identifiers) implements PhpstanDocsFetcherInterface {
            /** @param array<string, bool> $identifiers */
            public function __construct(private array $identifiers)
            {
            }

            public function fetch(string $into): string
            {
                \Safe\mkdir($into . '/website/errors', 0o777, true);
                \Safe\mkdir($into . '/website/src', 0o777, true);
                \Safe\file_put_contents($into . '/website/src/errorsIdentifiers.json', \Safe\json_encode(array_fill_keys(array_keys($this->identifiers), [])));
                \Safe\file_put_contents($into . '/website/errors/CLAUDE.md', "# notes for the generator\n");
                \Safe\file_put_contents($into . '/LICENSE', "MIT\n");
                foreach ($this->identifiers as $identifier => $hasPage) {
                    if ($hasPage) {
                        \Safe\file_put_contents($into . '/website/errors/' . $identifier . '.md', '# ' . $identifier . "\n");
                    }
                }

                return '2.3.x@0123456789ab';
            }
        };
    }
}
