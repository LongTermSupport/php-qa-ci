<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent;

use JsonException;
use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use LTS\PHPQA\Pipeline\Agent\Dto\ParsedReportDto;
use LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException;

/**
 * Reads PHPStan's own `--error-format=json` into the agent-mode report shape.
 *
 * The table output is never parsed. It wraps long messages across lines, pads
 * columns to the terminal width and prints the identifier on its own row, so
 * any reader of it is a guess that breaks when PHPStan changes its rendering.
 * The JSON format is the analyser's declared machine interface.
 *
 * @internal
 */
final readonly class PhpstanJsonParser
{
    public const string TOOL = 'phpstan';

    public function parse(string $output): ParsedReportDto
    {
        $decoded = $this->decode($output);
        $files   = [];
        foreach ($this->arrayValue($decoded, 'files') as $path => $entry) {
            if (!\is_string($path) || !\is_array($entry)) {
                throw UnreadableReportException::forTool(self::TOOL, 'a "files" entry was not keyed by a path');
            }

            $files[$path] = $this->messages($entry);
        }

        return new ParsedReportDto($files, $this->globalErrors($decoded));
    }

    /** @return array<array-key, mixed> */
    private function decode(string $output): array
    {
        // PHPStan writes the report to stdout, but a PHP start-up warning can
        // reach the same stream first, so the report begins at the first brace.
        $start = strpos($output, '{');
        if (false === $start) {
            throw UnreadableReportException::forTool(self::TOOL, 'no JSON object was found in the output');
        }

        try {
            $decoded = \Safe\json_decode(substr($output, $start), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw UnreadableReportException::forTool(self::TOOL, $jsonException->getMessage());
        }

        if (!\is_array($decoded) || !\array_key_exists('files', $decoded)) {
            throw UnreadableReportException::forTool(self::TOOL, 'the decoded JSON has no "files" key');
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @return array<array-key, mixed>
     */
    private function arrayValue(array $decoded, string $key): array
    {
        $value = $decoded[$key] ?? [];

        return \is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return list<FileErrorDto>
     */
    private function messages(array $entry): array
    {
        $errors = [];
        foreach ($this->arrayValue($entry, 'messages') as $message) {
            if (!\is_array($message)) {
                continue;
            }

            // A finding with no message is not a finding anyone can act on.
            // Defaulting it to an empty string would put a blank line in the
            // report and count it as work to do, so it is dropped instead.
            $text = $this->nullableString($message, 'message');
            if (null === $text) {
                continue;
            }

            $errors[] = new FileErrorDto(
                $this->nullableInt($message, 'line'),
                $text,
                $this->nullableString($message, 'identifier'),
                $this->nullableString($message, 'tip'),
            );
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @return list<string>
     */
    private function globalErrors(array $decoded): array
    {
        $errors = [];
        foreach ($this->arrayValue($decoded, 'errors') as $error) {
            if (\is_string($error)) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** @param array<array-key, mixed> $message */
    private function nullableInt(array $message, string $key): ?int
    {
        $value = $message[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /** @param array<array-key, mixed> $message */
    private function nullableString(array $message, string $key): ?string
    {
        $value = $message[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
