<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent;

use JsonException;
use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use LTS\PHPQA\Pipeline\Agent\Dto\ParsedArchReportDto;
use LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException;

/**
 * Reads PHPArkitect's own `--format=json` into the agent-mode report shape.
 *
 * The phar prints a banner and a progress bar to the same stream before the
 * report and a verdict line after it, so the object is located by its
 * `totalViolations` key rather than by assuming it starts the output.
 *
 * `details` is a JSON object keyed by class when there are violations and an
 * empty ARRAY when there are none, which is why the empty case is handled
 * before the shape is trusted.
 *
 * @internal
 */
final readonly class ArkitectJsonParser
{
    public const string TOOL = 'phpArkitect';

    private const string TOTAL_KEY = 'totalViolations';

    public function parse(string $output): ParsedArchReportDto
    {
        $decoded = $this->decode($output);
        $details = $decoded['details'] ?? [];
        if (!\is_array($details)) {
            throw UnreadableReportException::forTool(self::TOOL, 'the "details" value was neither an object nor an array');
        }

        $classes = [];
        foreach ($details as $fqcn => $violations) {
            if (!\is_string($fqcn) || !\is_array($violations)) {
                continue;
            }

            $errors = $this->violations($violations);
            if ([] !== $errors) {
                $classes[$fqcn] = $errors;
            }
        }

        return new ParsedArchReportDto($classes);
    }

    /** @return array<array-key, mixed> */
    private function decode(string $output): array
    {
        // The report is the object carrying totalViolations. Anchoring on the
        // key and walking back to its opening brace skips the banner and the
        // progress bar without assuming how much of either there was.
        $key = strpos($output, '"' . self::TOTAL_KEY . '"');
        if (false === $key) {
            throw UnreadableReportException::forTool(self::TOOL, 'no "totalViolations" key was found in the output');
        }

        $start = strrpos(substr($output, 0, $key), '{');
        if (false === $start) {
            throw UnreadableReportException::forTool(self::TOOL, 'the "totalViolations" key was not inside a JSON object');
        }

        $end = $this->matchingBrace($output, $start);
        if (null === $end) {
            throw UnreadableReportException::forTool(self::TOOL, 'the JSON object was never closed');
        }

        try {
            $decoded = \Safe\json_decode(substr($output, $start, $end - $start + 1), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw UnreadableReportException::forTool(self::TOOL, $jsonException->getMessage());
        }

        if (!\is_array($decoded)) {
            throw UnreadableReportException::forTool(self::TOOL, 'the decoded JSON was not an object');
        }

        return $decoded;
    }

    /**
     * The offset of the brace closing the object that opens at $start, or null
     * if it never closes.
     *
     * Counting depth is what makes the trailer safe. Taking the LAST `}` in
     * the output instead would work right up until the phar printed a brace
     * after the report, and then it would silently truncate it.
     */
    private function matchingBrace(string $output, int $start): ?int
    {
        $depth    = 0;
        $inString = false;
        $escaped  = false;
        $length   = \strlen($output);

        for ($i = $start; $i < $length; ++$i) {
            $char = $output[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($inString) {
                if ('\\' === $char) {
                    $escaped = true;
                } elseif ('"' === $char) {
                    $inString = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inString = true;

                continue;
            }

            if ('{' === $char) {
                ++$depth;
            } elseif ('}' === $char) {
                --$depth;
                if (0 === $depth) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $violations
     *
     * @return list<FileErrorDto>
     */
    private function violations(array $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            if (!\is_array($violation)) {
                continue;
            }

            // PHPArkitect gives a message and nothing else: no line, and no
            // stable rule id. Both stay null rather than being invented.
            $message = $violation['error'] ?? null;
            if (\is_string($message) && '' !== $message) {
                $errors[] = new FileErrorDto(null, $message, null, null);
            }
        }

        return $errors;
    }
}
