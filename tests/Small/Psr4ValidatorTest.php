<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use LTS\PHPQA\Helper;
use LTS\PHPQA\Psr4Validator;
use PHPUnit\Framework\TestCase;

/**
 * Class Psr4ValidatorTest.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Psr4Validator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(Helper::class)]
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

    public function testIgnoredFilesAloneDoNotProduceErrorsAndAreNotReported(): void
    {
        // A project that is entirely valid but whose ignore patterns match some
        // files. The scanner records those as "ignored" internally, but that
        // debug info is DELIBERATELY suppressed when there are no real errors —
        // main() returns early with an empty result the moment the error set is
        // empty, so a clean run never leaks the "Ignored Files:" block.
        $assetsPath  = __DIR__ . '/../assets/psr4/projectAllValid/';
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            ['%Nested/Deep%'],
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . '/composer.json')
        );

        self::assertSame([], $validator->main());
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
            'PSR-4 Errors:'  => [
                'In\Valid\\' => [
                    0 => [
                        'fileInfo'          => $projectRoot . '/src/Nested/Deep/Bad.php',
                        'expectedNamespace' => 'In\Valid\Nested\Deep',
                        'actualNamespace'   => 'So',
                    ],
                    1 => [
                        'fileInfo'          => $projectRoot . '/src/Wrong.php',
                        'expectedNamespace' => 'In\Valid',
                        'actualNamespace'   => 'Totally',
                    ],
                ],
            ],
            'Parse Errors:'  => [
                0 => $projectRoot . '/tests/ParseError.php',
            ],
            'Missing Paths:' => [
                'missing/path'         => 'Namespace root \'In\Valid\\\'
contains a path \'missing/path\'
which doesn\'t exist
',
                'missing/magento/path' => 'Namespace root \'In\Valid\\\'
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

    /**
     * Regression: php-qa-ci's own convention maps `qaConfig/` as a PSR-4 root
     * (QaConfig\, for custom PHPStan rules) AND ships non-class config files
     * there that projects override — qaConfig/phparkitect.php,
     * qaConfig/php_cs.php and qaConfig/php_cs_finder.php (the documented CS
     * Fixer Finder override, docs/coding-standards.md). Those legitimately
     * have no namespace, so the SHIPPED default ignore list must exclude them;
     * otherwise the re-enabled PSR-4 gate falsely reports them as Parse Errors
     * on every consuming project.
     *
     * The fixture also contains a correctly-namespaced rule class under the
     * same root (qaConfig/PHPStan/Rules/GoodRule.php) to prove the exclusion is
     * scoped to the config files and does not blanket-skip the qaConfig tree.
     */
    public function testShippedDefaultIgnoreListExcludesQaConfigConfigFiles(): void
    {
        $assetsPath  = __DIR__ . '/../assets/psr4/projectQaConfig/';
        $projectRoot = \Safe\realpath($assetsPath);
        $validator   = new Psr4Validator(
            $this->loadShippedDefaultIgnoreList(),
            $projectRoot,
            Helper::getComposerJsonDecoded($projectRoot . '/composer.json')
        );

        self::assertSame([], $validator->main());
    }

    /**
     * The exact ignore patterns bin/qa feeds the validator in production: every
     * non-blank line of the shipped default list (see Psr4ValidateTool).
     *
     * @return list<string>
     */
    private function loadShippedDefaultIgnoreList(): array
    {
        $path     = __DIR__ . '/../../configDefaults/generic/psr4-validate-ignore-list.txt';
        $contents = \Safe\file_get_contents($path);

        $patterns = [];
        foreach (explode("\n", $contents) as $line) {
            if ('' !== trim($line)) {
                $patterns[] = $line;
            }
        }

        return $patterns;
    }
}
