<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\Dto\RequiredHandlersEditDto;
use LTS\PHPQA\HooksDaemon\RequiredHandlersShape;
use LTS\PHPQA\HooksDaemon\RequiredHandlersYamlEditor;
use LTS\PHPQA\HooksDaemon\YamlLines;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Which config shapes the editor edits in place and which it refuses. A refused
 * file comes back byte for byte, because a wrong guess at a layout it cannot
 * edit would be read back differently by the YAML loader (a second `handlers:`
 * key replaces the first, a line at the wrong indent is invalid).
 *
 * @internal
 */
#[CoversClass(RequiredHandlersYamlEditor::class)]
#[UsesClass(RequiredHandlersEditDto::class)]
#[UsesClass(RequiredHandlersShape::class)]
#[UsesClass(YamlLines::class)]
#[Small]
final class RequiredHandlersYamlEditorShapesTest extends TestCase
{
    /** The name a refusal gives the top-level handlers key. */
    private const string HANDLERS = 'handlers';

    /** The name a refusal gives the event section. */
    private const string PRE_TOOL_USE = 'handlers.pre_tool_use';

    /** The name a refusal gives the git_stash handler. */
    private const string STASH = 'handlers.pre_tool_use.git_stash';

    /** The name a refusal gives the git_stash switch. */
    private const string STASH_ENABLED = 'handlers.pre_tool_use.git_stash.enabled';

    /** The word a refusal of several documents holds. */
    private const string DOCUMENT = 'document';

    /** The four other required handlers, already right, at the two-space layout. */
    private const string OTHERS = "    plan_time_estimates:\n      enabled: true\n    validate_instruction_content:\n      enabled: true\n"
        . "    markdown_organization:\n      enabled: true\n";

    #[Test]
    #[DataProvider('refusedShapes')]
    public function aShapeThatCannotBeEditedInPlaceIsLeftByteIdenticalAndNamesTheKey(string $yaml, string $named): void
    {
        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertSame($yaml, $result->yaml);
        self::assertSame([], $result->changes);
        self::assertNotNull($result->unsupported);
        self::assertStringContainsString($named, $result->unsupported);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedShapes(): iterable
    {
        yield 'a double-quoted handlers key' => ["\"handlers\":\n  pre_tool_use:\n    git_stash:\n      enabled: true\n", '"handlers"'];

        yield 'a single-quoted handlers key' => ["'handlers':\n  pre_tool_use:\n    git_stash:\n      enabled: true\n", "'handlers'"];

        yield 'a quoted pre_tool_use key' => ["handlers:\n  \"pre_tool_use\":\n    git_stash:\n      enabled: true\n", '"pre_tool_use"'];

        yield 'a quoted handler key' => ["handlers:\n  pre_tool_use:\n    \"git_stash\":\n      enabled: true\n", '"git_stash"'];

        yield 'a quoted enabled key' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      \"enabled\": true\n", '"enabled"'];

        yield 'a quoted options key' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      'options':\n        mode: deny\n", "'options'"];

        yield 'a quoted mode key' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      options:\n        \"mode\": deny\n", '"mode"'];

        yield 'a quoted key beside the ones enforced' => ["handlers:\n  pre_tool_use:\n    \"sed_blocker\":\n      enabled: true\n", '"sed_blocker"'];

        yield 'a fully quoted file' => ["\"handlers\":\n  \"pre_tool_use\":\n    \"git_stash\":\n      \"enabled\": true\n      \"options\":\n        \"mode\": warn\n", '"handlers"'];

        yield 'a complex key' => ["handlers:\n  ? pre_tool_use\n  : {}\n", self::HANDLERS];

        yield 'pre_tool_use as a block list' => ["handlers:\n  pre_tool_use:\n    - sed_blocker\n    - git_stash\n", self::PRE_TOOL_USE];

        yield "pre_tool_use as a list at the key's own indent" => ["handlers:\n  pre_tool_use:\n  - sed_blocker\n", self::HANDLERS];

        yield 'a handler as a block list' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      - enabled\n", self::STASH];

        yield 'handlers as a block list' => ["handlers:\n  - pre_tool_use\n", self::HANDLERS];

        yield 'pre_tool_use as a flow list' => ["handlers:\n  pre_tool_use: [sed_blocker]\n", self::PRE_TOOL_USE];

        yield 'enabled as a flow list' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: [true]\n", self::STASH_ENABLED];

        yield 'enabled as a flow mapping' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: {when: always}\n", self::STASH_ENABLED];

        yield 'tab indentation' => ["handlers:\n\tpre_tool_use:\n\t\tgit_stash:\n\t\t\tenabled: true\n", 'tab'];

        yield 'a tab before a line that is not on the path' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n\tother: 1\n", 'tab'];

        yield 'a duplicate handlers key' => ["handlers:\n  pre_tool_use:\n    sed_blocker:\n      enabled: true\nhandlers:\n  pre_tool_use: {}\n", self::HANDLERS];

        yield 'a duplicate pre_tool_use key' => ["handlers:\n  pre_tool_use:\n    sed_blocker:\n      enabled: true\n  pre_tool_use:\n    git_stash:\n      enabled: true\n", self::PRE_TOOL_USE];

        yield 'a duplicate handler key' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n    git_stash:\n      enabled: false\n", self::STASH];

        yield 'a duplicate enabled key' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      enabled: false\n", self::STASH_ENABLED];

        yield 'a duplicate mode key' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      options:\n        mode: deny\n        mode: warn\n", 'handlers.pre_tool_use.git_stash.options.mode'];

        yield 'an anchor on handlers' => ["handlers: &h\n  pre_tool_use: {}\n", self::HANDLERS];

        yield 'an alias as pre_tool_use' => ["base: &p\n  git_stash: {}\nhandlers:\n  pre_tool_use: *p\n", self::PRE_TOOL_USE];

        yield 'an anchor on a handler' => ["handlers:\n  pre_tool_use:\n    git_stash: &g\n      enabled: true\n", self::STASH];

        yield 'an alias as a handler' => ["handlers:\n  pre_tool_use:\n    git_stash: *g\n", self::STASH];

        yield 'an anchor on the enabled value' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: &e false\n", self::STASH_ENABLED];

        yield 'an alias as the mode value' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true\n      options:\n        mode: *m\n", 'handlers.pre_tool_use.git_stash.options.mode'];

        yield 'a tagged enabled value' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: !!bool false\n", self::STASH_ENABLED];

        yield 'a block scalar as the enabled value' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: |\n        false\n", self::STASH_ENABLED];

        yield 'a merge key under handlers' => ["base: &b\n  pre_tool_use: {}\nhandlers:\n  <<: *b\n", '<<'];

        yield 'a merge key under pre_tool_use' => ["handlers:\n  pre_tool_use:\n    <<: *common\n    sed_blocker:\n      enabled: true\n", '<<'];

        yield 'a merge key under a handler' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      <<: *common\n      enabled: true\n", '<<'];

        yield 'a merge key at the top level' => ["<<: *defaults\nhandlers:\n  pre_tool_use: {}\n", '<<'];

        yield 'a second document' => ["version: 1\n---\nhandlers:\n  pre_tool_use: {}\n", self::DOCUMENT];

        yield 'a document end marker' => ["handlers:\n  pre_tool_use: {}\n...\n", self::DOCUMENT];

        yield 'two documents after a leading marker' => ["---\nhandlers:\n  pre_tool_use: {}\n---\nother: 1\n", self::DOCUMENT];

        yield 'a line between a key and its children in depth' => ["handlers:\n    pre_tool_use:\n  git_stash:\n    enabled: true\n", self::HANDLERS];

        yield 'an indented top level' => ["  handlers:\n    pre_tool_use: {}\n", 'top level'];

        yield 'a scalar handlers' => ["handlers: ~\n", self::HANDLERS];
    }

    #[Test]
    public function aQuotedHandlersKeyDoesNotGetASecondHandlersBlockAppended(): void
    {
        $yaml = "\"handlers\":\n  \"pre_tool_use\":\n    \"git_stash\":\n      \"enabled\": true\n      \"options\":\n        \"mode\": warn\n";

        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertSame(0, substr_count($result->yaml, "\nhandlers:"));
        self::assertSame($yaml, $result->yaml);
    }

    #[Test]
    public function keysWithTrailingCommentsAreEditedInPlaceAndTheCommentsKept(): void
    {
        $yaml = "handlers: # the daemon's handlers\n  pre_tool_use: # before a tool runs\n    git_stash: # no stashes\n      enabled: false # why: owner\n"
            . "      options: # how\n        mode: deny # strict\n" . self::OTHERS;

        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertNull($result->unsupported);
        self::assertSame(['Updated git_stash.enabled: false → true'], $result->changes);
        self::assertSame(str_replace('enabled: false # why: owner', 'enabled: true # why: owner', $yaml), $result->yaml);
    }

    #[Test]
    public function aMissingSettingIsAddedBelowKeysThatCarryComments(): void
    {
        $yaml = "handlers: # all\n  pre_tool_use: # first\n    sed_blocker: # keep\n      enabled: true\n";

        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertNull($result->unsupported);
        self::assertStringStartsWith($yaml . "    git_stash:\n      enabled: true\n", $result->yaml);
        self::assertCount(5, $result->changes);
    }

    #[Test]
    public function quotedValuesAndQuotedKeysOffThePathDoNotStopAnEdit(): void
    {
        $yaml = "version: \"2.0\"\nname: 'x'\nhandlers:\n  pre_tool_use:\n    sed_blocker:\n      message: \"no sed\"\n      enabled: true\n"
            . "  post_tool_use:\n    lint_on_edit:\n      enabled: true\n";

        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertNull($result->unsupported);
        self::assertStringStartsWith("version: \"2.0\"\nname: 'x'\nhandlers:\n  pre_tool_use:\n    sed_blocker:\n      message: \"no sed\"\n      enabled: true\n    git_stash:\n", $result->yaml);
        self::assertStringEndsWith("  post_tool_use:\n    lint_on_edit:\n      enabled: true\n", $result->yaml);
    }

    #[Test]
    public function aLeadingDocumentMarkerIsKept(): void
    {
        $result = new RequiredHandlersYamlEditor()->apply("---\n# config\nhandlers:\n  pre_tool_use: {}\n");

        self::assertNull($result->unsupported);
        self::assertStringStartsWith("---\n# config\nhandlers:\n  pre_tool_use:\n    git_stash:\n", $result->yaml);
    }

    #[Test]
    public function aBlockScalarOffThePathMayMentionHandlers(): void
    {
        $yaml = "notes: |\n  handlers:\n    pre_tool_use: x\nhandlers:\n  pre_tool_use: {}\n";

        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertNull($result->unsupported);
        self::assertStringStartsWith("notes: |\n  handlers:\n    pre_tool_use: x\nhandlers:\n  pre_tool_use:\n    git_stash:\n", $result->yaml);
    }

    #[Test]
    public function aKeyThatIsNotOnThePathMayBeNestedAnyWay(): void
    {
        $yaml = "daemon: {log: INFO}\nlist:\n  - a\n  - b\nanchored: &a\n  x: 1\nuse: *a\nhandlers:\n  pre_tool_use: {}\n";

        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertNull($result->unsupported);
        self::assertStringStartsWith("daemon: {log: INFO}\nlist:\n  - a\n  - b\nanchored: &a\n  x: 1\nuse: *a\nhandlers:\n  pre_tool_use:\n    git_stash:\n", $result->yaml);
    }

    #[Test]
    public function aLineEndingOnOneLineAloneIsRefusedAsCrlf(): void
    {
        $yaml = "handlers:\n  pre_tool_use: {}\r\n";

        $result = new RequiredHandlersYamlEditor()->apply($yaml);

        self::assertSame($yaml, $result->yaml);
        self::assertNotNull($result->unsupported);
        self::assertStringContainsString('CRLF', $result->unsupported);
    }
}
