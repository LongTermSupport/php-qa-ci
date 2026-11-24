<?php

declare(strict_types=1);

namespace LTS\PHPQA\Markdown;

use Exception;
use LTS\PHPQA\Filesystem\UnresolvablePath;
use LTS\PHPQA\Helper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use RuntimeException;
use Throwable;

final readonly class LinksChecker
{
    private const string HEAD = 'HEAD';

    private const string NOT_BROKEN = ' is not treated as a broken link';

    /**
     * @throws Exception
     */
    public static function main(?string $projectRootDirectory = null): int
    {
        $return               = 0;
        $projectRootDirectory ??= Helper::getProjectRootDirectory();
        $files                = self::getFiles($projectRootDirectory);
        foreach ($files as $file) {
            if (UnresolvablePath::is($file)) {
                echo UnresolvablePath::skipped('Markdown links', $file, $projectRootDirectory) . PHP_EOL;

                continue;
            }

            $relativeFile = str_replace($projectRootDirectory, '', $file);
            $title        = PHP_EOL . $relativeFile . PHP_EOL . str_repeat('-', \strlen($relativeFile)) . "\n";
            $errors       = [];
            $links        = self::getLinks($file);
            foreach ($links as $link) {
                self::checkLink($projectRootDirectory, $link, $file, $errors, $return);
            }

            if ([] !== $errors) {
                echo $title . implode('', $errors);
            }
        }

        return $return;
    }

    /**
     * @return list<string>
     */
    private static function getFiles(string $projectRootDirectory): array
    {
        $files   = self::getDocsFiles($projectRootDirectory);
        $files[] = self::getMainReadme($projectRootDirectory);

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function getDocsFiles(string $projectRootDirectory): array
    {
        $files = [];
        $dir   = $projectRootDirectory . '/docs';
        if (!is_dir($dir)) {
            return $files;
        }

        $directory = new RecursiveDirectoryIterator($dir);
        $recursive = new RecursiveIteratorIterator($directory);
        $regex     = new RegexIterator(
            $recursive,
            '/^.+\.md/i',
            RecursiveRegexIterator::GET_MATCH
        );
        foreach ($regex as $file) {
            /** @var array<int, string> $file */
            if (isset($file[0]) && '' !== $file[0]) {
                $files[] = $file[0];
            }
        }

        sort($files);

        return $files;
    }

    private static function getMainReadme(string $projectRootDirectory): string
    {
        $path = $projectRootDirectory . '/README.md';
        if (UnresolvablePath::is($path)) {
            throw new RuntimeException(
                "\n\nREADME.md is a symlink whose target does not exist, so its real path cannot be resolved"
                . "\n\nPoint it at an existing file, or replace it with one, to pass QA"
            );
        }

        if (!is_file($path)) {
            throw new RuntimeException(
                "\n\nYou have no README.md file in your project"
                . "\n\nAs the bear minimum you need to have this file to pass QA"
            );
        }

        return $path;
    }

    /**
     * @return list<array<int|string, string>> one match set per link: [whole, text, target]
     */
    private static function getLinks(string $file): array
    {
        $links    = [];
        $contents = \Safe\file_get_contents($file);
        $matches  = [];
        if (
            0 !== \Safe\preg_match_all(
                '/\[([^\]]+)\]\(([^)]+)\)/',
                $contents,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            /** @var list<array<int|string, string>> $matches */
            $links = array_merge($links, $matches);
        }

        return $links;
    }

    /**
     * @param array<int|string, string> $link
     * @param list<string>              $errors
     */
    private static function checkLink(
        string $projectRootDirectory,
        array $link,
        string $file,
        array &$errors,
        int &$return
    ): void {
        $path = trim($link[2]);
        if (str_starts_with($path, '#')) {
            return;
        }

        if (1 === \Safe\preg_match('%^(http|//)%', $path)) {
            self::validateHttpLink($link, $errors, $return);

            return;
        }

        $path  = current(explode('#', $path, 2));
        $start = rtrim($projectRootDirectory, '/');
        if ('/' !== $path[0] || str_starts_with($path, './')) {
            $relativeSubdirs = \Safe\preg_replace(
                '%^' . preg_quote($projectRootDirectory, '%') . '%',
                '',
                \dirname($file)
            );
            if (\is_string($relativeSubdirs)) {
                $start .= '/' . rtrim($relativeSubdirs, '/');
            }
        }

        if (!file_exists($start . '/' . $path)) {
            $errors[] = \sprintf("\nBad link for \"%s\" to \"%s\"\n", $link[1], $link[2]);
            $return   = 1;
        }
    }

    /**
     * @param array<int|string, string> $link
     * @param list<string>              $errors
     */
    private static function validateHttpLink(array $link, array &$errors, int &$return): void
    {
        /** @var array<string, true> $checked */
        static $checked    = [];
        [, $anchor, $href] = $link;
        $hashPos           = (int)strpos($href, '#');
        if ($hashPos > 0) {
            $href = substr($href, 0, $hashPos);
        }

        if (isset($checked[$href])) {
            return;
        }

        $checked[$href] = true;

        $githubToken = null;
        if (self::isGitHubOwnedHost($href)) {
            $githubToken = self::getGitHubToken();
            if (null === $githubToken) {
                // github.com returns 404 for private repos when unauthenticated,
                // which is indistinguishable from a genuinely missing page. With
                // no token we cannot verify the URL, so skip it (do not fail) and
                // emit a clear notice.
                $errors[] = \sprintf(
                    "\nSkipped link check for \"%s\" to \"%s\"\n"
                    . 'reason: GitHub URLs cannot be verified anonymously'
                    . " (private repos return 404); set GH_TOKEN or GITHUB_TOKEN to enable checking.\n",
                    $anchor,
                    $href
                );

                return;
            }
        }

        $finding = self::fetchLinkStatus($href, $githubToken);
        if (null === $finding) {
            return;
        }

        if (!$finding['broken']) {
            $errors[] = \sprintf(
                "\nWarning for \"%s\" to \"%s\"\nresult: %s\n",
                $anchor,
                $href,
                $finding['result']
            );

            return;
        }

        $errors[] = \sprintf(
            "\nBad link for \"%s\" to \"%s\"\nresult: %s\n",
            $anchor,
            $href,
            $finding['result']
        );
        $return   = 1;
    }

    /**
     * Is the URL hosted on a GitHub-owned domain that cannot be verified
     * anonymously? Only the real host is considered (parsed via parse_url), so a
     * non-GitHub host with "github.com" elsewhere in the URL is not matched.
     */
    private static function isGitHubOwnedHost(string $href): bool
    {
        if (false === filter_var($href, FILTER_VALIDATE_URL)) {
            // A URL malformed enough that it does not validate is not a GitHub
            // host; let the normal HTTP check report it.
            return false;
        }

        $host = \Safe\parse_url($href, PHP_URL_HOST);

        if (!\is_string($host) || '' === $host) {
            return false;
        }

        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (\in_array($host, ['github.com', 'gist.github.com'], true)) {
            return true;
        }

        // raw.githubusercontent.com, objects.githubusercontent.com, etc.
        return str_ends_with($host, '.githubusercontent.com') || 'githubusercontent.com' === $host;
    }

    private static function getGitHubToken(): ?string
    {
        foreach (['GH_TOKEN', 'GITHUB_TOKEN'] as $envVar) {
            $token = getenv($envVar);
            if (\is_string($token) && '' !== trim($token)) {
                return trim($token);
            }
        }

        return null;
    }

    /**
     * Only a page the server says is gone is a broken link. Anything else that is not a clean
     * answer (a server error, a refusal to say, no connection, a redirect) is a warning: the
     * page may be fine, and a host being down must not fail a run.
     *
     * @return array{broken: bool, result: string}|null null when the link answers 2xx directly
     */
    private static function fetchLinkStatus(string $href, ?string $githubToken = null): ?array
    {
        $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36'
                     . ' (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
        $requestHeaders = [
            'Connection: close',
            'Accept: text/html,application/xhtml+xml,*/*',
            'Accept-Language: en-US,en;q=0.9',
        ];
        if (null !== $githubToken) {
            // Authenticate so private GitHub repos resolve correctly and genuine
            // 404s are still reported.
            $requestHeaders[] = 'Authorization: Bearer ' . $githubToken;
        }

        $httpOpts = [
            'method'           => self::HEAD,
            'protocol_version' => 1.1,
            'follow_location'  => true,
            'max_redirects'    => 5,
            'timeout'          => 15,
            'header'           => $requestHeaders,
            'user_agent'       => $userAgent,
        ];

        $lastError  = null;
        $lastStatus = null;
        foreach ([self::HEAD, 'GET'] as $method) {
            $httpOpts['method'] = $method;
            $context            = stream_context_create([
                'http'  => $httpOpts,
                'https' => $httpOpts,
                'ssl'   => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ]);
            try {
                $headers = @\Safe\get_headers($href, false, $context);
                /** @var list<string> $headers */
                $statuses   = self::getStatusCodes(...$headers);
                $lastStatus = [] === $statuses ? null : array_last($statuses);
                if (null !== $lastStatus && $lastStatus >= 200 && $lastStatus < 300) {
                    return self::redirectFinding($statuses, ...$headers);
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        return self::unansweredFinding($lastStatus, $lastError);
    }

    /**
     * @return array{broken: bool, result: string}
     */
    private static function unansweredFinding(?int $status, ?string $error): array
    {
        if (null === $status) {
            return [
                'broken' => false,
                'result' => ($error ?? 'connection failed') . ', so the link could not be checked and'
                    . self::NOT_BROKEN,
            ];
        }

        if (404 === $status || 410 === $status) {
            return ['broken' => true, 'result' => 'HTTP status: ' . $status];
        }

        $reason = match (true) {
            $status >= 500 => ', a server error',
            $status >= 400 => ', the server would not say whether the page exists, so it',
            default        => ', redirects that did not end on a page, so it',
        };

        return ['broken' => false, 'result' => 'HTTP status: ' . $status . $reason . self::NOT_BROKEN];
    }

    /**
     * The warning for a link that answered 2xx only after a redirect, or null when it answered 2xx directly.
     *
     * @param list<int> $statuses the status of every response on the way, redirects first
     *
     * @return array{broken: bool, result: string}|null
     */
    private static function redirectFinding(array $statuses, string ...$headers): ?array
    {
        if (\count($statuses) < 2) {
            return null;
        }

        $location = '';
        foreach ($headers as $header) {
            $matches = [];
            if (1 === \Safe\preg_match('/^Location:\s*(\S+)/i', $header, $matches) && isset($matches[1])) {
                $location = $matches[1];
            }
        }

        return [
            'broken' => false,
            'result' => \sprintf(
                'HTTP status: %d, redirected to %s; update the link to the address it ends on',
                $statuses[0],
                $location,
            ),
        ];
    }

    /**
     * @return list<int> the status of every response in the chain, in order
     */
    private static function getStatusCodes(string ...$headers): array
    {
        $statuses = [];
        foreach ($headers as $header) {
            $matches = [];
            if (1 === \Safe\preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $matches) && isset($matches[1])) {
                $statuses[] = (int)$matches[1];
            }
        }

        return $statuses;
    }
}
