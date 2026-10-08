<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Infection;

use LTS\PHPQA\Pipeline\Config\Dto\InfectionOptionsDto;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionArguments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Defence for the class "a lane passes the shipped tool an option that tool's version does not
 * accept" (#74).
 *
 * Nothing else ties the lane's argv to the bundled phar: a tool update that drops an option leaves
 * the setting that emits it orphaned, and the project that sets it gets a tool refusing its own
 * arguments instead of a run. So every option the Infection lane can emit, with every setting
 * that adds one switched on, must be listed by the shipped infection.phar's own `run --help`.
 *
 * @internal
 */
#[CoversClass(InfectionArguments::class)]
#[Large]
final class InfectionArgumentsAreAcceptedByTheShippedPharTest extends TestCase
{
    private const string PHAR = __DIR__ . '/../../../vendor-phar/infection.phar';

    /** @var list<string>|null the long options the phar lists, read once */
    private static ?array $accepted = null;

    /** @param list<string> $argv */
    #[Test]
    #[DataProvider('laneArgv')]
    public function everyOptionTheLaneEmitsIsOneTheShippedPharAccepts(array $argv): void
    {
        $emitted = [];
        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--')) {
                $emitted[] = explode('=', $argument, 2)[0];
            }
        }

        self::assertNotSame([], $emitted, 'the lane emitted no options, so this check would be vacuous');
        self::assertSame([], array_values(array_diff($emitted, $this->accepted())), 'options the shipped infection.phar does not list in run --help');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function laneArgv(): iterable
    {
        $options = new InfectionOptionsDto(
            enabled: true,
            threads: 2,
            onlyCovered: true,
            minMsi: 60,
            minCoveredMsi: 80,
            diffBase: 'origin/main',
            diffCoveredMsi: 80,
        );
        $arguments = new InfectionArguments();

        yield 'full run' => [$arguments->full($options, '/coverage', '/infection.json')];
        yield 'diff run' => [$arguments->diff($options, '/coverage', '/infection.json', '/src/One.php')];
    }

    /** @return list<string> */
    private function accepted(): array
    {
        if (null === self::$accepted) {
            $process = new Process([\PHP_BINARY, self::PHAR, 'run', '--help', '--no-ansi']);
            $process->mustRun();
            $matches = [];
            \Safe\preg_match_all('/(?<![\w-])--[a-z][a-z0-9-]*/', $process->getOutput(), $matches);
            $accepted = [];
            $found    = $matches[0] ?? [];
            foreach (\is_array($found) ? $found : [] as $option) {
                if (\is_string($option) && !\in_array($option, $accepted, true)) {
                    $accepted[] = $option;
                }
            }

            self::$accepted = $accepted;
        }

        return self::$accepted;
    }
}
