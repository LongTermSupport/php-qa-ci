<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Agent\Dto;

use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use LTS\PHPQA\Pipeline\Agent\Dto\ParsedArchReportDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ParsedArchReportDto::class)]
#[UsesClass(FileErrorDto::class)]
#[Small]
final class ParsedArchReportDtoTest extends TestCase
{
    private const string FIRST = 'App\BadlyNamed';

    private const string SECOND = 'App\Sub\AlsoBad';

    #[Test]
    public function anEmptyReportCountsNothing(): void
    {
        $parsed = new ParsedArchReportDto([]);

        self::assertSame(0, $parsed->violationCount());
    }

    #[Test]
    public function theViolationCountSpansEveryClassRatherThanCountingClasses(): void
    {
        $parsed = new ParsedArchReportDto([
            self::FIRST  => [$this->violation('a'), $this->violation('b')],
            self::SECOND => [$this->violation('c')],
        ]);

        self::assertSame(3, $parsed->violationCount(), 'two classes can carry three violations between them');
    }

    #[Test]
    public function theClassOrderIsTheAnalysersOwn(): void
    {
        $parsed = new ParsedArchReportDto([self::SECOND => [$this->violation('c')], self::FIRST => [$this->violation('a')]]);

        self::assertSame([self::SECOND, self::FIRST], array_keys($parsed->classes));
    }

    private function violation(string $message): FileErrorDto
    {
        return new FileErrorDto(null, $message, null, null);
    }
}
