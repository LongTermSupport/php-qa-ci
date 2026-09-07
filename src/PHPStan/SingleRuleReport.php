<?php

declare(strict_types=1);

namespace LTS\PHPQA\PHPStan;

use InvalidArgumentException;
use JsonException;

/**
 * Answers one question about a PHPStan JSON run: did a given rule fire, and
 * where. Backs `bin/phpstan-rule <identifier> <path>`, the single-rule harness
 * a rule author uses to prove a rule sees the construction it is meant to see
 * before trusting a green full run.
 *
 * @internal
 */
final readonly class SingleRuleReport
{
    /** @param array<string, list<array{line: int|null, message: string, identifier: string|null}>> $messagesByFile */
    private function __construct(private array $messagesByFile)
    {
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = \Safe\json_decode($json, true);
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException('Expected PHPStan --error-format=json output: ' . $jsonException->getMessage(), 0, $jsonException);
        }

        if (!\is_array($decoded) || !\array_key_exists('files', $decoded) || !\is_array($decoded['files'])) {
            throw new InvalidArgumentException('Expected PHPStan --error-format=json output with a "files" key');
        }

        $messagesByFile = [];
        foreach ($decoded['files'] as $file => $fileReport) {
            if (!\is_string($file)) {
                continue;
            }

            if (!\is_array($fileReport)) {
                continue;
            }

            if (!\is_array($fileReport['messages'] ?? null)) {
                continue;
            }

            $messagesByFile[$file] = [];
            foreach ($fileReport['messages'] as $message) {
                if (!\is_array($message)) {
                    continue;
                }

                if (!\is_string($message['message'] ?? null)) {
                    continue;
                }

                $line                    = $message['line']       ?? null;
                $identifier              = $message['identifier'] ?? null;
                $messagesByFile[$file][] = [
                    'line'       => \is_int($line) ? $line : null,
                    'message'    => $message['message'],
                    'identifier' => \is_string($identifier) ? $identifier : null,
                ];
            }
        }

        return new self($messagesByFile);
    }

    /**
     * Every location the rule fired, as "path:line message".
     *
     * @return list<string>
     */
    public function firingsOf(string $identifier): array
    {
        $firings = [];
        foreach ($this->messagesByFile as $file => $messages) {
            foreach ($messages as $message) {
                if ($identifier !== $message['identifier']) {
                    continue;
                }

                $firings[] = \sprintf('%s:%s %s', $file, $message['line'] ?? '?', $message['message']);
            }
        }

        return $firings;
    }

    public function render(string $identifier): string
    {
        $firings = $this->firingsOf($identifier);
        if ([] === $firings) {
            return $identifier . " did not fire\n";
        }

        return \sprintf("%s FIRED (%d)\n%s\n", $identifier, \count($firings), implode("\n", $firings));
    }

    /**
     * Entry point for the harness: JSON on stdin, identifier as the argument.
     * Exit 0 when the rule did not fire, 1 when it did, 2 when the input was
     * not a PHPStan JSON run at all.
     */
    public static function main(string $identifier, string $json): int
    {
        try {
            $report = self::fromJson($json);
        } catch (InvalidArgumentException $invalidArgumentException) {
            \Safe\fwrite(STDERR, $invalidArgumentException->getMessage() . "\n");

            return 2;
        }

        echo $report->render($identifier);

        return [] === $report->firingsOf($identifier) ? 0 : 1;
    }
}
