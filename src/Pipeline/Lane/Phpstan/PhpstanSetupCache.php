<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use Closure;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use RuntimeException;
use Safe\Exceptions\SafeExceptionInterface;

/**
 * The answers of `phpstan.phar dump-parameters` and `phpstan.phar diagnose`, kept under
 * `var/qa/cache/phpstan-setup/` against the PhpstanSetupKey of everything they depend on. Each
 * costs a PHPStan start, about 1.5 to 2 seconds, and neither depends on the file analysed, so the
 * edit-time lint, `qa -t phpstan -p FILE`, would otherwise pay over three seconds per edit for
 * answers it already has (#219).
 *
 * A `-p` run reads an entry whose key matches and asks PHPStan only on a miss. Any other run
 * asks PHPStan every time and rewrites the entry: there the seconds are noise beside the analysis,
 * and a gap in what the key covers then cannot outlive the next full run.
 *
 * Only an answer is kept: a probe that failed returns null and leaves the entry as it was. No key
 * (an input the key cannot see), a key that fails to compute, and an entry that is missing,
 * corrupt or of another shape all mean asking PHPStan. An entry is written to a file of its own
 * and renamed into place, so a reader sees one whole answer or none, and two writers at once
 * leave one of their answers, each whole. Deleting `var/qa/` resets it.
 *
 * @internal
 */
final readonly class PhpstanSetupCache
{
    /** The cache's directory under the QA cache directory. */
    public const string DIR = 'phpstan-setup';

    /** An entry's field holding the key it was written against. */
    private const string KEY = 'key';

    /** An entry's field holding what the probe printed. */
    private const string OUTPUT = 'output';

    /** An entry's field holding ENTRY_SCHEMA. */
    private const string SCHEMA = 'schema';

    /** The shape of an entry; another value is a miss. */
    private const int ENTRY_SCHEMA = 1;

    /**
     * What dump-parameters printed for `$config`, from the cache or from `$ask`.
     *
     * @param Closure(): ?string $ask runs the dump: its stdout, or null when it gave no answer
     */
    public function dumpParameters(ToolContext $context, string $config, ?string $autoloadFile, Closure $ask): ?string
    {
        // The deadCode lane's dump, with its detector's autoload file, is kept apart from the phpstan lane's.
        $question = null === $autoloadFile ? 'dump-parameters' : 'dump-parameters-' . hash('xxh128', $autoloadFile);

        return $this->remember($context, $question, static fn (): ?string => new PhpstanSetupKey()->forDumpParameters($context, $config, $autoloadFile), $ask);
    }

    /**
     * What diagnose printed for the lane's wrapper neon, from the cache or from `$ask`.
     *
     * @param Closure(): ?string $ask runs diagnose: its output, or null when it failed
     */
    public function diagnose(ToolContext $context, string $wrapperNeon, Closure $ask): ?string
    {
        return $this->remember($context, 'diagnose', static fn (): ?string => new PhpstanSetupKey()->forDiagnose($context, $wrapperNeon), $ask);
    }

    /**
     * @param Closure(): ?string $key
     * @param Closure(): ?string $ask
     */
    private function remember(ToolContext $context, string $question, Closure $key, Closure $ask): ?string
    {
        // The key is taken before PHPStan is asked, so an input that changes meanwhile leaves
        // an entry that misses next time rather than one that hits with the old answer.
        try {
            $current = $key();
        } catch (RuntimeException|SafeExceptionInterface $failed) {
            $context->writeln(\sprintf('PHPStan: no cached setup answer (%s), asking PHPStan', $failed->getMessage()));

            return $ask();
        }

        if (null === $current) {
            return $ask();
        }

        $file = $context->config->paths->cacheDir . '/' . self::DIR . '/' . $question . '.json';
        if (null !== $context->config->specifiedPath) {
            try {
                $cached = $this->read($file, $current);
            } catch (SafeExceptionInterface $unreadable) {
                $context->writeln(\sprintf('PHPStan: cached setup answer unreadable (%s), asking PHPStan', $unreadable->getMessage()));
                $cached = null;
            }

            if (null !== $cached) {
                return $cached;
            }
        }

        $answer = $ask();
        if (null !== $answer) {
            $this->write($context, $file, $current, $answer);
        }

        return $answer;
    }

    /** The kept answer when the entry is whole and its key is `$key`; null otherwise. */
    private function read(string $file, string $key): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $contents = \Safe\file_get_contents($file);
        if (!json_validate($contents)) {
            return null;
        }

        $entry = \Safe\json_decode($contents, true);
        if (!\is_array($entry) || self::ENTRY_SCHEMA !== ($entry[self::SCHEMA] ?? null) || $key !== ($entry[self::KEY] ?? null)) {
            return null;
        }

        $output = $entry[self::OUTPUT] ?? null;

        return \is_string($output) ? $output : null;
    }

    /** Written beside the entry and renamed over it; a failure is said and leaves the run unharmed. */
    private function write(ToolContext $context, string $file, string $key, string $answer): void
    {
        $directory = \dirname($file);
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            if (!is_dir($directory)) {
                \Safe\mkdir($directory, 0o777, true);
            }

            \Safe\file_put_contents($temporary, \Safe\json_encode([self::SCHEMA => self::ENTRY_SCHEMA, self::KEY => $key, self::OUTPUT => $answer]));
            \Safe\rename($temporary, $file);
        } catch (SafeExceptionInterface $safeException) {
            $context->writeln(\sprintf('PHPStan: setup answer not cached (%s)', $safeException->getMessage()));
            if (is_file($temporary)) {
                \Safe\unlink($temporary);
            }
        }
    }
}
