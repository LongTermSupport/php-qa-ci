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
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\Regex\RegexPattern::class)]
#[Small]
final class ConfigTemplateIgnoreListCheckTest extends TestCase
{
    private const string CONFIG_DEFAULTS_GENERIC = '/configDefaults/generic';

    private const string GENERIC_PSR4_VALIDATE_IGNORE_LIST_TXT = '/configDefaults/generic/psr4-validate-ignore-list.txt';

    private const string VIOLATING_ASSETS = __DIR__ . '/../../assets/configTemplateIgnoreList/violating';

    private const string CLEAN_ASSETS      = __DIR__ . '/../../assets/configTemplateIgnoreList/clean';

    private const string INVALID_ASSETS = __DIR__ . '/../../assets/configTemplateIgnoreList/invalidPattern';

    public function testItPassesAndPrintsOkWhenEverythingIsCovered(): void
    {
        $check = new ConfigTemplateIgnoreListCheck(new ConfigTemplateIgnoreListAuditor(
            self::CLEAN_ASSETS . self::CONFIG_DEFAULTS_GENERIC,
            self::CLEAN_ASSETS . self::GENERIC_PSR4_VALIDATE_IGNORE_LIST_TXT,
        ));

        self::expectOutputString('Every namespace-less configDefaults/generic template is covered by psr4-validate-ignore-list.txt.' . \PHP_EOL);
        self::assertSame(0, $check->run());
    }

    /**
     * main() audits the package's own configDefaults/generic; a wrong path throws rather than passing.
     */
    public function testTheShippedTemplatesAreAllCovered(): void
    {
        self::expectOutputString('Every namespace-less configDefaults/generic template is covered by psr4-validate-ignore-list.txt.' . \PHP_EOL);
        self::assertSame(0, ConfigTemplateIgnoreListCheck::main());
    }

    /**
     * The whole report is compared, so a dropped line, separator or blank line fails here.
     */
    public function testItFailsAndPrintsTheUncoveredFileTheFixAndTheIdentifier(): void
    {
        $check = new ConfigTemplateIgnoreListCheck(new ConfigTemplateIgnoreListAuditor(
            self::VIOLATING_ASSETS . self::CONFIG_DEFAULTS_GENERIC,
            self::VIOLATING_ASSETS . self::GENERIC_PSR4_VALIDATE_IGNORE_LIST_TXT,
        ));

        self::expectOutputString(
            \PHP_EOL
            . 'ERROR — config templates the PSR-4 ignore list does not cover' . \PHP_EOL
            . '------------------------------------------------------------' . \PHP_EOL
            . '  uncovered_template.php -> would fail psr4Validate as qaConfig/uncovered_template.php' . \PHP_EOL
            . \PHP_EOL
            . 'Add a matching line to configDefaults/generic/psr4-validate-ignore-list.txt, in the shape of the existing entries.' . \PHP_EOL
            . '🪪  phpqaci.configTemplateIgnoreList  (vendor/bin/rule-doc phpqaci.configTemplateIgnoreList)' . \PHP_EOL,
        );
        self::assertSame(1, $check->run());
    }

    /**
     * A bad entry fails the lane with its identifier, as an uncovered template does, rather than
     * ending the run.
     */
    public function testAnIgnoreListEntryThatDoesNotCompileFailsNamingItAndTheIdentifier(): void
    {
        $ignoreList = self::INVALID_ASSETS . self::GENERIC_PSR4_VALIDATE_IGNORE_LIST_TXT;
        $check      = new ConfigTemplateIgnoreListCheck(new ConfigTemplateIgnoreListAuditor(
            self::INVALID_ASSETS . self::CONFIG_DEFAULTS_GENERIC,
            $ignoreList,
        ));

        self::expectOutputString(
            \PHP_EOL
            . 'ERROR — ' . $ignoreList . ' entry "#qaConfig/(unclosed#" is not a valid regular expression: preg_match(): Compilation failed: missing closing parenthesis at offset 18' . \PHP_EOL
            . '🪪  phpqaci.configTemplateIgnoreList  (vendor/bin/rule-doc phpqaci.configTemplateIgnoreList)' . \PHP_EOL,
        );
        self::assertSame(1, $check->run());
    }
}
