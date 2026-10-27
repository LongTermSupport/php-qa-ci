<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\Dto\RequiredHandlersEditDto;
use LTS\PHPQA\HooksDaemon\RequiredHandlersEditInvariant;
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
 * The safety check on an edit the editor has already computed: it reads the
 * result back and compares it with the original, and holds only when every
 * required setting is right, the layout is still one the editor reads, and
 * nothing but the lines of the enforced keys moved.
 *
 * @internal
 */
#[CoversClass(RequiredHandlersEditInvariant::class)]
#[UsesClass(RequiredHandlersEditDto::class)]
#[UsesClass(RequiredHandlersShape::class)]
#[UsesClass(RequiredHandlersYamlEditor::class)]
#[UsesClass(YamlLines::class)]
#[Small]
final class RequiredHandlersEditInvariantTest extends TestCase
{
    /** The mode line the daemon config must end up with. */
    private const string MODE_DENY = 'mode: deny';

    /** A mode line that must be replaced. */
    private const string MODE_WARN = 'mode: warn';

    /** A reported change that adds a setting. */
    private const string ADDED_X = 'Added x';

    /** A reported change that updates a setting. */
    private const string UPDATED_X = 'Updated x';

    /** Every required setting right, with comments. */
    private const string RIGHT = "# top\nhandlers:\n  pre_tool_use:\n    git_stash:\n      enabled: true # keep\n      options:\n        mode: deny\n"
        . "    plan_time_estimates:\n      enabled: true\n    validate_instruction_content:\n      enabled: true\n"
        . "    markdown_organization:\n      enabled: true\n";

    /** What the editor makes of a file with no handlers section. */
    private const string ADDED = "version: 1\n";

    #[Test]
    public function anUnchangedFileHolds(): void
    {
        self::assertNull(new RequiredHandlersEditInvariant()->violation(self::RIGHT, new RequiredHandlersEditDto(self::RIGHT, [], null)));
    }

    #[Test]
    public function aTextThatChangedWithNoChangeReportedIsAViolation(): void
    {
        $violation = new RequiredHandlersEditInvariant()->violation(self::RIGHT, new RequiredHandlersEditDto(self::RIGHT . "extra: 1\n", [], null));

        self::assertSame('the text changed although no setting was reported changed', $violation);
    }

    #[Test]
    #[DataProvider('inputsTheEditorEdits')]
    public function everyEditTheEditorMakesHolds(string $input): void
    {
        $edit = new RequiredHandlersYamlEditor()->apply($input);

        self::assertNotSame([], $edit->changes, 'the fixture must need an edit');
        self::assertNull(new RequiredHandlersEditInvariant()->violation($input, $edit));
    }

    /** @return iterable<string, array{string}> */
    public static function inputsTheEditorEdits(): iterable
    {
        yield 'no section, no final newline' => ['version: 1'];

        yield 'no section' => [self::ADDED];

        yield 'an empty file' => [''];

        yield 'a wrong mode with a comment' => [str_replace(self::MODE_DENY, 'mode: warn # owner', self::RIGHT)];

        yield 'a disabled handler' => [str_replace("enabled: true\n    validate", "enabled: false\n    validate", self::RIGHT)];

        yield 'an opened empty flow mapping' => ["handlers:\n  pre_tool_use: {} # none yet\n"];

        yield 'comments between handlers' => ["handlers:\n  pre_tool_use:\n    # c1\n    git_stash:\n      enabled: true\n    # c2\n    sed_blocker:\n      enabled: true\n"];

        yield 'identical lines elsewhere in the file' => ["a:\n  enabled: true\nhandlers:\n  pre_tool_use:\n    other:\n      enabled: true\n"];
    }

    #[Test]
    public function anEditThatLeavesTheLayoutUnreadableIsAViolation(): void
    {
        $original = "\"handlers\":\n  pre_tool_use: {}\n";
        $appended = $original . str_replace('# top', '', self::RIGHT);

        $violation = new RequiredHandlersEditInvariant()->violation($original, new RequiredHandlersEditDto($appended, [self::ADDED_X], null));

        self::assertSame('the edited file is not a layout the editor reads: "handlers" (not a plain key)', $violation);
    }

    #[Test]
    public function anEditThatLeavesASettingWrongIsAViolation(): void
    {
        $original = str_replace(self::MODE_DENY, self::MODE_WARN, self::RIGHT);
        $edit     = new RequiredHandlersEditDto(str_replace(self::MODE_DENY, 'mode: allow', self::RIGHT), ['Updated git_stash.options.mode: warn → deny'], null);

        self::assertSame(
            'git_stash.options.mode is not deny in the edited file',
            new RequiredHandlersEditInvariant()->violation($original, $edit),
        );
    }

    #[Test]
    public function anEditThatLeavesASettingOutIsAViolation(): void
    {
        $original = "handlers:\n  pre_tool_use: {}\n";
        $edit     = new RequiredHandlersEditDto("handlers:\n  pre_tool_use:\n    git_stash:\n      options:\n        mode: deny\n", [self::ADDED_X], null);

        self::assertSame(
            'git_stash.enabled is not true in the edited file',
            new RequiredHandlersEditInvariant()->violation($original, $edit),
        );
    }

    #[Test]
    public function anEditThatDroppedACommentIsAViolation(): void
    {
        $original = str_replace(self::MODE_DENY, self::MODE_WARN, self::RIGHT);
        $edited   = str_replace(["# top\n", self::MODE_WARN], ['', self::MODE_DENY], $original);

        self::assertSame(
            'the line "# top" was removed',
            new RequiredHandlersEditInvariant()->violation($original, new RequiredHandlersEditDto($edited, [self::UPDATED_X], null)),
        );
    }

    #[Test]
    public function anEditThatChangedAnUnrelatedLineIsAViolation(): void
    {
        $original = "other: 1\n" . str_replace(self::MODE_DENY, self::MODE_WARN, self::RIGHT);
        $edited   = str_replace(['other: 1', self::MODE_WARN], ['other: 2', self::MODE_DENY], $original);

        self::assertSame(
            'the line "other: 1" was removed',
            new RequiredHandlersEditInvariant()->violation($original, new RequiredHandlersEditDto($edited, [self::UPDATED_X], null)),
        );
    }

    #[Test]
    public function anEditThatAddedAnUnrelatedLineIsAViolation(): void
    {
        $original = str_replace(self::MODE_DENY, self::MODE_WARN, self::RIGHT);
        $edited   = str_replace(self::MODE_WARN, "mode: deny\n        extra: 1", $original);

        self::assertSame(
            'the line "        extra: 1" was added',
            new RequiredHandlersEditInvariant()->violation($original, new RequiredHandlersEditDto($edited, [self::UPDATED_X], null)),
        );
    }

    #[Test]
    public function anEditThatRemovedMoreLinesThanItReportedChangingIsAViolation(): void
    {
        $original = str_replace(['enabled: true # keep', self::MODE_DENY], ['enabled: false # keep', self::MODE_WARN], self::RIGHT);

        self::assertSame(
            '2 lines were removed for 1 reported changes',
            new RequiredHandlersEditInvariant()->violation($original, new RequiredHandlersEditDto(self::RIGHT, ['Updated one'], null)),
        );
    }

    #[Test]
    public function aRemovedLineWithNoReplacementForThatKeyIsAViolation(): void
    {
        $original = "handlers:\n  pre_tool_use:\n    sed_blocker:\n      mode: warn\n";
        $edited   = "handlers:\n  pre_tool_use:\n    sed_blocker:\n" . substr(self::RIGHT, \strlen("# top\nhandlers:\n  pre_tool_use:\n"));

        self::assertSame(
            'the line "      mode: warn" was removed',
            new RequiredHandlersEditInvariant()->violation($original, new RequiredHandlersEditDto($edited, [self::ADDED_X], null)),
        );
    }

    #[Test]
    public function aDiffTooLargeToCheckIsAViolation(): void
    {
        $before = '';
        $after  = '';
        for ($line = 0; $line < 2100; ++$line) {
            $before .= 'a' . $line . ": 1\n";
            $after  .= 'b' . $line . ": 1\n";
        }

        $violation = new RequiredHandlersEditInvariant()->violation($before, new RequiredHandlersEditDto(self::RIGHT . $after, [self::ADDED_X], null));

        self::assertSame('the edit is too large to check line by line', $violation);
    }
}
