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
 * `bin/qa` is a PHP file behind a `php` shebang, and these tests pin the forms
 * that reach the pipeline: executed directly, and handed to a PHP binary.
 *
 * The second is the one worth a test. Composer generates a PHP proxy for a bin
 * entry with a `php` shebang, and that proxy includes this file rather than
 * executing it, so `php vendor/bin/qa` is a first-class way in rather than a
 * mistake. A change that made the entrypoint anything other than PHP would
 * break that silently, in consumers rather than here.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class QaEntrypointInvocationTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string USAGE_MARKER = 'use -t to specify a single tool';

    /** @return Iterator<string, array{string}> */
    public static function invocations(): Iterator
    {
        yield 'as an executable'      => ['./bin/qa'];
        yield 'handed to a PHP binary' => ['php bin/qa'];
    }

    #[Test]
    #[DataProvider('invocations')]
    public function everyShippedInvocationFormReachesThePipeline(string $invocation): void
    {
        $result = ShellRunner::run($invocation . ' -h', \Safe\realpath(self::REPO_ROOT));

        self::assertStringContainsString(
            self::USAGE_MARKER,
            $result['output'],
            $invocation . ' did not reach the pipeline',
        );
    }

    #[Test]
    public function theEntrypointIsPhpAndSaysSoInItsShebang(): void
    {
        // The property the Composer bin proxy depends on: Composer reads the
        // shebang to decide whether to generate a PHP proxy or a shell one.
        $first = \strtok(\Safe\file_get_contents(self::REPO_ROOT . '/bin/qa'), "\n");

        self::assertIsString($first);
        self::assertStringContainsString('php', $first);
        self::assertStringStartsWith('#!', $first);
    }

    #[Test]
    public function bothFormsProduceTheSameOutput(): void
    {
        $root = \Safe\realpath(self::REPO_ROOT);

        $direct = ShellRunner::run('./bin/qa -h', $root);
        $viaPhp = ShellRunner::run('php bin/qa -h', $root);

        self::assertSame($direct['output'], $viaPhp['output']);
        self::assertSame($direct['exit'], $viaPhp['exit']);
    }

    #[Test]
    public function theEntrypointHonoursThePipelinesOwnPhpExecutableVariable(): void
    {
        $root = \Safe\realpath(self::REPO_ROOT);

        $result = ShellRunner::run('PHP_QA_CI_PHP_EXECUTABLE=/definitely/not/a/php php bin/qa -t phpLint', $root);

        self::assertNotSame(0, $result['exit']);
        self::assertStringNotContainsString(
            'every QA tool passed',
            $result['output'],
            'the nominated interpreter is what the lanes must run under',
        );
    }
}
