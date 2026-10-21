<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan\Rules;

use LTS\PHPQA\PHPStan\Rules\NumericStringArrayKeyWritesCollector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rule's own test proves the writes against what it reports; this pins
 * how each body's writes are recorded, read back through a rule that reports
 * each record as its key and its writes, each at the line it is on.
 *
 * @internal
 *
 * @extends RuleTestCase<NumericStringArrayKeyWritesDumpRule>
 */
#[CoversClass(NumericStringArrayKeyWritesCollector::class)]
#[Medium]
final class NumericStringArrayKeyWritesCollectorTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../assets/PHPStan/NumericStringArrayKey/Writes.php';

    /** A write of an aliased variable, like the alias's own, is one PHPStan cannot follow; a literal's items are the literal's write. */
    #[Test]
    public function eachWriteIsRecordedWhereItIsAndEachBodyThatMayWriteUnseenIsKeyedAny(): void
    {
        require_once self::FIXTURE;

        $this->analyse(
            [self::FIXTURE],
            [
                ['|name@14,list@-1,list@-1,alias@-1', 14],
                ['|outer@25', 23],
                ['|inner@27,outer@27,sum@28', 27],
                ['*|count@36,count@38', 34],
                ['*|name@46', 46],
                ['*|', 54],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new NumericStringArrayKeyWritesDumpRule();
    }

    protected function getCollectors(): array
    {
        return [new NumericStringArrayKeyWritesCollector()];
    }
}
