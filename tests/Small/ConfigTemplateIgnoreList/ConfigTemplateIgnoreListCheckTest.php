<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ConfigTemplateIgnoreList;

use LTS\PHPQA\ConfigTemplateIgnoreList\ConfigTemplateIgnoreListAuditor;
use LTS\PHPQA\ConfigTemplateIgnoreList\ConfigTemplateIgnoreListCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ConfigTemplateIgnoreListCheck::class)]
#[CoversClass(ConfigTemplateIgnoreListAuditor::class)]
#[Small]
final class ConfigTemplateIgnoreListCheckTest extends TestCase
{
    private const string VIOLATING_ASSETS = __DIR__ . '/../../assets/configTemplateIgnoreList/violating';

    private const string CLEAN_ASSETS      = __DIR__ . '/../../assets/configTemplateIgnoreList/clean';

    public function testItPassesAndPrintsOkWhenEverythingIsCovered(): void
    {
        $check = new ConfigTemplateIgnoreListCheck(new ConfigTemplateIgnoreListAuditor(
            self::CLEAN_ASSETS . '/configDefaults/generic',
            self::CLEAN_ASSETS . '/configDefaults/generic/psr4-validate-ignore-list.txt',
        ));

        self::expectOutputString('Every namespace-less configDefaults/generic template is covered by psr4-validate-ignore-list.txt.' . \PHP_EOL);
        self::assertSame(0, $check->run());
    }

    public function testItFailsAndNamesTheUncoveredFileAndItsWouldBeFailurePath(): void
    {
        $check = new ConfigTemplateIgnoreListCheck(new ConfigTemplateIgnoreListAuditor(
            self::VIOLATING_ASSETS . '/configDefaults/generic',
            self::VIOLATING_ASSETS . '/configDefaults/generic/psr4-validate-ignore-list.txt',
        ));

        self::expectOutputRegex('/uncovered_template\.php.*qaConfig\/uncovered_template\.php/s');
        self::assertSame(1, $check->run());
    }

    public function testFailureMessageCarriesTheIdentifierAndTheRuleDocCommand(): void
    {
        $check = new ConfigTemplateIgnoreListCheck(new ConfigTemplateIgnoreListAuditor(
            self::VIOLATING_ASSETS . '/configDefaults/generic',
            self::VIOLATING_ASSETS . '/configDefaults/generic/psr4-validate-ignore-list.txt',
        ));

        self::expectOutputRegex('/phpqaci\.configTemplateIgnoreList.*rule-doc phpqaci\.configTemplateIgnoreList/s');
        $check->run();
    }
}
