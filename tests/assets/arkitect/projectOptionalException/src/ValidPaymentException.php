<?php

declare(strict_types=1);

namespace ArkitectFixture;

// A correctly-suffixed \Throwable — must PASS the IsA(\Throwable) -> *Exception
// rule, proving the rule is not over-broad (no false positive on good names).
final class ValidPaymentException extends \RuntimeException
{
}
