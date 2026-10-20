<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Lane\Phpstan\PhpstanCrash;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;

/**
 * Why a phpstan.phar run with --error-format=json is not a clean analysis, for
 * a test that expects findings but no error of PHPStan's own.
 *
 * @internal
 */
final readonly class PhpstanJsonRunFailure
{
    /** The reason the run failed, or null when it reached a verdict. */
    public static function reason(ProcessResultDto $result): ?string
    {
        return PhpstanCrash::jsonReason($result);
    }
}
