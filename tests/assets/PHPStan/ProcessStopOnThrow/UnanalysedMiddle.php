<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ProcessStopOnThrow;

/** Sits between a test case and the base that stops its child; its file is left out of the analysis. */
abstract class UnanalysedMiddle extends AbstractStoppingTestCase
{
}
