<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

use Throwable;

/**
 * The phpstan/turbo-ext releases on GitHub, over plain HTTP: nothing is shelled out. The token in
 * GITHUB_AUTH_TOKEN is sent when set, because unauthenticated API requests are limited to 60 an
 * hour, which a handful of CI runs exhausts.
 *
 * @internal
 */
final readonly class GitHubTurboReleaseSource implements TurboReleaseSourceInterface
{
    /** The release record for one tag. */
    private const string RELEASE_API = 'https://api.github.com/repos/phpstan/turbo-ext/releases/tags/%s';

    /** One asset of one release. */
    private const string DOWNLOAD_URL = 'https://github.com/phpstan/turbo-ext/releases/download/%s/%s';

    /** GitHub rejects an API request with no User-Agent. */
    private const string USER_AGENT = 'php-qa-ci';

    /** Long enough for a 3.3 MB asset on a slow runner, short enough not to hang a composer event. */
    private const int TIMEOUT_SECONDS = 60;

    public function release(string $version): ?string
    {
        return $this->get(\sprintf(self::RELEASE_API, rawurlencode($version)));
    }

    public function asset(string $version, string $asset): ?string
    {
        return $this->get(\sprintf(self::DOWNLOAD_URL, rawurlencode($version), rawurlencode($asset)));
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
