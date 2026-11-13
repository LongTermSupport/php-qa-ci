<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\VersionPins;

use LTS\PHPQA\Pipeline\Tool\PhaseEnum;
use LTS\PHPQA\VersionPins\WorkflowPhaseGroupsDetector;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a shipped workflow template that selects phase groups
 * and quietly leaves one out", so that a green check on a project that copied
 * the template says what it ran.
 *
 * The versionPins lane applies the same detector to the templates in a
 * checkout of this repository; this test pins that the shipped files pass it
 * and that the groups it requires are the phases the pipeline defines.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class ShippedTemplatesRunEveryPhaseGroupTest extends TestCase
{
    private const string TEMPLATES = __DIR__ . '/../../../templates/github-actions';

    #[Test]
    public function theAutofixTemplateGateRunsEveryPhaseGroupOrSaysWhyNot(): void
    {
        $yaml = \Safe\file_get_contents(self::TEMPLATES . '/qa-autofix.yml');

        self::assertSame([], new WorkflowPhaseGroupsDetector()->check($yaml, ...$this->phaseGroups()));
    }

    #[Test]
    public function theAutofixTemplateGateRunsTheLintingGroup(): void
    {
        $yaml = \Safe\file_get_contents(self::TEMPLATES . '/qa-autofix.yml');

        self::assertMatchesRegularExpression('/^\s*run: .*-t allLints\'?$/m', $yaml);
    }

    #[Test]
    public function everyShippedTemplatePassesTheDetector(): void
    {
        $templates = \Safe\glob(self::TEMPLATES . '/*.yml');
        self::assertNotSame([], $templates);

        foreach ($templates as $template) {
            self::assertIsString($template);
            self::assertSame(
                [],
                new WorkflowPhaseGroupsDetector()->check(\Safe\file_get_contents($template), ...$this->phaseGroups()),
                basename($template),
            );
        }
    }

    #[Test]
    public function theRequiredGroupsAreThePhasesThePipelineDefines(): void
    {
        self::assertSame(['allCS', 'allLints', 'allStatic', 'allTests'], $this->phaseGroups());
    }

    /** @return list<string> the `-t` token that runs each phase */
    private function phaseGroups(): array
    {
        return array_map(
            static fn (PhaseEnum $phase): string => $phase->phase()->runnerAliases[0],
            PhaseEnum::cases(),
        );
    }
}
