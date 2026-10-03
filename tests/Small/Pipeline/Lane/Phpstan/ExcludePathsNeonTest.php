<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Config\IgnoredPaths;
use LTS\PHPQA\Pipeline\Lane\Phpstan\ExcludePathsNeon;
use Nette\Neon\Entity;
use Nette\Neon\Neon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ExcludePathsNeon::class)]
#[UsesClass(IgnoredPaths::class)]
#[Small]
final class ExcludePathsNeonTest extends TestCase
{
    #[Test]
    public function noIgnoredPathWritesNoBlock(): void
    {
        self::assertSame('', new ExcludePathsNeon()->parameters(new IgnoredPaths('/project')));
    }

    #[Test]
    public function eachIgnoredPathIsAnOptionalAbsoluteAnalyseExclusion(): void
    {
        $neon = new ExcludePathsNeon()->parameters(new IgnoredPaths('/project', 'tests/assets', "it's here"));

        self::assertSame(
            "    excludePaths:\n        analyse:\n            - '/project/tests/assets' (?)\n            - '/project/it''s here' (?)\n",
            $neon,
        );

        $decoded = Neon::decode("parameters:\n" . $neon);
        self::assertIsArray($decoded);
        self::assertEquals(
            ['parameters' => ['excludePaths' => ['analyse' => [new Entity('/project/tests/assets', ['?']), new Entity("/project/it's here", ['?'])]]]],
            $decoded,
            'each entry is the path, marked optional, under analyse only',
        );
    }
}
