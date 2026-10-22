<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\ArrowFunctionSpanCollector;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule's own test proves the spans against what it reports; this pins what
 * the collector records for one arrow function.
 *
 * @internal
 */
#[CoversClass(ArrowFunctionSpanCollector::class)]
#[Small]
final class ArrowFunctionSpanCollectorTest extends TestCase
{
    #[Test]
    public function itCollectsArrowFunctions(): void
    {
        self::assertSame(ArrowFunction::class, (new ArrowFunctionSpanCollector())->getNodeType());
    }

    #[Test]
    public function anArrowFunctionIsRecordedAsTheOffsetsItStartsAndEndsAt(): void
    {
        $arrow = new ArrowFunction(['expr' => new Variable('map')], ['startFilePos' => 120, 'endFilePos' => 164]);

        self::assertSame(
            ['start' => 120, 'end' => 164],
            (new ArrowFunctionSpanCollector())->processNode($arrow, $this->createStub(Scope::class)),
        );
    }
}
