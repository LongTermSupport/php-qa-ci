<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\PHPStan;

use LTS\PHPQA\Tests\Support\FixtureConsumer;
use LTS\PHPQA\Turbo\TurboManifest;
use LTS\PHPQA\Turbo\TurboPlatform;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "PHPStan runs without Turbo and nobody notices", in the shape a consumer
 * runs it: php-qa-ci under vendor/lts/php-qa-ci, the consumer's own bin/qa, the PHPStan lane with
 * its wrapper config. PhpstanRunsWithTurboTest asks the phar directly; this asks the lane, which
 * is what a consumer sees.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class ConsumerPhpstanLaneRunsWithTurboTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private ?FixtureConsumer $consumer = null;

    protected function setUp(): void
    {
        $manifest = TurboManifest::fromJson(\Safe\file_get_contents(self::REPO_ROOT . '/' . TurboManifest::PATH));
        if (!$manifest->pinsBuildFor(TurboPlatform::fromRuntime())) {
            self::markTestSkipped('php-qa-ci ships no Turbo build for this host, so the lane runs without it by design');
        }

        $this->consumer = FixtureConsumer::create('phpstan-turbo-consumer');
    }

    protected function tearDown(): void
    {
        $this->consumer?->remove();
    }

    #[Test]
    public function aConsumersPhpstanLaneRunsWithTurbo(): void
    {
        $consumer = $this->consumer;
        self::assertNotNull($consumer);

        // PHPStan's own cache defaults to the system temp directory; keep it inside the consumer.
        $process = $consumer->qa(['TMPDIR' => $consumer->dir->mkdir('tmp')], '-t', 'stan');
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        self::assertStringContainsString('PHPStan Turbo: enabled', $output, $output);
    }
}
