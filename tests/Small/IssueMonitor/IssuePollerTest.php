<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\IssueMonitor;

use LTS\PHPQA\IssueMonitor\IssuePoller;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessResultDto;
use LTS\PHPQA\Pipeline\Process\Dto\ProcessSpecDto;
use LTS\PHPQA\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(IssuePoller::class)]
#[UsesClass(ProcessResultDto::class)]
#[UsesClass(ProcessSpecDto::class)]
#[Small]
final class IssuePollerTest extends TestCase
{
    private const string CLI = '/p/.claude/hooks-daemon/bin/hooks-daemon';

    private const string REPO = 'LongTermSupport/php-qa-ci';

    private const string AUTHOR = 'ballidev';

    private const string ELIGIBLE_1 = '{"eligible":[1]}';

    private FakeProcessRunner $processes;

    protected function setUp(): void
    {
        $this->processes = new FakeProcessRunner();
    }

    #[Test]
    public function theEligibleIssuesAreThoseTheDaemonReportsForTheApprovedAuthors(): void
    {
        $this->processes->willSucceed('{"eligible":[104,102,7],"skipped":1}');

        self::assertSame([104 => true, 102 => true, 7 => true], $this->poller()->eligible());
        self::assertSame(
            [self::CLI, 'issue-validity', '--list-eligible', '--json', '--project-root', '/p'],
            $this->processes->lastSpec()->command,
        );
    }

    #[Test]
    public function aNewIssueIsEligibleUnassignedAndNeitherBacklogNorAlreadyAnnounced(): void
    {
        $this->processes
            ->willSucceed('{"eligible":[110,111,112,113,114],"skipped":1}')
            ->willSucceed(\Safe\json_encode([
                $this->issue(115, 'stranger'),
                $this->issue(114, self::AUTHOR),
                $this->issue(113, self::AUTHOR, 'lts-bob'),
                $this->issue(112, 'LTSCommerce'),
                $this->issue(111, self::AUTHOR),
                $this->issue(110, self::AUTHOR),
            ]))
        ;

        $new = $this->poller()->newIssues(backlog: [110 => true], announced: [111 => true]);

        self::assertSame(
            [['number' => 112, 'author' => 'LTSCommerce', 'title' => 'Issue 112'], ['number' => 114, 'author' => self::AUTHOR, 'title' => 'Issue 114']],
            $new,
            '115 has an unapproved author, 113 is claimed, 110 is backlog and 111 was announced already',
        );
        self::assertSame(
            ['gh', 'issue', 'list', '--repo', self::REPO, '--state', 'open', '--limit', '500', '--json', 'number,title,author,assignees'],
            $this->processes->lastSpec()->command,
        );
    }

    #[Test]
    public function aFailedDaemonCallIsAPollFailureNamingTheCommand(): void
    {
        $this->processes->willFail(4, 'refusing to judge issues: no approved_issue_authors configured');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('issue-validity --list-eligible --json --project-root /p failed (exit 4): refusing to judge issues');

        $this->poller()->eligible();
    }

    #[Test]
    public function aFailedGitHubCallIsAPollFailure(): void
    {
        $this->processes->willSucceed(self::ELIGIBLE_1)->willFail(1, 'HTTP 502');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('gh issue list');

        $this->poller()->newIssues(backlog: [], announced: []);
    }

    #[Test]
    public function anAnswerThatIsNotTheExpectedJsonIsAPollFailure(): void
    {
        $this->processes->willSucceed('{"something":"else"}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('unexpected answer');

        $this->poller()->eligible();
    }

    #[Test]
    public function anEligibleEntryThatIsNotAnIssueNumberIsAPollFailure(): void
    {
        $this->processes->willSucceed('{"eligible":["7"]}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('issue-validity --list-eligible gave an unexpected answer');

        $this->poller()->eligible();
    }

    #[Test]
    public function anIssueListThatIsNotAListIsAPollFailure(): void
    {
        $this->processes->willSucceed(self::ELIGIBLE_1)->willSucceed('"rate limited"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('gh issue list gave an unexpected answer');

        $this->poller()->newIssues(backlog: [], announced: []);
    }

    #[Test]
    public function anAnswerThatIsNotJsonIsAPollFailureNamingTheCommand(): void
    {
        $this->processes->willSucceed('<html>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('issue-validity --list-eligible --json --project-root /p gave an unexpected answer: ');

        $this->poller()->eligible();
    }

    #[Test]
    public function anIssueListEntryWithoutANumberIsAPollFailure(): void
    {
        $this->processes->willSucceed(self::ELIGIBLE_1)->willSucceed('[{"title":"x"}]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('unexpected answer');

        $this->poller()->newIssues(backlog: [], announced: []);
    }

    private function poller(): IssuePoller
    {
        return new IssuePoller($this->processes, self::CLI, '/p', self::REPO);
    }

    /** @return array<string, mixed> */
    private function issue(int $number, string $author, string ...$assignees): array
    {
        return [
            'number'    => $number,
            'title'     => 'Issue ' . $number,
            'author'    => ['login' => $author],
            'assignees' => array_map(static fn (string $login): array => ['login' => $login], array_values($assignees)),
        ];
    }
}
