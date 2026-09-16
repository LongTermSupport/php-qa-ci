<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline;

use Iterator;
use LTS\PHPQA\Tests\Assets\DeploySkills\ShellRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/qa is reached by hand, by composer's generated proxy, by CI and by
 * editor hooks, and a shell interpreter in front of it is a habit no amount of
 * documentation prevents. A PHP file behind a `php` shebang answers only one of
 * those invocations and reports a shell syntax error for the rest, naming
 * neither PHP nor the file's real nature.
 *
 * The shim exists so every form reaches the same pipeline. These tests pin
 * that, because the failure it prevents is silent: nothing about a PHP file
 * with a shebang looks wrong until somebody types the wrong three characters.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class QaEntrypointInvocationTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    #[Test]
    #[DataProvider('invocations')]
    public function everyInvocationFormReachesThePipeline(string $invocation): void
    {
        $result = ShellRunner::run($invocation . ' -h', \Safe\realpath(self::REPO_ROOT));

        self::assertStringContainsString(
            'use -t to specify a single tool',
            $result['output'],
            $invocation . ' did not reach the pipeline',
        );
        self::assertStringNotContainsString('syntax error', $result['output']);
    }

    /** @return Iterator<string, array{string}> */
    public static function invocations(): Iterator
    {
        yield 'as an executable' => ['./bin/qa'];
        yield 'through bash'     => ['bash bin/qa'];
        yield 'through sh'       => ['sh bin/qa'];
    }

    #[Test]
    public function theShimAndThePhpEntrypointAgree(): void
    {
        $root = \Safe\realpath(self::REPO_ROOT);

        $viaShim = ShellRunner::run('bash bin/qa -h', $root);
        $viaPhp  = ShellRunner::run('php bin/qa.php -h', $root);

        self::assertSame($viaPhp['output'], $viaShim['output']);
        self::assertSame($viaPhp['exit'], $viaShim['exit']);
    }

    #[Test]
    public function theShimHonoursThePipelinesOwnPhpExecutableVariable(): void
    {
        $root = \Safe\realpath(self::REPO_ROOT);

        $result = ShellRunner::run('PHP_QA_CI_PHP_EXECUTABLE=/definitely/not/a/php bash bin/qa -h', $root);

        self::assertNotSame(0, $result['exit']);
        self::assertStringNotContainsString(
            'use -t to specify a single tool',
            $result['output'],
            'the shim must use the nominated interpreter, not fall back to a different PHP',
        );
    }
}
