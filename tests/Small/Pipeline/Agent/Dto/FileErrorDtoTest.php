<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Agent\Dto;

use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FileErrorDto::class)]
#[Small]
final class FileErrorDtoTest extends TestCase
{
    #[Test]
    public function everyDocumentedKeyIsPresentInTheDocumentedOrder(): void
    {
        $error = new FileErrorDto(89, 'Call to static method allClasses() on an unknown class.', 'class.notFound', 'Learn more at https://phpstan.org/');

        self::assertSame(
            ['line', 'message', 'identifier', 'tip'],
            array_keys($error->jsonSerialize()),
        );
    }

    #[Test]
    public function theValuesSurviveSerialisation(): void
    {
        $error = new FileErrorDto(89, 'boom', 'class.notFound', 'a tip');

        self::assertSame(
            ['line' => 89, 'message' => 'boom', 'identifier' => 'class.notFound', 'tip' => 'a tip'],
            $error->jsonSerialize(),
        );
    }

    #[Test]
    public function aFileLevelErrorWithNoLineOrIdentifierSerialisesAsNullNeverAsZeroOrEmptyString(): void
    {
        $error = new FileErrorDto(null, 'Ignored error pattern was not matched.', null, null);

        self::assertSame(
            ['line' => null, 'message' => 'Ignored error pattern was not matched.', 'identifier' => null, 'tip' => null],
            $error->jsonSerialize(),
        );
        self::assertSame(
            '{"line":null,"message":"Ignored error pattern was not matched.","identifier":null,"tip":null}',
            \Safe\json_encode($error),
        );
    }
}
