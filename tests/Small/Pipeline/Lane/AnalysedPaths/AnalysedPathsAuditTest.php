<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\AnalysedPaths;

use LTS\PHPQA\Pipeline\Lane\AnalysedPaths\AnalysedPathsAudit;
use LTS\PHPQA\Pipeline\Lane\AnalysedPaths\Dto\AnalysedPathsVerdictDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The decision behind the analysedPaths lane: every PHP file is under an
 * analysed path, an ignored path, a declared unanalysed path or a default
 * exclusion, and whatever is under none of them is reported by directory.
 *
 * @internal
 */
#[CoversClass(AnalysedPathsAudit::class)]
#[CoversClass(AnalysedPathsVerdictDto::class)]
#[Small]
final class AnalysedPathsAuditTest extends TestCase
{
    private const string SRC = 'src';

    private const string TESTS = 'tests';

    private const string CONFIG = 'config';

    private const string SRC_FILE = 'src/Service.php';

    private const string TEST_FILE = 'tests/ServiceTest.php';

    private const string SERVICES = 'config/services.php';

    private const string BUNDLES = 'config/bundles.php';

    private const string PACKAGE = 'config/packages/doctrine.php';

    private const string REASON = 'loaded by the kernel, analysed through the container lint';

    #[Test]
    public function aProjectWhosePhpIsAllUnderTheAnalysedPathsPasses(): void
    {
        $verdict = $this->audit()->audit(self::SRC_FILE, self::TEST_FILE);

        self::assertTrue($verdict->passes());
        self::assertSame(2, $verdict->phpFiles);
        self::assertSame([], $verdict->unclassified);
    }

    #[Test]
    public function eachUnclassifiedDirectoryIsReportedOnceWithItsFileCountInPathOrder(): void
    {
        $verdict = $this->audit()->audit(self::SRC_FILE, self::SERVICES, self::PACKAGE, self::BUNDLES);

        self::assertFalse($verdict->passes());
        self::assertSame(['config/' => 2, 'config/packages/' => 1], $verdict->unclassified);
    }

    /** A file at the root is its own unit: declaring "." would declare the whole project. */
    #[Test]
    public function aRootLevelFileIsReportedByItsOwnName(): void
    {
        $verdict = $this->audit()->audit(self::SRC_FILE, 'rector.php');

        self::assertSame(['rector.php' => 1], $verdict->unclassified);
    }

    #[Test]
    public function aDeclaredDirectoryAccountsForEverythingBeneathItAndIsCounted(): void
    {
        $verdict = $this->audit([self::CONFIG => self::REASON])->audit(self::SRC_FILE, self::SERVICES, self::PACKAGE);

        self::assertTrue($verdict->passes());
        self::assertSame([self::CONFIG => 2], $verdict->declaredInUse);
    }

    #[Test]
    public function aDeclaredFileAccountsForThatFileOnly(): void
    {
        $verdict = $this->audit([self::SERVICES => self::REASON])->audit(self::SERVICES, self::BUNDLES);

        self::assertSame(['config/' => 1], $verdict->unclassified);
        self::assertSame([self::SERVICES => 1], $verdict->declaredInUse);
    }

    /** A prefix of a directory name is not the directory: `config` does not cover `configuration/`. */
    #[Test]
    public function aDeclarationCoversWholePathSegmentsOnly(): void
    {
        $verdict = $this->audit([self::CONFIG => self::REASON])->audit(self::SERVICES, 'configuration/app.php');

        self::assertSame(['configuration/' => 1], $verdict->unclassified);
    }

    #[Test]
    public function anIgnoredPathAccountsForThePhpBeneathIt(): void
    {
        $verdict = $this->audit([], self::CONFIG)->audit(self::SRC_FILE, self::SERVICES);

        self::assertTrue($verdict->passes());
    }

    #[Test]
    public function vendorAndVarAreUnanalysedByDefault(): void
    {
        $verdict = $this->audit()->audit('vendor/acme/lib/src/A.php', 'var/cache/dev/Container.php');

        self::assertTrue($verdict->passes());
        self::assertArrayHasKey('vendor', AnalysedPathsAudit::DEFAULT_UNANALYSED);
        self::assertArrayHasKey('var', AnalysedPathsAudit::DEFAULT_UNANALYSED);
    }

    #[Test]
    public function anAnalysedPathOfTheWholeProjectCoversEverything(): void
    {
        $verdict = new AnalysedPathsAudit([''], [], [])->audit(self::SERVICES, 'rector.php');

        self::assertTrue($verdict->passes());
    }

    /**
     * A declaration that matches nothing is not harmless: it would silently
     * account for PHP added there later, without anyone having decided so.
     */
    #[Test]
    public function aDeclarationMatchingNoPhpFileIsStale(): void
    {
        $verdict = $this->audit(['migrations' => self::REASON])->audit(self::SRC_FILE);

        self::assertFalse($verdict->passes());
        self::assertSame(['migrations'], $verdict->stale);
    }

    /** The analysed paths win: PHP under one is analysed, whatever else claims it. */
    #[Test]
    public function aDeclarationInsideAnAnalysedPathContradictsIt(): void
    {
        $verdict = $this->audit(['src/Legacy' => self::REASON])->audit(self::SRC_FILE, 'src/Legacy/Old.php');

        self::assertFalse($verdict->passes());
        self::assertSame(['src/Legacy' => self::SRC], $verdict->contradicted);
        self::assertSame([], $verdict->stale);
        self::assertSame([], $verdict->declaredInUse);
    }

    #[Test]
    public function pathsAreComparedWithoutLeadingDotSlashOrTrailingSlash(): void
    {
        $audit   = new AnalysedPathsAudit(['./src/', '/tests'], ['./config/'], []);
        $verdict = $audit->audit(self::SRC_FILE, self::TEST_FILE, './config/services.php');

        self::assertTrue($verdict->passes());
    }

    /**
     * @param array<string, string> $declared
     */
    private function audit(array $declared = [], string ...$ignored): AnalysedPathsAudit
    {
        return new AnalysedPathsAudit([self::SRC, self::TESTS], array_values($ignored), $declared);
    }
}
