<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

use Symfony\Component\Process\Process;

/** A subtype of Process: the rule follows the type, not the class name. */
final class ChildProcess extends Process
{
}
