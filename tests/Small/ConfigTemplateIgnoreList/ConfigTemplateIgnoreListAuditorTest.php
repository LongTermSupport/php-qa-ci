<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ConfigTemplateIgnoreList;

use LTS\PHPQA\ConfigTemplateIgnoreList\ConfigTemplateIgnoreListAuditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * A namespace-less template under configDefaults/generic/ that the shipped
 * PSR-4 ignore list does not cover breaks the documented override procedure
 * the moment a consumer copies it. The auditor reads both as text; these
 * tests prove it fires on fixtures and stays quiet on the shipped files.
 *
 * @internal
 */
#[CoversClass(ConfigTemplateIgnoreListAuditor::class)]
#[Small]
final class ConfigTemplateIgnoreListAuditorTest extends TestCase
{
    private const string VIOLATING_ASSETS = __DIR__ . '/../../assets/configTemplateIgnoreList/violating';

    private const string CLEAN_ASSETS      = __DIR__ . '/../../assets/configTemplateIgnoreList/clean';

    private const string GENERIC_DIR = '/configDefaults/generic';

    private const string IGNORE_LIST = self::GENERIC_DIR . '/psr4-validate-ignore-list.txt';

    public function testItReportsANamespaceLessTemplateMissingFromTheIgnoreList(): void
    {
        $auditor = new ConfigTemplateIgnoreListAuditor(
            self::VIOLATING_ASSETS . self::GENERIC_DIR,
            self::VIOLATING_ASSETS . self::IGNORE_LIST,
        );

        $actual = $auditor->main();

        self::assertSame(
            ['uncovered_template.php' => 'qaConfig/uncovered_template.php'],
            $actual,
        );
    }

    public function testItIsQuietWhenEveryNamespaceLessTemplateIsCovered(): void
    {
        $auditor = new ConfigTemplateIgnoreListAuditor(
            self::CLEAN_ASSETS . self::GENERIC_DIR,
            self::CLEAN_ASSETS . self::IGNORE_LIST,
        );

        self::assertSame([], $auditor->main());
    }

    public function testItDoesNotFlagACoveredTemplateAlongsideAnUncoveredOne(): void
    {
        // Regression against a rule too broad to trust: it must name ONLY the
        // uncovered file, never the one the ignore list already excludes, and
        // it must never flag the namespaced class file.
        $auditor = new ConfigTemplateIgnoreListAuditor(
            self::VIOLATING_ASSETS . self::GENERIC_DIR,
            self::VIOLATING_ASSETS . self::IGNORE_LIST,
        );

        $actual = $auditor->main();

        self::assertArrayNotHasKey('covered_template.php', $actual);
        self::assertArrayNotHasKey('good_with_namespace.php', $actual);
    }

    /**
     * The audited copy destination is the directory the documentation tells a
     * consumer to use, so the two may not drift apart silently.
     */
    public function testTheOverrideDirectoryIsTheOneTheDocumentationNames(): void
    {
        $docs = \Safe\file_get_contents(__DIR__ . '/../../../docs/configuration.md');

        self::assertStringContainsString(
            'root `' . ConfigTemplateIgnoreListAuditor::OVERRIDE_DIR . '/',
            $docs,
            'docs/configuration.md no longer names the override directory the auditor assumes',
        );
    }

    /**
     * Real-repo regression: proves the Detector against php-qa-ci's OWN
     * shipped configDefaults/generic — this is the exact defect from the DBF
     * exec-test DEFECT-REPORT.md, reproduced through the Detector rather than
     * by hand.
     */
    public function testShippedConfigDefaultsAreFullyCoveredByTheShippedIgnoreList(): void
    {
        $repoRoot = __DIR__ . '/../../..';

        $auditor = new ConfigTemplateIgnoreListAuditor($repoRoot . self::GENERIC_DIR, $repoRoot . self::IGNORE_LIST);

        self::assertSame([], $auditor->main());
    }
}
