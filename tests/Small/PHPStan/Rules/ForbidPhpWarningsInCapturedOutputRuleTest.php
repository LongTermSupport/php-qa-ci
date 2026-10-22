<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\CapturedPhpOutputCollector;
use LTS\PHPQA\PHPStan\Rules\ForbidPhpWarningsInCapturedOutputRule;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * Whether a captured command runs PHP, and whether it sends PHP's warnings to stderr, is read
 * from the code at the call or from the last write to the variable the call is given, so the rule
 * is pinned by analysing a fixture holding every spelling it reads.
 *
 * @internal
 *
 * @extends RuleTestCase<ForbidPhpWarningsInCapturedOutputRule>
 */
#[CoversClass(ForbidPhpWarningsInCapturedOutputRule::class)]
#[CoversClass(CapturedPhpOutputCollector::class)]
#[Medium]
final class ForbidPhpWarningsInCapturedOutputRuleTest extends RuleTestCase
{
    /** Every spelling of a captured PHP run the rule reads, routed and not. */
    private const string FIXTURE = __DIR__ . '/../../../assets/PHPStan/PhpWarningsInCapturedOutput/CapturedPhpCalls.php';

    /** The Composer classes a plugin is given, which this project does not install. */
    private const string COMPOSER_STUBS = __DIR__ . '/../../../assets/ComposerPlugin/composer-stubs.php';

    /** The shape of the originating instance: a state check built in a variable, then captured; the call is on line 15. */
    private const string STATE_CHECK = <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        namespace App\Plugin;

        use Composer\Util\ProcessExecutor;

        final class TurboState
        {
            public function left(ProcessExecutor $executor, string $installer): string
            {
                $output     = null;
                $stateCheck = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($installer) . ' state';
                $executor->execute($stateCheck, $output);

                return \is_string($output) ? $output : '';
            }
        }
        PHP_WRAP;

    /** The fixture is loaded first so the reflection provider knows its classes, as a project's autoloaded ones are. */
    #[Test]
    public function capturedPhpWhoseWarningsAreLeftOnStdoutIsReported(): void
    {
        require_once self::COMPOSER_STUBS;
        require_once self::FIXTURE;

        $this->analyse(
            [self::FIXTURE],
            array_map(
                static fn (int $line): array => [ForbidPhpWarningsInCapturedOutputRule::MESSAGE, $line],
                [22, 23, 24, 25, 26, 27, 28, 29, 30, 32, 33, 35, 37, 38, 39],
            ),
        );
    }

    /** The plugin's state check before #279: built in one statement, captured in the next. */
    #[Test]
    public function aCommandBuiltInAVariableIsJudgedByWhatTheVariableWasLastGiven(): void
    {
        require_once self::COMPOSER_STUBS;
        $project = TempDir::create('phpqaci-captured-php');

        try {
            $this->analyse([$project->write('src/Plugin/TurboState.php', self::STATE_CHECK)], [[ForbidPhpWarningsInCapturedOutputRule::MESSAGE, 15]]);
            $this->analyse([$project->write('src/Plugin/TurboState.php', str_replace("\\PHP_BINARY) . ' '", "\\PHP_BINARY) . ' -d display_errors=stderr '", self::STATE_CHECK))], []);
        } finally {
            $project->remove();
        }
    }

    protected function getRule(): Rule
    {
        return new ForbidPhpWarningsInCapturedOutputRule();
    }

    protected function getCollectors(): array
    {
        return [new CapturedPhpOutputCollector()];
    }
}
