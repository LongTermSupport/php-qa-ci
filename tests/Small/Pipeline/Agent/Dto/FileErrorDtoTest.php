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
    private const string NOT_FOUND = 'class.notFound';

    private const string MESSAGE = 'boom';

    private const string TIP = 'a tip';

    #[Test]
    public function everyDocumentedKeyIsPresentInTheDocumentedOrder(): void
    {
        $error = new FileErrorDto(89, self::MESSAGE, self::NOT_FOUND, self::TIP);

        self::assertSame(
            '{"line":89,"message":"boom","identifier":"class.notFound","tip":"a tip"}',
            \Safe\json_encode($error),
            'the key order is the wire contract a consumer reads, so it is pinned on the encoded form',
        );
    }

    #[Test]
    public function theValuesSurviveSerialisation(): void
    {
        $error = new FileErrorDto(89, self::MESSAGE, self::NOT_FOUND, self::TIP);

        self::assertSame(
            ['line' => 89, 'message' => self::MESSAGE, 'identifier' => self::NOT_FOUND, 'tip' => self::TIP],
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
