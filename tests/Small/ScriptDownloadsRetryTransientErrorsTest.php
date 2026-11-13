<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Defence for the class "a one-shot download in a script a scheduled job depends on".
 *
 * A server answering 504 for a few seconds failed the daily dependency update,
 * because the script that fetches Box asked once. `curl --retry` retries the
 * transient HTTP errors (408, 429, 500, 502, 503, 504) with a growing wait, so
 * every `curl` the repository's scripts and workflows run must carry it.
 *
 * @internal
 */
#[CoversNothing]
#[Small]
final class ScriptDownloadsRetryTransientErrorsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /** @var array<string, string> directory => file pattern, both relative to the project root */
    private const array SCANNED = [
        'scripts'                  => '*',
        'scripts/lib'              => '*',
        'git-hooks'                => '*',
        '.github/workflows'        => '*.y*ml',
        'templates/github-actions' => '*.y*ml',
        '.claude/ccy'              => 'Dockerfile*',
    ];

    private const string CALL_PATTERN = '/(?:^|[\s|&;(!`])curl\s+(?:-|["\']?https?:)/';

    // `--retry` followed by a count of at least one: `--retry 0` and `--retry-delay` retry nothing.
    private const string RETRY_PATTERN = '/--retry\s+[1-9]/';

    #[Test]
    public function everyDownloadInAScriptOrWorkflowRetriesATransientError(): void
    {
        $withoutRetry = [];
        foreach ($this->scannedFiles() as $path) {
            foreach ($this->callsWithoutRetry(\Safe\file_get_contents($path)) as $call) {
                $withoutRetry[] = substr($path, \strlen(self::ROOT) + 1) . ': ' . $call;
            }
        }

        self::assertSame([], $withoutRetry, 'add `--retry 5` to each curl call listed');
    }

    #[Test]
    public function theScanCoversTheScriptThatFetchesBox(): void
    {
        self::assertContains(self::ROOT . '/scripts/build-phar.bash', $this->scannedFiles());
    }

    #[Test]
    public function aCurlWithoutRetryIsFound(): void
    {
        self::assertSame(
            ['curl -fSL -o "$CACHE" https://example.test/box.phar'],
            $this->callsWithoutRetry("set -e\ncurl -fSL -o \"\$CACHE\" https://example.test/box.phar\n"),
        );
    }

    #[Test]
    public function theScanCoversAnExtensionlessScriptAndAYamlWorkflow(): void
    {
        $files = $this->scannedFiles();

        self::assertContains(self::ROOT . '/scripts/issue-monitor', $files);
        self::assertSame([], array_filter($files, static fn (string $path): bool => str_ends_with($path, '.md')));
    }

    #[Test]
    public function aRetryThatRetriesNothingDoesNotCount(): void
    {
        self::assertCount(1, $this->callsWithoutRetry("curl -fSL --retry 0 https://example.test/a\n"));
        self::assertCount(1, $this->callsWithoutRetry("curl -fSL --retry-delay 3 https://example.test/a\n"));
        self::assertCount(1, $this->callsWithoutRetry("curl -fSL --retry https://example.test/a\n"));
    }

    #[Test]
    public function aCurlWhoseRetryIsOnAContinuationLineIsAccepted(): void
    {
        self::assertSame([], $this->callsWithoutRetry("curl -fSL --retry 5 -o \"\$CACHE\" \\\n    https://example.test/box.phar\n"));
        self::assertSame([], $this->callsWithoutRetry("curl -fSL \\\n    --retry 5 \\\n    https://example.test/box.phar\n"));
    }

    #[Test]
    public function aCurlInsideAConditionOrAPipeIsFound(): void
    {
        self::assertCount(1, $this->callsWithoutRetry("if ! curl -fSL https://example.test/a; then exit 1; fi\n"));
        self::assertCount(1, $this->callsWithoutRetry("      - run: curl -fsSL https://example.test/a | bash\n"));
    }

    #[Test]
    public function aCommentOrAMessageThatMentionsCurlIsNotACall(): void
    {
        self::assertSame([], $this->callsWithoutRetry("# curl -fSL https://example.test/a\n"));
        self::assertSame([], $this->callsWithoutRetry("echo \"run curl -fSL https://example.test/a\"\n"));
        self::assertSame([], $this->callsWithoutRetry("      - name: Install curl and jq\n"));
    }

    /** @return list<string> */
    private function scannedFiles(): array
    {
        $files = [];
        foreach (self::SCANNED as $directory => $pattern) {
            foreach (\Safe\glob(self::ROOT . '/' . $directory . '/' . $pattern) as $path) {
                if (\is_string($path) && is_file($path) && !str_ends_with($path, '.md')) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    /** @return list<string> every logical line that calls curl without `--retry` */
    private function callsWithoutRetry(string $contents): array
    {
        $calls = [];
        foreach ($this->logicalLines($contents) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '#') || str_starts_with($trimmed, 'echo') || str_starts_with($trimmed, 'printf')) {
                continue;
            }

            if (1 === \Safe\preg_match(self::CALL_PATTERN, $line) && 1 !== \Safe\preg_match(self::RETRY_PATTERN, $line)) {
                $calls[] = $trimmed;
            }
        }

        return $calls;
    }

    /** @return list<string> the lines, with each backslash continuation joined to its first line */
    private function logicalLines(string $contents): array
    {
        $lines   = [];
        $current = '';
        foreach (explode("\n", $contents) as $line) {
            if (str_ends_with($line, '\\')) {
                $current .= rtrim(substr($line, 0, -1)) . ' ';

                continue;
            }

            $lines[] = $current . $line;
            $current = '';
        }

        return $lines;
    }
}
