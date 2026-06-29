<?php

declare(strict_types=1);

namespace ArkitectFixture;

// A \Throwable whose name does NOT end in Exception — violates the optional
// tier's IsA(\Throwable) -> *Exception rule. (final, so the Abstract* rule does
// not apply — this isolates the Exception-suffix rule.)
final class BadError extends \RuntimeException
{
}
