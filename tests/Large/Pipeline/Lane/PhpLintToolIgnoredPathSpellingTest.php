<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\Pipeline\Lane;

use Iterator;
use LTS\PHPQA\Pipeline\Lane\PhpLintTool;
use LTS\PHPQA\Pipeline\Tool\ToolOutcomeEnum;
use LTS\PHPQA\Tests\Support\RealLaneContext;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Defence for the class "an ignored path is excluded only when it is spelt the way the tool
 * compares paths" (#479). parallel-lint matches each `--exclude` against the exact path its
 * walk yields, so an exclude spelt `<root>/./generated` matches nothing and a file under the
 * ignored path is still linted. Every other lane reads the ignored paths through
 * `IgnoredPaths`, which drops a leading `./` and a trailing slash.
 *
 * Each test runs the real lane, with the real parallel-lint phar, over a probe project whose
 * checked `src` directory holds a clean file and a file with a syntax error under the ignored
 * directory.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class PhpLintToolIgnoredPathSpellingTest extends TestCase
{
    private const string BROKEN = 'src/generated/Broken.php';

    private const string IGNORED = 'src/generated';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-lint-ignored-spelling');
        $this->project->write('src/Clean.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Probe;\n\nfinal class Clean\n{\n}\n");
        $this->project->write(self::BROKEN, "<?php\n\nfinal class Broken {\n");
        $this->project->mkdir('tests');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    #[DataProvider('spellingsOfTheIgnoredPath')]
    public function aSyntaxErrorUnderAnIgnoredPathIsNotLintedHoweverThePathIsSpelt(string $spelling): void
    {
        $output = new BufferedOutput();
        $result = new PhpLintTool()->run(RealLaneContext::over($this->project->path, true, $output, null, [$spelling]));

        self::assertSame(ToolOutcomeEnum::Passed, $result->outcome, $output->fetch());
    }

    /** @return Iterator<string, array{string}> */
    public static function spellingsOfTheIgnoredPath(): Iterator
    {
        yield 'plain'                           => [self::IGNORED];
        yield 'a trailing slash'                => [self::IGNORED . '/'];
        yield 'a leading dot and slash'         => ['./' . self::IGNORED];
        yield 'a leading dot and slash, a trailing slash' => ['./' . self::IGNORED . '/'];
    }

    #[Test]
    public function theSameSyntaxErrorOutsideAnyIgnoredPathFailsTheLane(): void
    {
        $output = new BufferedOutput();
        $result = new PhpLintTool()->run(RealLaneContext::over($this->project->path, true, $output));

        self::assertSame(ToolOutcomeEnum::Failed, $result->outcome, $output->fetch());
    }
}
