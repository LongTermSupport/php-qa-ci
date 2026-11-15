<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use Iterator;
use LTS\PHPQA\VersionPins\WorkflowYamlText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WorkflowYamlText::class)]
#[Small]
final class WorkflowYamlTextTest extends TestCase
{
    #[Test]
    #[DataProvider('commentedLines')]
    public function aCommentIsRemovedAndNothingElse(string $line, string $expected): void
    {
        self::assertSame($expected, new WorkflowYamlText()->withoutComments($line));
    }

    /** @return Iterator<string, array{string, string}> */
    public static function commentedLines(): Iterator
    {
        yield 'a whole-line comment' => ["# note\n", "\n"];

        yield 'an indented comment' => ['    # note', ''];

        yield 'a trailing comment' => ['on: push # note', 'on: push'];

        yield 'a tab before a hash' => ["run: echo a\t# note", 'run: echo a'];

        yield 'a hash in a word' => ['run: echo a#b', 'run: echo a#b'];

        yield 'a hash in a url' => ['run: curl http://x/#frag', 'run: curl http://x/#frag'];

        yield 'a hash in double quotes' => ['run: echo "a # b" # note', 'run: echo "a # b"'];

        yield 'a hash in single quotes' => ["run: echo 'a # b' # note", "run: echo 'a # b'"];

        yield 'an escaped quote before a hash' => ['run: echo "a \" # b" # note', 'run: echo "a \" # b"'];

        yield 'a doubled single quote before a hash' => ["run: echo 'it''s # b' # note", "run: echo 'it''s # b'"];

        yield 'an apostrophe in a plain scalar' => ["run: echo it's # note", "run: echo it's"];

        yield 'trailing spaces' => ["on: push   \n", "on: push\n"];

        yield 'windows line endings' => ["on: push\r\njobs:\r\n", "on: push\njobs:\n"];

        yield 'a comment in a flow list' => ["on: [push, # first\n  pull_request]", "on: [push,\n  pull_request]"];
    }

    #[Test]
    #[DataProvider('balancedTexts')]
    public function aFlowValueIsReadToItsMatchingCloser(string $text, ?string $expected): void
    {
        self::assertSame($expected, new WorkflowYamlText()->balanced($text));
    }

    /** @return Iterator<string, array{string, ?string}> */
    public static function balancedTexts(): Iterator
    {
        yield 'a list' => ["[a, b]\njobs:", '[a, b]'];

        yield 'a nested list' => ['[a, [b, c], d] trailing', '[a, [b, c], d]'];

        yield 'a mapping' => ['{a: {b: 1}, c: 2}', '{a: {b: 1}, c: 2}'];

        yield 'a closer in quotes' => ["['a]', b]", "['a]', b]"];

        yield 'over several lines' => ["[\n  a,\n  b\n]\nnext: 1", "[\n  a,\n  b\n]"];

        yield 'never closed' => ['[a, b', null];

        yield 'not a flow value' => ['a, b', null];
    }

    #[Test]
    public function aFlowValueIsSplitAtItsTopLevelCommas(): void
    {
        $items = new WorkflowYamlText()->flowItems("[push, 'a,b', {x: [1, 2]}, \"c\" ]");

        self::assertSame(['push', "'a,b'", '{x: [1, 2]}', '"c"'], $items);
    }

    #[Test]
    public function aFlowMappingIsSplitIntoItsEntries(): void
    {
        $items = new WorkflowYamlText()->flowItems('{push: {branches: [main]}, pull_request: {}}');

        self::assertSame(['push: {branches: [main]}', 'pull_request: {}'], $items);
    }

    #[Test]
    #[DataProvider('quotedNames')]
    public function aMatchingPairOfQuotesIsRemoved(string $text, string $expected): void
    {
        self::assertSame($expected, new WorkflowYamlText()->unquote($text));
    }

    /** @return Iterator<string, array{string, string}> */
    public static function quotedNames(): Iterator
    {
        yield 'single' => ["'qa'", 'qa'];

        yield 'double' => ['"qa"', 'qa'];

        yield 'plain' => ['qa', 'qa'];

        yield 'one quote only' => ["'qa", "'qa"];

        yield 'mixed quotes' => ['\'qa"', '\'qa"'];

        yield 'padded' => ['  qa  ', 'qa'];
    }

    #[Test]
    public function theIndentIsTheCountOfLeadingSpaces(): void
    {
        $text = new WorkflowYamlText();

        self::assertSame(0, $text->indentOf('on:'));
        self::assertSame(4, $text->indentOf('    qa:'));
        self::assertSame(2, $text->indentOf('  - a'));
    }
}
