<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Support;

use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
#[Small]
final class WorkflowNodeTest extends TestCase
{
    private const string PUSH = 'push';

    private const string DISPATCH = 'workflow_dispatch';

    private const string WORKFLOW = <<<'YAML'
        on:
          push:
            branches: [main, 'php8.5']
          workflow_dispatch:
        jobs:
          qa:
            timeout-minutes: 45
            continue-on-error: true
            permissions:
              contents: read
            steps:
              - run: first
              - run: second
                with:
                  run: nested
        YAML;

    #[Test]
    public function aMissingChildIsANodeThatIsNotThere(): void
    {
        $missing = WorkflowNode::ofYaml(self::WORKFLOW)->child('jobs', 'nope', 'deeper');

        self::assertNull($missing->string());
        self::assertSame([], $missing->keys());
        self::assertSame([], $missing->items());
        self::assertFalse($missing->has('anything'));
    }

    #[Test]
    public function aKeyWhoseValueIsEmptyIsStillThere(): void
    {
        $triggers = WorkflowNode::ofYaml(self::WORKFLOW)->child('on');

        self::assertTrue($triggers->has(self::DISPATCH));
        self::assertFalse($triggers->has('pull_request'));
        self::assertSame([self::PUSH, self::DISPATCH], $triggers->keys());
    }

    #[Test]
    public function aValueIsReadAsTheTypeItHasOrNotAtAll(): void
    {
        $job = WorkflowNode::ofYaml(self::WORKFLOW)->child('jobs', 'qa');

        self::assertSame(45, $job->child('timeout-minutes')->integer());
        self::assertNull($job->child('timeout-minutes')->string());
        self::assertTrue($job->child('continue-on-error')->boolean());
        self::assertSame(['contents' => 'read'], $job->child('permissions')->stringMap());
        self::assertSame(['main', 'php8.5'], WorkflowNode::ofYaml(self::WORKFLOW)->child('on', self::PUSH, 'branches')->strings());
        self::assertSame(['contents'], array_keys($job->child('permissions')->entries()));
    }

    #[Test]
    public function everyStringUnderAKeyOfThatNameIsFoundAtAnyDepth(): void
    {
        self::assertSame(
            ['first', 'second', 'nested'],
            WorkflowNode::ofYaml(self::WORKFLOW)->stringsUnderKey('run'),
        );
    }

    #[Test]
    public function aFileIsParsedLikeItsContents(): void
    {
        self::assertSame(
            [self::PUSH, 'pull_request', self::DISPATCH],
            WorkflowNode::ofFile(__DIR__ . '/../../../templates/github-actions/php-qa-ci.yml')->child('on')->keys(),
        );
    }
}
