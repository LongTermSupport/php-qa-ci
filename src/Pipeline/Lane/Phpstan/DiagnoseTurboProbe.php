<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Turbo\TurboManifest;
use LTS\PHPQA\Turbo\TurboPlatform;
use LTS\PHPQA\Turbo\TurboStatus;

/**
 * Asks the shipped phpstan.phar whether it runs with Turbo, through `diagnose`, invoked as the
 * lane invokes `analyse`: Xdebug off, the memory limit, the lane's wrapper config. The answer is
 * judged against the committed manifest: a host it pins a build for is one where Turbo should be
 * running, so its absence there is a fault rather than the expected fallback. A `-p` run reuses
 * the last diagnose while nothing Turbo's verdict rests on has changed (PhpstanSetupCache); the
 * manifest is read afresh every time.
 *
 * @internal
 */
final readonly class DiagnoseTurboProbe implements TurboProbeInterface
{
    private const string PHAR = '/phpstan.phar';

    /** null asks the running PHP, which is the one composer install fetched the binary for. */
    public function __construct(private ?TurboPlatform $platform = null)
    {
    }

    public function status(ToolContext $context, string $wrapperNeon): TurboStatus
    {
        $output = new PhpstanSetupCache()->diagnose($context, $wrapperNeon, fn (): ?string => $this->diagnose($context, $wrapperNeon));

        return TurboStatus::fromDiagnose(null === $output ? '' : $output, $this->shippedForHost($context->config->paths->pharDir));
    }

    /** What diagnose printed, or null when it failed: a failed run's lines are not its report. */
    private function diagnose(ToolContext $context, string $wrapperNeon): ?string
    {
        $paths  = $context->config->paths;
        $result = $context->php->withoutXdebug(
            $paths->pharDir . self::PHAR,
            ['diagnose', '--no-interaction', '-c', $wrapperNeon],
            $paths->projectRoot,
            streamOutput: false,
        );

        return $result->succeeded() ? $result->output : null;
    }

    private function shippedForHost(string $pharDir): bool
    {
        $manifest = TurboManifest::fromJson(\Safe\file_get_contents($pharDir . '/' . basename(TurboManifest::PATH)));

        return $manifest->pinsBuildFor($this->platform ?? TurboPlatform::fromRuntime());
    }
}
