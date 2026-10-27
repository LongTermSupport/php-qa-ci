<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\Dto\RequiredHandlersEditDto;
use LTS\PHPQA\HooksDaemon\RequiredHandlersYamlEditor;
use LTS\PHPQA\HooksDaemon\YamlLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Every assertion compares whole files: the daemon config is hand-maintained and
 * commented, so a key that is enforced must be the only thing that moves.
 *
 * @internal
 */
#[CoversClass(RequiredHandlersYamlEditor::class)]
#[UsesClass(RequiredHandlersEditDto::class)]
#[UsesClass(YamlLines::class)]
#[Small]
final class RequiredHandlersYamlEditorTest extends TestCase
{
    /** The change line reported when the mode setting is added. */
    private const string MODE_ADDED = 'Added git_stash.options.mode: deny';

    /** Four-space layout, comments everywhere, every required handler already right. */
    /** The dotted name a refusal gives for the git_stash handler. */
    private const string GIT_STASH = 'handlers.pre_tool_use.git_stash';

    /** What a file holding only `version: 1` becomes. */
    private const string SECTION_AFTER_VERSION = "version: 1\nhandlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      options:\n        mode: deny\n"
        . "    plan_time_estimates:\n      enabled: true\n    validate_instruction_content:\n      enabled: true\n"
        . "    markdown_organization:\n      enabled: true\n";

    /** Four-space layout, comments everywhere, every required handler already right. */
    private const string COMPLIANT = <<<'YAML'
        # Project daemon config: hand maintained.
        version: "2.0"   # schema
        daemon:
            log_level: INFO

        handlers:
            pre_tool_use:
                # Stashes get forgotten.
                git_stash:
                    enabled: true   # keep on
                    options:
                        # deny, never warn
                        mode: deny   # owner decision
                    priority: 20
                plan_time_estimates:
                    enabled: yes
                validate_instruction_content:
                    enabled: True
                markdown_organization:
                    enabled: true
                # the sed guard
                sed_blocker:
                    enabled: true
            post_tool_use:
                lint_on_edit:
                    enabled: true

        YAML;

    #[Test]
    public function aCompliantFileIsReturnedByteForByteWithNoChanges(): void
    {
        $result = new RequiredHandlersYamlEditor()->apply(self::COMPLIANT);

        self::assertSame(self::COMPLIANT, $result->yaml);
        self::assertSame([], $result->changes);
        self::assertNull($result->unsupported);
    }

    #[Test]
    public function aWrongModeIsTheOnlyLineThatChangesAndItsCommentSurvives(): void
    {
        $input    = str_replace('mode: deny   # owner decision', 'mode: warn   # owner decision', self::COMPLIANT);
        $result   = new RequiredHandlersYamlEditor()->apply($input);

        self::assertSame(self::COMPLIANT, $result->yaml);
        self::assertSame(['Updated git_stash.options.mode: warn → deny'], $result->changes);
        self::assertNull($result->unsupported);
    }

    #[Test]
    public function aQuotedWrongModeIsReplacedAndAQuotedRightModeIsKept(): void
    {
        $quotedRight = str_replace('mode: deny', 'mode: "deny"', self::COMPLIANT);
        self::assertSame($quotedRight, new RequiredHandlersYamlEditor()->apply($quotedRight)->yaml);

        $quotedWrong = str_replace('mode: deny   # owner decision', "mode: 'warn'   # owner decision", self::COMPLIANT);
        self::assertSame(self::COMPLIANT, new RequiredHandlersYamlEditor()->apply($quotedWrong)->yaml);
    }

    #[Test]
    public function aMissingModeIsAddedBelowTheOptionsKeyAtTheFilesOwnIndent(): void
    {
        $input    = str_replace("                mode: deny   # owner decision\n", '', self::COMPLIANT);
        $expected = str_replace("                mode: deny   # owner decision\n", "                mode: deny\n", self::COMPLIANT);

        $result = new RequiredHandlersYamlEditor()->apply($input);

        self::assertSame($expected, $result->yaml);
        self::assertSame([self::MODE_ADDED], $result->changes);
    }

    #[Test]
    public function aMissingOptionsBlockIsAddedAfterTheLastLineOfTheHandlerAndBeforeTheNextComment(): void
    {
        $input = <<<'YAML'
            handlers:
              pre_tool_use:
                # Keep stashes out.
                git_stash:
                  enabled: true
                # Next handler's comment.
                plan_time_estimates:
                  enabled: true
                validate_instruction_content:
                  enabled: true
                markdown_organization:
                  enabled: true

            YAML;
        $expected = <<<'YAML'
            handlers:
              pre_tool_use:
                # Keep stashes out.
                git_stash:
                  enabled: true
                  options:
                    mode: deny
                # Next handler's comment.
                plan_time_estimates:
                  enabled: true
                validate_instruction_content:
                  enabled: true
                markdown_organization:
                  enabled: true

            YAML;

        $result = new RequiredHandlersYamlEditor()->apply($input);

        self::assertSame($expected, $result->yaml);
        self::assertSame([self::MODE_ADDED], $result->changes);
    }

    #[Test]
    public function aDisabledHandlerIsSwitchedOnInPlace(): void
    {
        $input = str_replace('markdown_organization:
            enabled: true', 'markdown_organization:
            enabled: false', self::COMPLIANT);
        self::assertNotSame(self::COMPLIANT, $input);

        $result = new RequiredHandlersYamlEditor()->apply($input);

        self::assertSame(self::COMPLIANT, $result->yaml);
        self::assertSame(['Updated markdown_organization.enabled: false → true'], $result->changes);
    }

    #[Test]
    public function aMissingEnabledFieldIsAddedAtTheHandlersOwnChildIndent(): void
    {
        $input = <<<'YAML'
            handlers:
                pre_tool_use:
                    git_stash:
                        options:
                            mode: deny
                    plan_time_estimates:
                        priority: 5
                    validate_instruction_content:
                        enabled: true
                    markdown_organization:
                        enabled: true
            YAML;

        $result = new RequiredHandlersYamlEditor()->apply($input);

        self::assertNull($result->unsupported);
        self::assertSame(
            <<<'YAML'
                handlers:
                    pre_tool_use:
                        git_stash:
                            options:
                                mode: deny
                            enabled: true
                        plan_time_estimates:
                            priority: 5
                            enabled: true
                        validate_instruction_content:
                            enabled: true
                        markdown_organization:
                            enabled: true
                YAML,
            $result->yaml,
        );
        self::assertSame(['Added git_stash.enabled: true', 'Added plan_time_estimates.enabled: true'], $result->changes);
    }

    #[Test]
    public function missingHandlersAreAppendedAfterTheLastOneKeepingEverythingElse(): void
    {
        $input = <<<'YAML'
            # header
            handlers:
              pre_tool_use:
                sed_blocker:
                  enabled: true   # keep
              post_tool_use:
                lint_on_edit:
                  enabled: true

            YAML;
        $expected = <<<'YAML'
            # header
            handlers:
              pre_tool_use:
                sed_blocker:
                  enabled: true   # keep
                git_stash:
                  enabled: true
                  options:
                    mode: deny
                plan_time_estimates:
                  enabled: true
                validate_instruction_content:
                  enabled: true
                markdown_organization:
                  enabled: true
              post_tool_use:
                lint_on_edit:
                  enabled: true

            YAML;

        $result = new RequiredHandlersYamlEditor()->apply($input);

        self::assertSame($expected, $result->yaml);
        self::assertCount(5, $result->changes);
        self::assertSame('Added git_stash.enabled: true', $result->changes[0]);
        self::assertSame(self::MODE_ADDED, $result->changes[1]);
    }

    #[Test]
    public function aFileWithoutAHandlersSectionGetsOneAtTheEnd(): void
    {
        $result = new RequiredHandlersYamlEditor()->apply("# only a comment\nversion: '2.0'\n");

        self::assertStringStartsWith("# only a comment\nversion: '2.0'\nhandlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      options:\n        mode: deny\n", $result->yaml);
        self::assertStringEndsWith("    markdown_organization:\n      enabled: true\n", $result->yaml);
        self::assertNull($result->unsupported);
    }

    #[Test]
    public function aFileWithNoFinalNewlineGainsOneWhenASectionIsAppended(): void
    {
        $result = new RequiredHandlersYamlEditor()->apply('version: 1');

        self::assertSame(self::SECTION_AFTER_VERSION, $result->yaml);
    }

    #[Test]
    public function aModeWithNoCommentIsReplacedWithoutOne(): void
    {
        $input  = "handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      options:\n        mode: warn\n";
        $result = new RequiredHandlersYamlEditor()->apply($input);

        self::assertStringContainsString("        mode: deny\n    plan_time_estimates:", $result->yaml);
    }

    #[Test]
    public function aSingleQuotedRightModeIsKept(): void
    {
        $yaml = str_replace('mode: deny', "mode: 'deny'", self::COMPLIANT);

        self::assertSame($yaml, new RequiredHandlersYamlEditor()->apply($yaml)->yaml);
    }

    #[Test]
    public function aLayoutThatNestsDeeperThanItsFirstStepIsFollowedNotNormalised(): void
    {
        $input = "handlers:\n  pre_tool_use:\n      git_stash:\n          enabled: true\n";
        $want  = "handlers:\n  pre_tool_use:\n      git_stash:\n          enabled: true\n          options:\n            mode: deny\n"
            . "      plan_time_estimates:\n        enabled: true\n"
            . "      validate_instruction_content:\n        enabled: true\n"
            . "      markdown_organization:\n        enabled: true\n";

        self::assertSame($want, new RequiredHandlersYamlEditor()->apply($input)->yaml);
    }

    #[Test]
    public function anEmptyFileGetsTheWholeSection(): void
    {
        $result = new RequiredHandlersYamlEditor()->apply('');

        self::assertStringStartsWith("handlers:\n  pre_tool_use:\n    git_stash:\n", $result->yaml);
        self::assertNull($result->unsupported);
    }

    #[Test]
    public function anEmptyFlowMappingIsOpenedAndItsCommentKept(): void
    {
        $result = new RequiredHandlersYamlEditor()->apply("handlers:\n  pre_tool_use: {}   # none yet\n");

        self::assertSame(
            "handlers:\n  pre_tool_use: # none yet\n    git_stash:\n      enabled: true\n      options:\n        mode: deny\n"
            . "    plan_time_estimates:\n      enabled: true\n    validate_instruction_content:\n      enabled: true\n"
            . "    markdown_organization:\n      enabled: true\n",
            $result->yaml,
        );
        self::assertNull($result->unsupported);
    }

    #[Test]
    public function aPreToolUseSectionHoldingAnEmptyHandlerLineIsFilledIn(): void
    {
        $input = "handlers:\n  pre_tool_use:\n    git_stash:\n    plan_time_estimates:\n      enabled: true\n    validate_instruction_content:\n      enabled: true\n    markdown_organization:\n      enabled: true\n";

        $result = new RequiredHandlersYamlEditor()->apply($input);

        self::assertNull($result->unsupported);
        self::assertStringContainsString("    git_stash:\n      enabled: true\n      options:\n        mode: deny\n    plan_time_estimates:\n", $result->yaml);
    }

    #[Test]
    #[DataProvider('unsupportedLayouts')]
    public function aLayoutThatCannotBeEditedInPlaceIsRefusedNamingWhatToSetByHand(string $yaml, string $path): void
    {
        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertSame($yaml, $result->yaml);
        self::assertSame([], $result->changes);
        self::assertSame($path, $result->unsupported);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupportedLayouts(): iterable
    {
        yield 'handlers as a populated flow mapping' => ["handlers: {pre_tool_use: {}}\n", 'handlers'];

        yield 'pre_tool_use as a populated flow mapping' => ["handlers:\n  pre_tool_use: {sed_blocker: {enabled: true}}\n", 'handlers.pre_tool_use'];

        yield 'a handler as a populated flow mapping' => ["handlers:\n  pre_tool_use:\n    git_stash: {enabled: true}\n", self::GIT_STASH];

        yield 'a handler written as a bare scalar' => ["handlers:\n  pre_tool_use:\n    git_stash: true\n", self::GIT_STASH];

        yield 'options as a populated flow mapping' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      options: {mode: warn}\n", self::GIT_STASH . '.options'];

        yield 'mode written as a block' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      options:\n        mode:\n          - deny\n", self::GIT_STASH . '.options.mode'];
    }

    #[Test]
    public function crlfLineEndingsAreRefusedUntouched(): void
    {
        $yaml   = "handlers:\r\n  pre_tool_use:\r\n    sed_blocker:\r\n      enabled: true\r\n";
        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertSame($yaml, $result->yaml);
        self::assertNotNull($result->unsupported);
        self::assertStringContainsString('CRLF', $result->unsupported);
    }

    #[Test]
    public function aKeyThatOnlySharesAPrefixWithARequiredOneIsNotMistakenForIt(): void
    {
        $input  = "handlers:\n  pre_tool_use:\n    git_stash_extra:\n      enabled: true\n";
        $result = new RequiredHandlersYamlEditor()->apply($input);

        self::assertStringContainsString("    git_stash_extra:\n      enabled: true\n    git_stash:\n", $result->yaml);
    }

    #[Test]
    public function aSecondPassOverItsOwnOutputChangesNothing(): void
    {
        $editor = new RequiredHandlersYamlEditor();
        $once   = $editor->apply("# c\nhandlers:\n    pre_tool_use:\n        git_stash:\n            options:\n                mode: warn\n");
        $twice  = $editor->apply($once->yaml);

        self::assertNotSame([], $once->changes);
        self::assertSame($once->yaml, $twice->yaml);
        self::assertSame([], $twice->changes);
    }
}
