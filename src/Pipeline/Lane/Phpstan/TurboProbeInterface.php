<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Turbo\TurboStatus;

/**
 * How the PHPStan lane learns whether PHPStan runs with Turbo. The shipped answer is
 * DiagnoseTurboProbe, which asks the phar itself.
 *
 * @internal
 */
interface TurboProbeInterface
{
    /** @param string $wrapperNeon the lane's wrapper config, so the answer is about the run the lane makes */
    public function status(ToolContext $context, string $wrapperNeon): TurboStatus;
}
