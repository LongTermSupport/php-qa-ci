<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a template that tells a consumer to require a check its own job does not
 * report".
 *
 * Branch protection matches a required check by its exact text. The header of the autofix template
 * tells a consumer which check to require; if it names anything but the text the `gate` job reports
 * (its `name:`), the consumer requires a check that never arrives and the pull request waits for
 * ever.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class QaAutofixTemplateNamesItsGateTest extends TestCase
{
    private const string TEMPLATE = __DIR__ . '/../../templates/github-actions/qa-autofix.yml';

    #[Test]
    public function theHeaderNamesTheCheckTheGateJobReports(): void
    {
        $name = WorkflowNode::ofFile(self::TEMPLATE)->child('jobs', 'gate', 'name')->string();

        self::assertNotNull($name, 'the gate job has no name, so its check is reported by its id');
        self::assertTrue(
            str_contains(\Safe\file_get_contents(self::TEMPLATE), \sprintf('require the "%s" check', $name)),
            \sprintf('the header does not tell a consumer to require "%s", the check the gate job reports', $name),
        );
    }
}
