<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\Dto\YamlEditResultDto;
use LTS\PHPQA\HooksDaemon\FullQaBlockerBlockRenderer;
use LTS\PHPQA\HooksDaemon\FullQaBlockerYamlEditor;
use LTS\PHPQA\HooksDaemon\YamlEditOutcomeEnum;
use LTS\PHPQA\HooksDaemon\YamlLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The daemon config is a hand-maintained, heavily commented file, so the edit
 * is a text edit: every assertion below compares whole files, which proves that
 * nothing outside the handler's own lines moved.
 *
 * @internal
 */
#[CoversClass(FullQaBlockerYamlEditor::class)]
#[CoversClass(YamlLines::class)]
#[UsesClass(FullQaBlockerBlockRenderer::class)]
#[UsesClass(YamlEditResultDto::class)]
#[Small]
final class FullQaBlockerYamlEditorTest extends TestCase
{
    private const string BIN = 'vendor/bin';

    private const string TYPICAL = <<<'YAML'
        version: '2.0'
        # Project daemon config.
        daemon:
          log_level: INFO
        handlers:
          pre_tool_use:
            destructive_git:
              enabled: true
              priority: 10
            # Keep the sed guard on.
            sed_blocker:
              enabled: true
              options:
                # a nested comment
                mode: deny
          # Post-tool handlers below.
          post_tool_use:
            lint_on_edit:
              enabled: true

        YAML;

    #[Test]
    public function theHandlerIsAppendedToPreToolUseAndNothingElseMoves(): void
    {
        $expected = <<<'YAML'
            version: '2.0'
            # Project daemon config.
            daemon:
              log_level: INFO
            handlers:
              pre_tool_use:
                destructive_git:
                  enabled: true
                  priority: 10
                # Keep the sed guard on.
                sed_blocker:
                  enabled: true
                  options:
                    # a nested comment
                    mode: deny

            YAML
            . $this->block(4) . <<<'YAML'

                  # Post-tool handlers below.
                  post_tool_use:
                    lint_on_edit:
                      enabled: true

                YAML;

        $result = $this->apply(self::TYPICAL);

        self::assertSame(YamlEditOutcomeEnum::Inserted, $result->outcome);
        self::assertSame($expected, $result->yaml);
    }

    #[Test]
    public function aSecondRunIsANoOp(): void
    {
        $first  = $this->apply(self::TYPICAL);
        $second = $this->apply($first->yaml);

        self::assertSame(YamlEditOutcomeEnum::Unchanged, $second->outcome);
        self::assertSame($first->yaml, $second->yaml);
    }

    #[Test]
    public function aStaleManagedBlockIsRewrittenInPlace(): void
    {
        $stale = <<<'YAML'
            handlers:
              pre_tool_use:
                subagent_full_qa_blocker:
                  # Managed by php-qa-ci: an older rendering.
                  enabled: true
                  options:
                    full_qa_patterns:
                      - id: old
                        command: qa

                    targeted_qa_commands: []
                # The next handler keeps its comment.
                sed_blocker:
                  enabled: true

            YAML;
        $expected = $this->section() . <<<'YAML'

                # The next handler keeps its comment.
                sed_blocker:
                  enabled: true

            YAML;

        $result = $this->apply($stale);

        self::assertSame(YamlEditOutcomeEnum::Updated, $result->outcome);
        self::assertSame($expected, $result->yaml);
    }

    #[Test]
    public function aManagedBlockTheProjectDisabledIsLeftAlone(): void
    {
        $disabled = str_replace('enabled: true', 'enabled: false', $this->apply(self::TYPICAL)->yaml);

        $result = $this->apply($disabled);

        self::assertSame(YamlEditOutcomeEnum::DisabledByProject, $result->outcome);
        self::assertSame($disabled, $result->yaml);
    }

    #[Test]
    public function aHandWrittenBlockIsTheProjectsOwn(): void
    {
        $own = <<<'YAML'
            handlers:
              pre_tool_use:
                subagent_full_qa_blocker:
                  enabled: true
                  options:
                    full_qa_patterns:
                      - id: mine
                        command: qa

            YAML;

        $result = $this->apply($own);

        self::assertSame(YamlEditOutcomeEnum::ProjectOwned, $result->outcome);
        self::assertSame($own, $result->yaml);
    }

    #[Test]
    public function aHandWrittenDisabledBlockIsReportedAsDisabled(): void
    {
        $own = "handlers:\n  pre_tool_use:\n    subagent_full_qa_blocker:\n      enabled: no  # not here\n";

        self::assertSame(YamlEditOutcomeEnum::DisabledByProject, $this->apply($own)->outcome);
    }

    #[Test]
    public function aNestedEnabledFalseDoesNotDisableTheHandler(): void
    {
        $own = <<<'YAML'
            handlers:
              pre_tool_use:
                subagent_full_qa_blocker:
                  enabled: true
                  options:
                    enabled: false

            YAML;

        self::assertSame(YamlEditOutcomeEnum::ProjectOwned, $this->apply($own)->outcome);
    }

    #[Test]
    public function anInlineDisabledHandlerIsLeftAlone(): void
    {
        $inline = "handlers:\n  pre_tool_use:\n    subagent_full_qa_blocker: {enabled: false}\n";

        $result = $this->apply($inline);

        self::assertSame(YamlEditOutcomeEnum::DisabledByProject, $result->outcome);
        self::assertSame($inline, $result->yaml);
    }

    #[Test]
    public function anyOtherInlineHandlerIsTheProjectsOwn(): void
    {
        $inline = "handlers:\n  pre_tool_use:\n    subagent_full_qa_blocker: {enabled: true}  # mine\n";

        self::assertSame(YamlEditOutcomeEnum::ProjectOwned, $this->apply($inline)->outcome);
    }

    #[Test]
    public function aMissingPreToolUseIsCreatedFirstUnderHandlers(): void
    {
        $yaml = <<<'YAML'
            handlers:
              # Stop handlers.
              stop:
                auto_continue_stop:
                  enabled: true

            YAML;
        $expected = $this->section() . "\n  # Stop handlers.\n  stop:\n    auto_continue_stop:\n      enabled: true\n";

        $result = $this->apply($yaml);

        self::assertSame(YamlEditOutcomeEnum::Inserted, $result->outcome);
        self::assertSame($expected, $result->yaml);
    }

    #[Test]
    public function aMissingHandlersSectionIsAppended(): void
    {
        $yaml = "version: '2.0'\ndaemon:\n  log_level: INFO\n";

        $result = $this->apply($yaml);

        self::assertSame(YamlEditOutcomeEnum::Inserted, $result->outcome);
        self::assertSame($yaml . $this->section() . "\n", $result->yaml);
    }

    #[Test]
    public function aFileWithoutAFinalNewlineGainsOne(): void
    {
        $yaml = "daemon:\n  log_level: INFO";

        $result = $this->apply($yaml);

        self::assertSame($yaml . "\n" . $this->section() . "\n", $result->yaml);
    }

    #[Test]
    public function anEmptyFileGainsTheWholeSection(): void
    {
        $result = $this->apply('');

        self::assertSame(YamlEditOutcomeEnum::Inserted, $result->outcome);
        self::assertSame($this->section() . "\n", $result->yaml);
    }

    #[Test]
    public function anEmptyFlowHandlersMappingIsOpenedUp(): void
    {
        $result = $this->apply("version: '2.0'\nhandlers: {}\n");

        self::assertSame(YamlEditOutcomeEnum::Inserted, $result->outcome);
        self::assertSame("version: '2.0'\n" . $this->section() . "\n", $result->yaml);
    }

    #[Test]
    public function anEmptyFlowPreToolUseMappingIsOpenedUp(): void
    {
        $result = $this->apply("handlers:\n  pre_tool_use: {}  # none yet\n  stop: {}\n");

        self::assertSame(YamlEditOutcomeEnum::Inserted, $result->outcome);
        self::assertSame("handlers:\n  pre_tool_use: # none yet\n" . $this->block(4) . "\n  stop: {}\n", $result->yaml);
    }

    #[Test]
    public function aPreToolUseWithOnlyCommentsGainsTheHandlerAfterThem(): void
    {
        $yaml     = "handlers:\n  pre_tool_use:\n    # nothing configured yet\n";
        $expected = "handlers:\n  pre_tool_use:\n    # nothing configured yet\n" . $this->block(4) . "\n";

        self::assertSame($expected, $this->apply($yaml)->yaml);
    }

    #[Test]
    public function aNonEmptyFlowMappingIsNotEdited(): void
    {
        foreach (["handlers: {pre_tool_use: {}}\n", "handlers:\n  pre_tool_use: {sed_blocker: {enabled: true}}\n"] as $yaml) {
            $result = $this->apply($yaml);

            self::assertSame(YamlEditOutcomeEnum::Unsupported, $result->outcome, $yaml);
            self::assertSame($yaml, $result->yaml);
        }
    }

    #[Test]
    public function aWindowsLineEndedFileIsNotEdited(): void
    {
        $yaml = "handlers:\r\n  pre_tool_use:\r\n    sed_blocker:\r\n      enabled: true\r\n";

        $result = $this->apply($yaml);

        self::assertSame(YamlEditOutcomeEnum::Unsupported, $result->outcome);
        self::assertSame($yaml, $result->yaml);
    }

    #[Test]
    public function theFilesOwnIndentUnitIsFollowed(): void
    {
        $yaml = "daemon:\n    log_level: INFO\nhandlers:\n    pre_tool_use:\n        sed_blocker:\n            enabled: true\n";

        $result = $this->apply($yaml);

        $expected = $yaml . implode("\n", new FullQaBlockerBlockRenderer()->render(8, 4, self::BIN)) . "\n";
        self::assertSame($expected, $result->yaml);
        self::assertSame(YamlEditOutcomeEnum::Unchanged, $this->apply($result->yaml)->outcome);
    }

    #[Test]
    public function aNestedHandlersKeyIsNotMistakenForTheTopLevelOne(): void
    {
        $yaml = "pseudo_events:\n  nitpick:\n    handlers:\n      hedging_language:\n        enabled: true\n";

        $result = $this->apply($yaml);

        self::assertSame($yaml . $this->section() . "\n", $result->yaml);
    }

    #[Test]
    public function aKeyThatMerelyStartsWithTheNameIsNotIt(): void
    {
        $yaml = "handlers_extra:\n  x: 1\nhandlers:\n  pre_tool_use_more:\n    x: 1\n";

        $result = $this->apply($yaml);

        self::assertSame(
            "handlers_extra:\n  x: 1\n" . $this->section() . "\n  pre_tool_use_more:\n    x: 1\n",
            $result->yaml,
        );
    }

    #[Test]
    public function aTabMaySeparateAKeyFromItsValue(): void
    {
        $result = $this->apply("handlers:\t{}\n");

        self::assertSame(YamlEditOutcomeEnum::Inserted, $result->outcome);
        self::assertSame($this->section() . "\n", $result->yaml);
    }

    #[Test]
    public function aKeyGluedToMoreTextIsADifferentKey(): void
    {
        $yaml = "handlers:x: 1\n";

        self::assertSame($yaml . $this->section() . "\n", $this->apply($yaml)->yaml);
    }

    #[Test]
    public function theLastHandlersTrailingDeeperCommentStaysWithIt(): void
    {
        $yaml = "handlers:\n  pre_tool_use:\n    sed_blocker:\n      enabled: true\n      # option: off\n\n  # next section\n  stop: {}\n";

        $expected = "handlers:\n  pre_tool_use:\n    sed_blocker:\n      enabled: true\n      # option: off\n"
            . $this->block(4) . "\n\n  # next section\n  stop: {}\n";

        self::assertSame($expected, $this->apply($yaml)->yaml);
    }

    private function apply(string $yaml): YamlEditResultDto
    {
        return new FullQaBlockerYamlEditor()->apply($yaml, self::BIN);
    }

    private function block(int $indent): string
    {
        return implode("\n", new FullQaBlockerBlockRenderer()->render($indent, 2, self::BIN));
    }

    /** A fresh `handlers.pre_tool_use` holding only the block, at the default indent. */
    private function section(): string
    {
        return "handlers:\n  pre_tool_use:\n" . $this->block(4);
    }
}
