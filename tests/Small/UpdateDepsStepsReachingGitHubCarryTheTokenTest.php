<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "an update-deps step that reaches the GitHub API without the job's token".
 *
 * The root project's `composer update` runs `scripts/tool-install.bash update` as its
 * post-update script, and that calls the GitHub API: phive resolving releases, the ShellCheck
 * updater, the docs installer. Unauthenticated, a shared runner hits the API's rate limit, so the
 * daily job fails and files a failure issue for nothing. Every step that runs the script, or a
 * root `composer update`, must set GITHUB_AUTH_TOKEN. A `--working-dir` update of a build/
 * manifest runs that manifest's scripts, which are none, so it does not count.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class UpdateDepsStepsReachingGitHubCarryTheTokenTest extends TestCase
{
    /** The daily job. */
    private const string WORKFLOW = __DIR__ . '/../../.github/workflows/update-deps.yml';

    /** The script that calls the GitHub API. */
    private const string TOOL_INSTALL = 'tool-install.bash';

    /** A root `composer update`, which runs that script: a line with no --working-dir. */
    private const string ROOT_COMPOSER_UPDATE = '/^(?!.*--working-dir).*\bcomposer update\b/m';

    /** What a step must set to authenticate them. */
    private const string TOKEN = 'GITHUB_AUTH_TOKEN:';

    /** A step starts at a list item under `steps:`. */
    private const string STEP_START = '/^ {6}- /m';

    public function testEveryStepReachingGitHubSetsTheToken(): void
    {
        self::assertSame([], $this->stepsWithoutToken(\Safe\file_get_contents(self::WORKFLOW)));
    }

    /** The guard proven to fire: a step running composer update with no token is reported. */
    public function testTheGuardReportsAStepWithoutTheToken(): void
    {
        $withToken = "    steps:\n      - name: Update\n        env:\n          GITHUB_AUTH_TOKEN: x\n        run: composer update\n";
        $without   = "      - name: Again\n        run: composer update --no-interaction\n";
        $unrelated = "      - name: Lint\n        run: bin/qa\n";
        $buildOnly = "      - name: Build\n        run: composer update --working-dir=build/rector --no-dev\n\n      # the composer update above ran tool-install.bash\n";
        $script    = "      - name: Phars\n        run: bash scripts/tool-install.bash update\n";

        self::assertSame([], $this->stepsWithoutToken($withToken . $unrelated . $buildOnly));
        self::assertSame(['Again', 'Phars'], $this->stepsWithoutToken($withToken . $without . $unrelated . $script));
    }

    /** @return list<string> the names of the steps that reach GitHub without the token */
    private function stepsWithoutToken(string $workflow): array
    {
        $missing = [];
        $steps   = \Safe\preg_split(self::STEP_START, $workflow);
        foreach (\array_slice($steps, 1) as $step) {
            if (!$this->reachesGitHub($step) || str_contains($step, self::TOKEN)) {
                continue;
            }

            $missing[] = 1 === \Safe\preg_match('/^name: (.+)$/m', $step, $match) ? trim($match[1]) : trim(strtok($step, "\n"));
        }

        return $missing;
    }

    /** Comment lines are prose, and the comment above a step lands in the step before it. */
    private function reachesGitHub(string $step): bool
    {
        $code = \Safe\preg_replace('/^\s*#.*$/m', '', $step);

        return str_contains($code, self::TOOL_INSTALL) || 1 === \Safe\preg_match(self::ROOT_COMPOSER_UPDATE, $code);
    }
}
