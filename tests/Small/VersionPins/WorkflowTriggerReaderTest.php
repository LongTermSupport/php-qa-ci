<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use Iterator;
use LTS\PHPQA\VersionPins\WorkflowTriggerReader;
use LTS\PHPQA\VersionPins\WorkflowYamlText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WorkflowTriggerReader::class)]
#[UsesClass(WorkflowYamlText::class)]
#[Small]
final class WorkflowTriggerReaderTest extends TestCase
{
    private const string PUSH = 'push';

    private const string PULL_REQUEST = 'pull_request';

    /** @param list<string>|null $expected */
    #[Test]
    #[DataProvider('triggers')]
    public function theEventsOfATriggerAreRead(string $text, ?array $expected): void
    {
        self::assertSame($expected, new WorkflowTriggerReader()->events($text));
    }

    /** @return Iterator<string, array{string, list<string>|null}> */
    public static function triggers(): Iterator
    {
        $both = [self::PUSH, self::PULL_REQUEST];

        yield 'a scalar' => ["on: push\njobs:\n", [self::PUSH]];

        yield 'a quoted scalar' => ["on: 'pull_request'\n", [self::PULL_REQUEST]];

        yield 'a flow list' => ["on: [push, pull_request]\n", $both];

        yield 'a flow list over several lines' => ["on: [\n  push,\n  'pull_request'\n]\njobs:\n", $both];

        yield 'a flow mapping' => ["on: {push: {branches: [main]}, pull_request: {}}\n", $both];

        yield 'a flow mapping over several lines' => ["on: {\n  push: {},\n  \"pull_request\": {}\n}\n", $both];

        yield 'a flow mapping of bare names' => ["on: {push, pull_request}\n", $both];

        yield 'a flow mapping of bare names over several lines' => ["on: {\n  push,\n  'pull_request'\n}\n", $both];

        yield 'a flow mapping of bare and valued names' => ["on: {push, pull_request: {types: [opened]}}\n", $both];

        yield 'a flow mapping holding a bare alias' => ["on: {push, *x}\n", null];

        yield 'a block mapping' => ["on:\n  push:\n    branches: [main]\n  pull_request:\n    types: [opened]\njobs:\n", $both];

        yield 'a block mapping indented four spaces' => ["on:\n    pull_request:\njobs:\n", [self::PULL_REQUEST]];

        yield 'a block mapping with quoted keys' => ["on:\n  'push':\n  \"pull_request\":\n", $both];

        yield 'a block list' => ["on:\n  - push\n  - pull_request\njobs:\n", $both];

        yield 'a block list with no indent' => ["on:\n- push\n- 'pull_request'\njobs:\n", $both];

        yield 'a quoted on key' => ["\"on\":\n  pull_request:\n", [self::PULL_REQUEST]];

        yield 'a single-quoted on key' => ["'on': pull_request\n", [self::PULL_REQUEST]];

        yield 'an on key after other keys' => ["name: ci\nenv:\n  A: b\non: push\n", [self::PUSH]];

        yield 'only first-level keys are events' => ["on:\n  push:\n    branches:\n      - pull_request\n", [self::PUSH]];

        yield 'an alias as the value' => ["on: *events\n", null];

        yield 'an anchored scalar' => ["on: &t push\n", null];

        yield 'a tagged scalar' => ["on: !!str push\n", null];

        yield 'a block scalar' => ["on: |\n  push\n", null];

        yield 'a merge key' => ["on:\n  <<: *base\n", null];

        yield 'an alias key' => ["on:\n  *x:\n", null];

        yield 'an alias in a list' => ["on:\n  - *x\n", null];

        yield 'a flow list holding an alias' => ["on: [push, *x]\n", null];

        yield 'a flow list never closed' => ["on: [push\njobs:\n", null];

        yield 'a flow mapping with a merge key' => ["on: {<<: *base}\n", null];

        yield 'no on key' => ["jobs:\n  qa:\n    steps: []\n", null];

        yield 'an on key with nothing under it' => ["on:\njobs:\n", null];

        yield 'a scalar with a space in it' => ["on: push pull_request\n", null];

        yield 'a null written as a tilde' => ["on: ~\n", null];

        yield 'a null written out' => ["on: null\n", null];

        yield 'a null written capitalised' => ["on: Null\n", null];

        yield 'a null written in capitals' => ["on: NULL\n", null];

        yield 'a block list item written as a mapping' => ["on:\n  - pull_request:\n", null];

        yield 'a flow list item written as a mapping' => ["on: [push, pull_request:]\n", null];

        yield 'a quoted name that ends in a colon is one name' => ["on: ['pull_request:']\n", ['pull_request:']];

        yield 'a block list item with a quoted colon is one name' => ["on:\n  - 'a: b'\n", ['a: b']];

        yield 'a longer key that starts with on' => ["online: yes\n", null];
    }
}
