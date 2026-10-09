<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Lane\Phpstan\TurboProbeInterface;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Turbo\TurboStatus;

/**
 * Answers the PHPStan lane's Turbo question with a fixed status and records each wrapper neon it
 * was asked about, so the lane's tests keep their process queue to the analysis itself.
 */
final class FixedTurboProbe implements TurboProbeInterface
{
    /** @var list<string> */
    public array $askedAbout = [];

    public function __construct(private readonly TurboStatus $status)
    {
    }

    public function status(ToolContext $context, string $wrapperNeon): TurboStatus
    {
        $this->askedAbout[] = $wrapperNeon;

        return $this->status;
    }
}
