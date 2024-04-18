<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Helper;
use LTS\PHPQA\Psr4Validator;
use PHPUnit\Framework\TestCase;

/**
 * Class Psr4ValidatorTest.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Psr4Validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(Helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(Psr4Validator::class)]
#[\PHPUnit\Framework\Attributes\Small]
final class Psr4ValidatorTest extends TestCase
{
    public function testItFindsNoErrorsOnAValidProject(): void
    {
        $assetsPath  = __DIR__ . '/../assets/psr4/projectAllValid/';
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            [],
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . '/composer.json')
        );
        $actual   = $validator->main();
        $expected = [];
        self::assertSame($expected, $actual);
    }

    public function testItCanHandleOddComposerConfigs(): void
    {
        $assetsPath  = __DIR__ . '/../assets/psr4/projectOddComposer/';
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            [],
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . '/composer.json')
        );
        $actual   = $validator->main();
        $expected = [];
        self::assertSame($expected, $actual);
    }

    public function testItFindsErrorsAndThrowsAnExceptionOnAnInvalidProject(): void
    {
        $assetsPath  = __DIR__ . '/../assets/psr4/projectInValid/';
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            ['%IgnoredStuff%'],
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . '/composer.json')
        );
        $actual   = $validator->main();
        $expected = [
            'PSR-4 Errors:' => [
                'In\\Valid\\' => [
                    0 => [
                        'fileInfo'          => $projectRoot . '/src/Nested/Deep/Bad.php',
                        'expectedNamespace' => 'In\\Valid\\Nested\\Deep',
                        'actualNamespace'   => 'So',
                    ],
                    1 => [
                        'fileInfo'          => $projectRoot . '/src/Wrong.php',
                        'expectedNamespace' => 'In\\Valid',
                        'actualNamespace'   => 'Totally',
                    ],
                ],
            ],
            'Parse Errors:' => [
                0 => $projectRoot . '/tests/ParseError.php',
            ],
            'Missing Paths:' => [
                'missing/path' => 'Namespace root \'In\\Valid\\\'
contains a path \'missing/path\'
which doesn\'t exist
',
                'missing/magento/path' => 'Namespace root \'In\\Valid\\\'
contains a path \'missing/magento/path\'
which doesn\'t exist
Magento\'s composer includes this by default, it should be removed from the psr-4 section',
            ],
            'Ignored Files:' => [
                0 => $projectRoot . '/src/IgnoredStuff/Ignored.php',
                1 => $projectRoot . '/src/IgnoredStuff/InvalidIgnored.php',
            ],
        ];

        self::assertSame($expected, $actual);
    }
}
