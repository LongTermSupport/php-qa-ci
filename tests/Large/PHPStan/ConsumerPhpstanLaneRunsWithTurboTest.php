<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\PHPStan;

use LTS\PHPQA\Tests\Support\FixtureConsumer;
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
    private FixtureConsumer $consumer;

    protected function setUp(): void
    {
        $this->consumer = FixtureConsumer::create('phpstan-turbo-consumer');
    }

    protected function tearDown(): void
    {
        $this->consumer->remove();
    }

    #[Test]
    public function aConsumersPhpstanLaneRunsWithTurbo(): void
    {
        // PHPStan's own cache defaults to the system temp directory; keep it inside the consumer.
        $process = $this->consumer->qa(['TMPDIR' => $this->consumer->dir->mkdir('tmp')], '-t', 'stan');
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        self::assertStringContainsString('PHPStan Turbo: enabled', $output, $output);
    }
}
