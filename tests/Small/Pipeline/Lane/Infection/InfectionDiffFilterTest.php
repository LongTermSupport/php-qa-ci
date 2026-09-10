<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline\Lane\Infection;

use LTS\PHPQA\Pipeline\Lane\Infection\Dto\InfectionDiffFilterDto;
use LTS\PHPQA\Pipeline\Lane\Infection\InfectionDiffFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InfectionDiffFilter::class)]
#[UsesClass(InfectionDiffFilterDto::class)]
#[Small]
final class InfectionDiffFilterTest extends TestCase
{
    #[Test]
    public function theGitDiffIsAThreeDotDiffOfCommittedHistoryRelativeToTheCwd(): void
    {
        $args = new InfectionDiffFilter()->gitDiffArguments('origin/main', '/p/src');

        self::assertSame(['--no-pager', 'diff', 'origin/main...HEAD', '--diff-filter=AM', '--name-only', '--relative', '--', '/p/src'], $args);
    }

    #[Test]
    public function theFilterIsExactlyTheCommittedChangeAbsolutisedAgainstTheCwd(): void
    {
        $filter = new InfectionDiffFilter()->fromGitDiffOutput("src/Committed.php\n", '/p');

        self::assertSame(['/p/src/Committed.php'], $filter->positionalPaths);
        self::assertSame('src/Committed.php', $filter->display());
        self::assertFalse($filter->isEmpty());
    }

    #[Test]
    public function onlyPhpFilesSurviveAndTheDisplayListIsCommaJoined(): void
    {
        $filter = new InfectionDiffFilter()->fromGitDiffOutput("src/A.php\nsrc/notes.md\n\nsrc/Deep/B.php\r\nsrc/c.phtml\n", '/p');

        self::assertSame(['/p/src/A.php', '/p/src/Deep/B.php'], $filter->positionalPaths);
        self::assertSame('src/A.php,src/Deep/B.php', $filter->display());
    }

    #[Test]
    public function anEmptyDiffIsEmpty(): void
    {
        $filter = new InfectionDiffFilter()->fromGitDiffOutput("\n", '/p');

        self::assertTrue($filter->isEmpty());
        self::assertSame('', $filter->display());
        self::assertSame([], $filter->positionalPaths);
    }
}
