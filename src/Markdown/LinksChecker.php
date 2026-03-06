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

final class LinksChecker
{
    /**
     * @throws Exception
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public static function main(?string $projectRootDirectory = null): int
    {
        $return               = 0;
        $projectRootDirectory ??= Helper::getProjectRootDirectory();
        $files                = self::getFiles($projectRootDirectory);
        foreach ($files as $file) {
            $relativeFile = str_replace($projectRootDirectory, '', $file);
            $title        = "\n{$relativeFile}\n" . str_repeat('-', \strlen($relativeFile)) . "\n";
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
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private static function getFiles(string $projectRootDirectory): array
    {
        $files   = self::getDocsFiles($projectRootDirectory);
        $files[] = self::getMainReadme($projectRootDirectory);

        return $files;
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
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
        $regex = new RegexIterator(
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

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
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
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
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
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
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
        if (0 === strpos($path, '#')) {
            return;
        }
        if (1 === \Safe\preg_match('%^(http|//)%', $path)) {
            self::validateHttpLink($link, $errors, $return);

            return;
        }

        $path  = current(explode('#', $path, 2));
        $start = rtrim($projectRootDirectory, '/');
        if ('/' !== $path[0] || 0 === strpos($path, './')) {
            $relativeSubdirs = \Safe\preg_replace(
                '%^' . $projectRootDirectory . '%',
                '',
                \dirname($file)
            );
            if (\is_string($relativeSubdirs)) {
                $start .= '/' . rtrim($relativeSubdirs, '/');
            }
        }
        try {
            $realpath = \Safe\realpath($start . '/' . $path);
        } catch (Throwable) {
            $errors[] = sprintf("\nBad link for \"%s\" to \"%s\"\n", $link[1], $link[2]);
            $return   = 1;
        }
    }

    /**
     * @param string[] $link
     * @param string[] $errors
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) - seems to not understand the static variable
     */
    private static function validateHttpLink(array $link, array &$errors, int &$return): void
    {
        /** @var array<string, true> $checked */
        static $checked    = [];
        [, $anchor, $href] = $link;
        $hashPos           = (int) strpos($href, '#');
        if ($hashPos > 0) {
            $href = substr($href, 0, $hashPos);
        }
        if (isset($checked[$href])) {
            return;
        }
        $checked[$href] = true;

        $result = self::fetchLinkStatus($href);
        if (null === $result) {
            return;
        }

        $errors[] = sprintf(
            "\nBad link for \"%s\" to \"%s\"\nresult: %s\n",
            $anchor,
            $href,
            $result
        );
        $return   = 1;
    }

    private static function fetchLinkStatus(string $href): ?string
    {
        $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36'
                     . ' (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
        $httpOpts  = [
            'method'           => 'HEAD',
            'protocol_version' => 1.1,
            'follow_location'  => true,
            'max_redirects'    => 5,
            'timeout'          => 15,
            'header'           => [
                'Connection: close',
                'Accept: text/html,application/xhtml+xml,*/*',
                'Accept-Language: en-US,en;q=0.9',
            ],
            'user_agent'       => $userAgent,
        ];

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
                $headers = @get_headers($href, false, $context);
                /** @phpstan-ignore function.alreadyNarrowedType */
                if (!\is_array($headers)) {
                    continue;
                }
                /** @var list<string> $headers */
                $lastStatus = self::getLastStatusCode($headers);
                if (null !== $lastStatus && $lastStatus >= 200 && $lastStatus < 400) {
                    return null;
                }
                if ('HEAD' === $method && null !== $lastStatus && $lastStatus >= 400) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return 'HTTP status: ' . ($lastStatus ?? 'connection failed');
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
                $lastStatus = (int) $matches[1];
            }
        }

        return $lastStatus;
    }
}
