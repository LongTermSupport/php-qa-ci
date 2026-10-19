<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ForbidUnisolatedGitProcessRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
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
    use ScopeStubTrait;

    private const string FIXTURE = __DIR__ . '/../../../assets/PHPStan/UnisolatedGitProcess/GitCallsTest.php';

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
        $scope = self::scopeStub();
        $scope->method('getNamespace')->willReturn('App\Git');
        $scope->method('getFile')->willReturn('/app/src/Git/Status.php');

        $call = new New_(new FullyQualified(\Symfony\Component\Process\Process::class), [new Arg(new Array_([new \PhpParser\Node\ArrayItem(new String_('git'))]))]);

        self::assertSame([], new ForbidUnisolatedGitProcessRule()->processNode($call, $scope));
    }

    protected function getRule(): Rule
    {
        return new ForbidUnisolatedGitProcessRule();
    }
}
