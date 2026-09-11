<?php

declare(strict_types=1);

namespace LTS\PHPQA\Markdown;

/**
 * One place where a document talks about itself instead of about its subject.
 *
 * @internal
 */
final readonly class DocumentSelfReferenceFinding
{
    public function __construct(
        /** Project-relative path, so the message reads the same wherever the checkout lives. */
        public string $file,
        /** Line the containing paragraph starts on: the phrase itself may straddle a line break. */
        public int $line,
        /** The matched phrase, normalised and lower-cased, so it is stable to re-wrapping. */
        public string $matched,
        /** The whole paragraph, flattened, so the reader sees the phrase in context. */
        public string $context,
    ) {
    }
}
