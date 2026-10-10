<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

use Throwable;

/**
 * The phpstan/phpstan repository on GitHub, where each tag holds the Turbo binaries and cores the
 * phar loads under `turbo-ext/`, over plain HTTP: nothing is shelled out. The token in
 * GITHUB_AUTH_TOKEN is sent when set, because unauthenticated API requests are limited to 60 an
 * hour, which a handful of CI runs exhausts.
 *
 * @internal
 */
final readonly class GitHubTurboReleaseSource implements TurboReleaseSourceInterface
{
    /** Every path of one tag, recursively. */
    private const string TREE_API = 'https://api.github.com/repos/phpstan/phpstan/git/trees/%s?recursive=1';

    /** One file of one tag. */
    private const string DOWNLOAD_URL = 'https://raw.githubusercontent.com/phpstan/phpstan/%s/turbo-ext/%s';

    /** GitHub rejects an API request with no User-Agent. */
    private const string USER_AGENT = 'php-qa-ci';

    /** Long enough for a 7 MB core on a slow runner, short enough not to hang a composer event. */
    private const int TIMEOUT_SECONDS = 60;

    public function tree(string $version): ?string
    {
        return $this->get(\sprintf(self::TREE_API, rawurlencode($version)));
    }

    public function file(string $version, string $path): ?string
    {
        return $this->get(\sprintf(self::DOWNLOAD_URL, rawurlencode($version), implode('/', array_map(rawurlencode(...), explode('/', $path)))));
    }

    private function get(string $url): ?string
    {
        $headers = ['User-Agent: ' . self::USER_AGENT, 'Accept: application/vnd.github+json'];
        $token   = getenv('GITHUB_AUTH_TOKEN');
        if (\is_string($token) && '' !== $token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'header'        => implode("\r\n", $headers),
                'timeout'       => self::TIMEOUT_SECONDS,
                'ignore_errors' => false,
            ],
        ]);

        try {
            return \Safe\file_get_contents($url, false, $context);
        } catch (Throwable $throwable) {
            // The caller decides what a failed request means; the cause is still worth printing,
            // or a rate limit looks the same as a missing release.
            \Safe\fwrite(\STDERR, 'Note: could not fetch ' . $url . ': ' . $throwable->getMessage() . \PHP_EOL);

            return null;
        }
    }
}
