<?php

declare(strict_types=1);

namespace RectorPhpunitFixture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Positive control: the shipped PHPUnit Rector configuration MUST rewrite this
 * file (the @test annotation becomes an attribute). Its being changed proves the
 * run loaded that configuration and reached this directory, so the neighbouring
 * fixture's being left alone is a verdict rather than a file Rector never saw.
 *
 * @internal
 */
#[CoversNothing]
final class AnnotationControlTest extends TestCase
{
    /**
     * @test
     */
    public function itIsRewritten(): void
    {
        self::assertTrue(true);
    }
}
