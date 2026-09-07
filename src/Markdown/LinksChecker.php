<?php

declare(strict_types=1);

namespace LTS\PHPQA\Markdown;

use Exception;
use LTS\PHPQA\Helper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use RuntimeException;
use Throwable;

final readonly class LinksChecker
{
    /**
     * @throws Exception
     */
    public static function main(?string $projectRootDirectory = null): int
    {
        $return               = 0;
        $projectRootDirectory ??= Helper::getProjectRootDirectory();
        $files                = self::getFiles($projectRootDirectory);
        foreach ($files as $file) {
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
     * @return string[]
     */
    private static function getFiles(string $projectRootDirectory): array
    {
        $files   = self::getDocsFiles($projectRootDirectory);
        $files[] = self::getMainReadme($projectRootDirectory);

        return $files;
    }

    /**
     * @return string[]
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

        return $files;
    }

    private static function getMainReadme(string $projectRootDirectory): string
    {
        $path = $projectRootDirectory . '/README.md';
        if (!is_file($path)) {
            throw new RuntimeException(
                "\n\nYou have no README.md file in your project"
                . "\n\nAs the bear minimum you need to have this file to pass QA"
            );
        }

        return $path;
    }

    /**
     * @return array<array<string>>
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
            /** @var array<array<string>> $matches */
            $links = array_merge($links, $matches);
        }

        return $links;
    }

    /**
     * @param string[] $link
     * @param string[] $errors
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
                '%^' . $projectRootDirectory . '%',
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
     * @param string[] $link
     * @param string[] $errors
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

        $result = self::fetchLinkStatus($href, $githubToken);
        if (null === $result) {
            return;
        }

        $errors[] = \sprintf(
            "\nBad link for \"%s\" to \"%s\"\nresult: %s\n",
            $anchor,
            $href,
            $result
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

    private static function fetchLinkStatus(string $href, ?string $githubToken = null): ?string
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
            'method'           => 'HEAD',
            'protocol_version' => 1.1,
            'follow_location'  => true,
            'max_redirects'    => 5,
            'timeout'          => 15,
            'header'           => $requestHeaders,
            'user_agent'       => $userAgent,
        ];

        $lastError = null;
        foreach (['HEAD', 'GET'] as $method) {
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
                $lastStatus = self::getLastStatusCode($headers);
                if (null !== $lastStatus && $lastStatus >= 200 && $lastStatus < 400) {
                    return null;
                }

                if ('HEAD' === $method && null !== $lastStatus && $lastStatus >= 400) {
                    continue;
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                continue;
            }
        }

        return 'HTTP status: ' . ($lastStatus ?? $lastError ?? 'connection failed');
    }

    /**
     * @param array<string> $headers
     */
    private static function getLastStatusCode(array $headers): ?int
    {
        $lastStatus = null;
        foreach ($headers as $header) {
            $matches = [];
            if (1 === \Safe\preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $matches) && isset($matches[1])) {
                $lastStatus = (int)$matches[1];
            }
        }

        return $lastStatus;
    }
}
