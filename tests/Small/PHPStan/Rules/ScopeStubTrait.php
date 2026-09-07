<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\MockObject\Stub;

/**
 * A Scope double for rule-level tests.
 *
 * Rule::processNode() receives the intersection the analyser actually passes,
 * so the double must satisfy all three interfaces or the call is a type error.
 * PHPUnit builds such a double with createStubForIntersectionOfInterfaces(),
 * and this wrapper gives every rule test one place to get it. A stub rather than a mock,
 * because these tests configure return values and never set expectations,
 * and a mock without expectations is exactly what
 * ForbidAllowMockWithoutExpectationsRule exists to forbid.
 *
 * @internal
 */
trait ScopeStubTrait
{
    private static function scopeStub(): CollectedDataEmitter&NodeCallbackInvoker&Scope&Stub
    {
        return self::createStubForIntersectionOfInterfaces([CollectedDataEmitter::class, NodeCallbackInvoker::class, Scope::class]);
    }
}
