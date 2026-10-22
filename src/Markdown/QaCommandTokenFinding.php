<?php

declare(strict_types=1);

namespace LTS\PHPQA\Markdown;

/**
 * One `qa -t <token>` command in a document that `bin/qa` refuses.
 *
 * @internal
 */
final readonly class QaCommandTokenFinding
{
    public function __construct(
        /** Project-relative path, so the message reads the same wherever the checkout lives. */
        public string $file,
        /** The line the command is written on, counted from 1. */
        public int $line,
        /** The token as written. */
        public string $token,
        /** The command as written, from its bin prefix to its last option. */
        public string $command,
        /** Why bin/qa refuses it. */
        public string $problem,
        /** The command the writer most likely meant; null when nothing in the registry resembles it. */
        public ?string $hint,
    ) {
    }

    public function message(): string
    {
        return \sprintf(
            "%s:%d\n    %s — %s — %s",
            $this->file,
            $this->line,
            $this->command,
            $this->problem,
            $this->hint ?? 'vendor/bin/qa -h lists every tool',
        );
    }
}
