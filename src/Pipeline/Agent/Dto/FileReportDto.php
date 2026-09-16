<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Agent\Dto;

use JsonSerializable;
use LTS\PHPQA\Pipeline\Agent\AgentStatusEnum;

/**
 * The agent-mode result for one source file: the whole of what an agent needs
 * to fix that file, and nothing else.
 *
 * The count is derived from the error list rather than passed alongside it,
 * so a report can never claim a number its own errors do not support. The
 * named constructors are the only way to build one, which is what keeps the
 * status, the exit code and the count consistent with each other.
 *
 * @internal
 */
final readonly class FileReportDto implements JsonSerializable
{
    /** ISO 8601, so a report is sortable and unambiguous without a timezone lookup. */
    public const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:sP';

    /** @param list<FileErrorDto> $errors */
    private function __construct(
        public string $tool,
        public string $path,
        public AgentStatusEnum $status,
        public int $runAt,
        public array $errors,
        public string $logPath,
    ) {
    }

    /**
     * The ordinary outcome: whatever the analyser said about this file. An
     * empty list is a clean file, which is the write that erases a previous
     * red report.
     *
     * @param list<FileErrorDto> $errors
     */
    public static function forErrors(string $tool, string $path, int $runAt, array $errors, string $logPath): self
    {
        return new self($tool, $path, AgentStatusEnum::forErrorCount(\count($errors)), $runAt, $errors, $logPath);
    }

    /**
     * The analyser died. The reason is recorded as the single error so an
     * agent reading only `errors` still learns why there is no analysis.
     */
    public static function crashed(string $tool, string $path, int $runAt, string $reason, string $logPath): self
    {
        return new self($tool, $path, AgentStatusEnum::Crashed, $runAt, [new FileErrorDto(null, $reason, null, null)], $logPath);
    }

    /**
     * The same result recorded against a different spelling of the same file.
     * The writer uses it to store a project-relative path whatever it was
     * handed, without the status being recomputed and a crash quietly
     * becoming an ordinary finding.
     */
    public function withPath(string $path): self
    {
        return new self($this->tool, $path, $this->status, $this->runAt, $this->errors, $this->logPath);
    }

    public function errorCount(): int
    {
        return \count($this->errors);
    }

    /**
     * @return array{
     *     tool: string,
     *     path: string,
     *     status: string,
     *     run_at: string,
     *     exit_code: int,
     *     error_count: int,
     *     errors: list<FileErrorDto>,
     *     log_path: string,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'tool'        => $this->tool,
            'path'        => $this->path,
            'status'      => $this->status->value,
            'run_at'      => gmdate(self::TIMESTAMP_FORMAT, $this->runAt),
            'exit_code'   => $this->status->exitCode(),
            'error_count' => $this->errorCount(),
            'errors'      => $this->errors,
            'log_path'    => $this->logPath,
        ];
    }
}
