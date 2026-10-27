<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

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
 * @internal
 */
#[CoversClass(RequiredHandlersShape::class)]
#[UsesClass(RequiredHandlersYamlEditor::class)]
#[UsesClass(YamlLines::class)]
#[Small]
final class RequiredHandlersShapeTest extends TestCase
{
    /** The dotted name of the git_stash handler. */
    private const string GIT_STASH_PATH = 'handlers.pre_tool_use.git_stash';

    /** What a second document is called. */
    private const string MANY_DOCUMENTS = 'the file (more than one YAML document)';

    /** What an indicator on the enabled value is called, after the handler's name. */
    private const string INDICATOR_ON_ENABLED = '.enabled (an anchor, alias, tag, block scalar or flow value)';

    /** The switch every handler carries. */
    private const string ENABLED = 'enabled';

    /** A boolean spelled true. */
    private const string TRUE_WORD = 'true';

    /** A boolean spelled false. */
    private const string FALSE_WORD = 'false';

    /** The mode the daemon must be in. */
    private const string DENY = 'deny';

    /** A layout of plain block mappings with one required setting off. */
    private const string SUPPORTED = "version: 1\nhandlers:\n  pre_tool_use:\n    git_stash:\n      enabled: false # why\n      options:\n        mode: 'deny'\n";

    #[Test]
    public function aPlainBlockLayoutHasNoBlocker(): void
    {
        self::assertNull(new RequiredHandlersShape()->blocker(YamlLines::fromText(self::SUPPORTED)));
    }

    #[Test]
    public function anEmptyFileAndAFileWithoutTheSectionHaveNoBlocker(): void
    {
        $shape = new RequiredHandlersShape();

        self::assertNull($shape->blocker(YamlLines::fromText('')));
        self::assertNull($shape->blocker(YamlLines::fromText("version: 1\n# nothing else\n")));
    }

    #[Test]
    #[DataProvider('blockers')]
    public function aLayoutItCannotEditIsNamedExactly(string $yaml, string $named): void
    {
        self::assertSame($named, new RequiredHandlersShape()->blocker(YamlLines::fromText($yaml)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function blockers(): iterable
    {
        yield 'a tab' => ["handlers:\n\tpre_tool_use: {}\n", 'the file (tab indentation)'];

        yield 'a tab after the indent spaces' => ["handlers:\n  \tpre_tool_use: {}\n", 'the file (tab indentation)'];

        yield 'a second document' => ["a: 1\n---\nb: 2\n", self::MANY_DOCUMENTS];

        yield 'a document end' => ["a: 1\n...\n", self::MANY_DOCUMENTS];

        yield 'a document end before any content' => ["...\na: 1\n", self::MANY_DOCUMENTS];

        yield 'an indented top level' => ["  handlers:\n    pre_tool_use: {}\n", 'the top level (indented)'];

        yield 'a quoted top-level key' => ["\"handlers\":\n  pre_tool_use: {}\n", '"handlers" (not a plain key)'];

        yield 'a single-quoted key under handlers' => ["handlers:\n  'pre_tool_use':\n    x: 1\n", "handlers.'pre_tool_use' (not a plain key)"];

        yield 'a key with no colon' => ["handlers:\n  pre_tool_use\n", 'handlers.pre_tool_use (not a plain key)'];

        yield 'a merge key' => ["handlers:\n  <<: *base\n", 'handlers.<< (merge key)'];

        yield 'a top-level list' => ["- a\n- b\n", 'the top level (a list)'];

        yield 'a bare dash' => ["handlers:\n  -\n", 'handlers (a list)'];

        yield 'a list at the key indent' => ["handlers:\n  pre_tool_use:\n  - a\n", 'handlers (a list)'];

        yield 'a list below a key' => ["handlers:\n  pre_tool_use:\n    - a\n", 'handlers.pre_tool_use (a list)'];

        yield 'a dash inside a longer key is a key' => ["handlers:\n  pre_tool_use:\n    -x: 1\n", 'handlers.pre_tool_use.-x (not a plain key)'];

        yield 'a line shallower than its siblings' => ["handlers:\n    pre_tool_use: {}\n  other: 1\n", 'handlers (inconsistent indentation)'];

        yield 'a duplicate' => ["handlers:\n  pre_tool_use: {}\n  pre_tool_use: {}\n", 'handlers.pre_tool_use (duplicate key)'];

        yield 'a populated flow mapping' => ["handlers: {a: 1}\n", 'handlers'];

        yield 'an alias on a handler' => ["handlers:\n  pre_tool_use:\n    git_stash: *g\n", self::GIT_STASH_PATH];

        yield 'an anchor on the setting' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: &e true\n", self::GIT_STASH_PATH . self::INDICATOR_ON_ENABLED];

        yield 'a folded scalar on the setting' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: >\n        true\n", self::GIT_STASH_PATH . self::INDICATOR_ON_ENABLED];

        yield 'a flow list on the setting' => ["handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: [a]\n", self::GIT_STASH_PATH . self::INDICATOR_ON_ENABLED];
    }

    #[Test]
    public function aLeadingDocumentMarkerAndMarkersInsideABlockScalarAreNotASecondDocument(): void
    {
        $shape = new RequiredHandlersShape();

        self::assertNull($shape->blocker(YamlLines::fromText("--- # start\nhandlers: {}\n")));
        self::assertNull($shape->blocker(YamlLines::fromText("notes: |\n  ---\n  ...\nhandlers: {}\n")));
        self::assertNull($shape->blocker(YamlLines::fromText("# first\n---\nhandlers: {}\n")));
    }

    #[Test]
    public function aKeyBlockOffThePathMayHoldAnythingAndKeysSharingAPrefixAreDifferentKeys(): void
    {
        $yaml = "other:\n  - a\n  \"q\": 1\nhandlers:\n  pre_tool_use:\n    git_stash_extra:\n      enabled: [a]\n";

        self::assertNull(new RequiredHandlersShape()->blocker(YamlLines::fromText($yaml)));
    }

    #[Test]
    public function aValueIsReadFromTheLineOfItsKeyWithItsCommentLeftOut(): void
    {
        $lines = YamlLines::fromText(self::SUPPORTED);
        $shape = new RequiredHandlersShape();

        self::assertSame(self::FALSE_WORD, $shape->settingValue($lines, [...explode('.', self::GIT_STASH_PATH), self::ENABLED]));
        self::assertSame("'deny'", $shape->settingValue($lines, [...explode('.', self::GIT_STASH_PATH), 'options', 'mode']));
    }

    #[Test]
    public function aValueIsNullWhenTheKeyIsAbsentOrTheWayThereIsBlocked(): void
    {
        $shape = new RequiredHandlersShape();
        $path  = [...explode('.', self::GIT_STASH_PATH), self::ENABLED];

        self::assertNull($shape->settingValue(YamlLines::fromText("handlers:\n  pre_tool_use:\n    git_stash:\n      options: {}\n"), $path));
        self::assertNull($shape->settingValue(YamlLines::fromText("handlers: {}\n"), $path));
        self::assertNull($shape->settingValue(YamlLines::fromText("handlers:\n  pre_tool_use:\n    git_stash: *g\n"), $path));
        self::assertNull($shape->settingValue(YamlLines::fromText("handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: &e true\n"), $path));
    }

    #[Test]
    public function aSettingWithNoValueOnItsLineReadsAsEmpty(): void
    {
        $path = [...explode('.', self::GIT_STASH_PATH), self::ENABLED];

        self::assertSame('', new RequiredHandlersShape()->settingValue(YamlLines::fromText("handlers:\n  pre_tool_use:\n    git_stash:\n      enabled: # off\n"), $path));
    }

    #[Test]
    #[DataProvider('spellings')]
    public function aWrittenScalarHoldsTheWantedValueOnlyInTheSpellingsYamlReadsAsIt(string $written, string $wanted, bool $holds): void
    {
        self::assertSame($holds, new RequiredHandlersShape()->holds($written, $wanted));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function spellings(): iterable
    {
        yield self::TRUE_WORD => [self::TRUE_WORD, self::TRUE_WORD, true];

        yield 'True' => ['True', self::TRUE_WORD, true];

        yield 'yes' => ['yes', self::TRUE_WORD, true];

        yield 'ON' => ['ON', self::TRUE_WORD, true];

        yield self::FALSE_WORD => [self::FALSE_WORD, self::TRUE_WORD, false];

        yield 'a quoted true is a string' => ['"true"', self::TRUE_WORD, false];

        yield 'no' => ['no', self::TRUE_WORD, false];

        yield self::DENY => [self::DENY, self::DENY, true];

        yield 'double-quoted deny' => ['"deny"', self::DENY, true];

        yield 'single-quoted deny' => ["'deny'", self::DENY, true];

        yield 'warn' => ['warn', self::DENY, false];

        yield 'Deny' => ['Deny', self::DENY, false];

        yield 'unbalanced quote' => ['"deny', self::DENY, false];
    }

    #[Test]
    public function thePathOfARequiredSettingStartsAtHandlersAndPreToolUse(): void
    {
        self::assertSame(
            ['handlers', 'pre_tool_use', 'git_stash', 'options', 'mode'],
            RequiredHandlersShape::pathOf(RequiredHandlersYamlEditor::REQUIRED[1]),
        );
    }
}
