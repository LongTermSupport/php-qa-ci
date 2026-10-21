<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Defence for the class "a required status-check context that no workflow job produces".
 *
 * Branch protection keys an Actions check by the JOB's name (its `name:`, or its id when it has
 * none), never the workflow's. A required context that no job reports is never satisfied, so
 * every pull request to the branch is blocked until an admin overrides it. Nothing else fails:
 * the workflows stay green and the context simply never arrives.
 *
 * scripts/setup-branch-protection.bash is where this repository declares the contexts it
 * requires; each must be a job name a workflow under .github/workflows produces. A job whose
 * name GitHub can only resolve at run time (a `${{ }}` expression, a matrix, a reusable-workflow
 * call) is reported as dynamic rather than matched: a required context is a fixed string, and
 * whether one of those produces it cannot be decided from the files.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class RequiredStatusChecksAreProducedTest extends TestCase
{
    private const string REPOSITORY = __DIR__ . '/../..';

    private const string PROTECTION_SCRIPT = self::REPOSITORY . '/scripts/setup-branch-protection.bash';

    private const string WORKFLOWS = self::REPOSITORY . '/.github/workflows';

    // The one assignment in the script's embedded Python that lists the required contexts.
    private const string CONTEXTS_ASSIGNMENT = '/^OUR_CONTEXTS = (\[.*\])$/m';

    private const string EXPRESSION = '${{';

    private const string KEY_NAME = 'name';

    private const string FIXTURE_FILE = 'fixture.yml';

    private const string FIXTURE_JOBS = <<<'YAML'
        on: push
        jobs:
          qa:
            name: QA Pipeline
            runs-on: ubuntu-latest
          lint:
            runs-on: ubuntu-latest
          versioned:
            name: PHP QA (${{ needs.detect.outputs.php }})
            runs-on: ubuntu-latest
          matrixed:
            name: Tests
            strategy:
              matrix:
                php: ['8.4', '8.5']
            runs-on: ubuntu-latest
          called:
            uses: ./.github/workflows/reusable.yml
        YAML;

    #[Test]
    public function everyContextTheProtectionScriptRequiresIsAJobNameAWorkflowProduces(): void
    {
        $static  = [];
        $dynamic = [];
        foreach ($this->workflowFiles() as $file) {
            $produced = $this->producedContexts(\Safe\file_get_contents($file), basename($file));
            $static   = [...$static, ...$produced['static']];
            $dynamic  = [...$dynamic, ...$produced['dynamic']];
        }

        $unproduced = array_values(array_diff($this->requiredContexts(), $static));

        self::assertSame(
            [],
            $unproduced,
            \sprintf(
                "scripts/setup-branch-protection.bash requires status-check contexts no workflow job produces, so every merge to the protected branch would be blocked.\nJob names the workflows produce: %s\nJob names resolved only at run time, not matched: %s\nRequire only a job name from the first list, or remove the context.",
                implode(', ', $static),
                [] === $dynamic ? '(none)' : implode('; ', $dynamic),
            ),
        );
    }

    #[Test]
    public function theProtectionScriptDeclaresTheContextsItRequires(): void
    {
        self::assertNotSame([], $this->requiredContexts(), 'With no required context the check above passes on nothing.');
    }

    #[Test]
    public function aJobIsProducedByItsNameOrElseItsId(): void
    {
        self::assertSame(
            ['QA Pipeline', 'lint'],
            $this->producedContexts(self::FIXTURE_JOBS, self::FIXTURE_FILE)['static'],
        );
    }

    #[Test]
    public function aNameResolvedAtRunTimeIsReportedAsDynamicNotMatched(): void
    {
        self::assertSame(
            [
                'fixture.yml jobs.versioned: PHP QA (${{ needs.detect.outputs.php }}) (an expression)',
                'fixture.yml jobs.matrixed: Tests (a matrix appends its values)',
                'fixture.yml jobs.called: called (a reusable-workflow call is reported as "caller / job")',
            ],
            $this->producedContexts(self::FIXTURE_JOBS, self::FIXTURE_FILE)['dynamic'],
        );
    }

    /** @return list<string> */
    private function requiredContexts(): array
    {
        $script = \Safe\file_get_contents(self::PROTECTION_SCRIPT);
        self::assertSame(
            1,
            \Safe\preg_match_all(self::CONTEXTS_ASSIGNMENT, $script),
            'scripts/setup-branch-protection.bash must assign OUR_CONTEXTS exactly once, on one line.',
        );
        $matches = [];
        \Safe\preg_match(self::CONTEXTS_ASSIGNMENT, $script, $matches);
        $list = $matches[1] ?? null;
        self::assertIsString($list);

        $contexts = \Safe\json_decode($list, true);
        self::assertIsArray($contexts);
        self::assertContainsOnlyString($contexts);
        self::assertTrue(array_is_list($contexts));

        return $contexts;
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $files = [
            ...\Safe\glob(self::WORKFLOWS . '/*.yml'),
            ...\Safe\glob(self::WORKFLOWS . '/*.yaml'),
        ];
        self::assertContainsOnlyString($files);
        sort($files);
        self::assertNotSame([], $files, 'No workflow found under .github/workflows.');

        return $files;
    }

    /**
     * The contexts a workflow's jobs report: `static` holds names known from the file; `dynamic`
     * describes each job whose reported name GitHub decides only at run time.
     *
     * @return array{static: list<string>, dynamic: list<string>}
     */
    private function producedContexts(string $yaml, string $file): array
    {
        $workflow = Yaml::parse($yaml);
        self::assertIsArray($workflow, $file . ' is not a YAML mapping.');
        $jobs = $workflow['jobs'] ?? [];
        self::assertIsArray($jobs, $file . ' has a jobs key that is not a mapping.');

        $static  = [];
        $dynamic = [];
        foreach ($jobs as $id => $job) {
            self::assertIsArray($job, \sprintf('%s jobs.%s is not a mapping.', $file, $id));
            $name = $job[self::KEY_NAME] ?? (string)$id;
            self::assertIsString($name, \sprintf('%s jobs.%s has a name that is not a string.', $file, $id));

            $reason = $this->dynamicReason($job, $name);
            if (null === $reason) {
                $static[] = $name;

                continue;
            }

            $dynamic[] = \sprintf('%s jobs.%s: %s (%s)', $file, $id, $name, $reason);
        }

        return ['static' => $static, 'dynamic' => $dynamic];
    }

    /** @param array<array-key, mixed> $job */
    private function dynamicReason(array $job, string $name): ?string
    {
        if (str_contains($name, self::EXPRESSION)) {
            return 'an expression';
        }

        if (\is_array($job['strategy'] ?? null) && \array_key_exists('matrix', $job['strategy'])) {
            return 'a matrix appends its values';
        }

        if (\array_key_exists('uses', $job)) {
            return 'a reusable-workflow call is reported as "caller / job"';
        }

        return null;
    }
}
