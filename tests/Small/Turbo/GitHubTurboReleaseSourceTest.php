<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Turbo;

use LTS\PHPQA\Turbo\GitHubTurboReleaseSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The URLs the release source asks GitHub for, the headers it sends, and what a failed request
 * leaves: a null for the caller and the cause on the error output. The requests are answered here
 * in place of GitHub.
 *
 * @internal
 */
#[CoversClass(GitHubTurboReleaseSource::class)]
#[Small]
final class GitHubTurboReleaseSourceTest extends TestCase
{
    private const string TOKEN_VARIABLE = 'GITHUB_AUTH_TOKEN';

    private const string BODY = 'the body';

    private const string TAG = '2.3.1';

    private BufferedOutput $errors;

    /** @var list<array{string, list<string>}> every request made, with its headers, in order */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->errors = new BufferedOutput();
    }

    #[Test]
    public function theTreeOfATagIsReadFromTheGitTreesApi(): void
    {
        self::assertSame(self::BODY, $this->source(self::BODY)->tree(self::TAG));
        self::assertSame('https://api.github.com/repos/phpstan/phpstan/git/trees/2.3.1?recursive=1', $this->requests[0][0]);
        self::assertSame('', $this->errors->fetch());
    }

    #[Test]
    public function aFileIsReadFromTheTagsTurboDirectoryWithEachSegmentEncoded(): void
    {
        self::assertSame(self::BODY, $this->source(self::BODY)->file('2.3.1+x', 'linux x86/turbo.so'));
        self::assertSame('https://raw.githubusercontent.com/phpstan/phpstan/2.3.1%2Bx/turbo-ext/linux%20x86/turbo.so', $this->requests[0][0]);
    }

    #[Test]
    public function aTagIsEncodedInTheTreeUrl(): void
    {
        $this->source(self::BODY)->tree('2.3.1+x');

        self::assertSame('https://api.github.com/repos/phpstan/phpstan/git/trees/2.3.1%2Bx?recursive=1', $this->requests[0][0]);
    }

    #[Test]
    public function aFailedRequestIsNullAndItsCauseIsNoted(): void
    {
        self::assertNull($this->source(new RuntimeException('HTTP 403 rate limited'))->tree(self::TAG));
        self::assertSame(
            "Note: could not fetch https://api.github.com/repos/phpstan/phpstan/git/trees/2.3.1?recursive=1: HTTP 403 rate limited\n",
            $this->errors->fetch(),
        );
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('tokens')]
    public function eachRequestNamesItsClientAndCarriesTheTokenWhenOneIsSet(false|string $token, array $expected): void
    {
        $original = getenv(self::TOKEN_VARIABLE);
        \Safe\putenv(false === $token ? self::TOKEN_VARIABLE : self::TOKEN_VARIABLE . '=' . $token);

        try {
            $this->source(self::BODY)->file(self::TAG, 'turbo.so');
        } finally {
            \Safe\putenv(false === $original ? self::TOKEN_VARIABLE : self::TOKEN_VARIABLE . '=' . $original);
        }

        self::assertSame($expected, $this->requests[0][1]);
    }

    /** @return iterable<string, array{false|string, list<string>}> */
    public static function tokens(): iterable
    {
        $anonymous = ['User-Agent: php-qa-ci', 'Accept: application/vnd.github+json'];

        yield 'no token' => [false, $anonymous];
        yield 'an empty token' => ['', $anonymous];
        yield 'a token' => ['a-token', [...$anonymous, 'Authorization: Bearer a-token']];
    }

    /** The source, every request answered with $response: a string is the body, an exception the failure. */
    private function source(string|RuntimeException $response): GitHubTurboReleaseSource
    {
        return new GitHubTurboReleaseSource(
            $this->errors,
            function (string $url, string ...$headers) use ($response): string {
                $this->requests[] = [$url, array_values($headers)];

                return \is_string($response) ? $response : throw $response;
            },
        );
    }
}
