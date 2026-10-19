<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidUnisolatedGitProcessRule;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * Whether git is spawned and whether its environment isolates it are both read from PHPStan's
 * types, so the rule is pinned by analysing a fixture under a tests/ path.
 *
 * @internal
 *
 * @extends RuleTestCase<ForbidUnisolatedGitProcessRule>
 */
#[CoversClass(ForbidUnisolatedGitProcessRule::class)]
#[Medium]
final class ForbidUnisolatedGitProcessRuleTest extends RuleTestCase
{
    /** Every spelling of a git run the rule reads, isolated and not, in a test namespace. */
    private const string FIXTURE =__DIR__ . '/../../../assets/PHPStan/UnisolatedGitProcess/GitCallsTest.php';

    /** One unisolated git run in a namespace that does not mark it as test code; the call is on line 13. */
    private const string UNISOLATED_GIT = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Git;

        use Symfony\Component\Process\Process;

        final class Status
        {
            public function run(): void
            {
                new Process(['git', 'status'])->run();
            }
        }
        PHP;

    private TempDir $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = TempDir::create('phpqaci-unisolated-git');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        parent::tearDown();
    }

    /** The fixture is loaded first so the reflection provider knows its constants, as a project's autoloaded ones are. */
    #[Test]
    public function gitRunWithoutAnEnvironmentThatSwitchesOffTheHostConfigIsReported(): void
    {
        require_once self::FIXTURE;

        $this->analyse(
            [self::FIXTURE],
            [
                [ForbidUnisolatedGitProcessRule::MESSAGE, 18],
                [ForbidUnisolatedGitProcessRule::MESSAGE, 19],
                [ForbidUnisolatedGitProcessRule::MESSAGE, 20],
                [ForbidUnisolatedGitProcessRule::MESSAGE, 21],
                [ForbidUnisolatedGitProcessRule::MESSAGE, 23],
                [ForbidUnisolatedGitProcessRule::MESSAGE, 24],
                [ForbidUnisolatedGitProcessRule::MESSAGE, 25],
                [ForbidUnisolatedGitProcessRule::MESSAGE, 26],
                [ForbidUnisolatedGitProcessRule::MESSAGE, 27],
                [ForbidUnisolatedGitProcessRule::MESSAGE, 36],
            ],
        );
    }

    /** Production code runs git on the user's own repository, where the user's configuration is wanted. */
    #[Test]
    public function codeOutsideATestIsNotReported(): void
    {
        $this->analyse([$this->project->write('src/Git/Status.php', self::UNISOLATED_GIT)], []);
    }

    /** The same call in a file under tests/ is test code even when its namespace does not say so. */
    #[Test]
    public function aFileUnderTestsIsTestCodeWhateverItsNamespace(): void
    {
        $this->analyse([$this->project->write('tests/Git/StatusTest.php', self::UNISOLATED_GIT)], [[ForbidUnisolatedGitProcessRule::MESSAGE, 13]]);
    }

    protected function getRule(): Rule
    {
        return new ForbidUnisolatedGitProcessRule();
    }
}
