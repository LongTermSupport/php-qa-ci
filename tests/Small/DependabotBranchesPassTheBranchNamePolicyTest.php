<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\BranchNamePolicyDecision;
use LTS\PHPQA\Tests\Support\WorkflowNode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "an updater opens pull requests from branches the branch-name policy fails".
 *
 * Dependabot names its branches `dependabot/<ecosystem>/<dependency>` unless told otherwise, and the
 * branch-name policy allows only `feature/`, `bugfix/`, `chore/` and `hotfix/` (plus the project's
 * own list). A pull request from a branch the policy rejects is red from the moment it opens, and
 * widening the policy to fit it is an Owner decision. Each `updates` entry therefore sets the
 * branch prefix to one the policy already allows; the prefix and its separator, not the bare word,
 * are what the policy reads.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class DependabotBranchesPassTheBranchNamePolicyTest extends TestCase
{
    private const string CONFIG = __DIR__ . '/../../.github/dependabot.yml';

    #[Test]
    public function everyUpdateEntryBranchesUnderAPrefixThePolicyAllows(): void
    {
        $problems = $this->problems(WorkflowNode::ofFile(self::CONFIG));

        self::assertSame([], $problems);
    }

    #[Test]
    public function theGuardReportsNoPrefixAForeignPrefixAndASeparatorThatBreaksTheMatch(): void
    {
        $yaml = <<<'YAML'
            version: 2
            updates:
              - package-ecosystem: github-actions
              - package-ecosystem: composer
                pull-request-branch-name: {prefix: deps}
              - package-ecosystem: npm
                pull-request-branch-name: {prefix: chore, separator: "-"}
              - package-ecosystem: pip
                pull-request-branch-name: {prefix: chore}
              - package-ecosystem: docker
                pull-request-branch-name: {prefix: hotfix, separator: "/"}
            YAML;

        self::assertSame(
            [
                'github-actions sets no pull-request-branch-name.prefix, so its branches start with dependabot/',
                'composer branches start with "deps/", which the policy does not allow',
                'npm branches start with "chore-", which the policy does not allow',
            ],
            $this->problems(WorkflowNode::ofYaml($yaml)),
        );
    }

    #[Test]
    public function theGuardReportsAConfigurationWithNoUpdateEntries(): void
    {
        self::assertSame(
            ['the configuration has no updates entry, so no branch is checked'],
            $this->problems(WorkflowNode::ofYaml("version: 2\nupdates: []\n")),
        );
        self::assertSame(
            ['the configuration has no updates entry, so no branch is checked'],
            $this->problems(WorkflowNode::ofYaml("version: 2\n")),
        );
    }

    /** @return list<string> */
    private function problems(WorkflowNode $config): array
    {
        $problems = [];
        foreach ($config->child('updates')->items() as $update) {
            $ecosystem = $update->child('package-ecosystem')->text();
            $branch    = $update->child('pull-request-branch-name');
            $prefix    = $branch->child('prefix')->string();
            if (null === $prefix) {
                $problems[] = $ecosystem . ' sets no pull-request-branch-name.prefix, so its branches start with dependabot/';

                continue;
            }

            $start = $prefix . ($branch->child('separator')->string() ?? '/');
            if (!\in_array($start, BranchNamePolicyDecision::DEFAULT_PREFIXES, true)) {
                $problems[] = \sprintf('%s branches start with "%s", which the policy does not allow', $ecosystem, $start);
            }
        }

        return $problems;
    }
}
