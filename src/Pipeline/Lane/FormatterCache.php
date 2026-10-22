<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * A formatter lane's cache file, under var/qa/cache. A formatter's cache records what a run
 * judged clean and skips it next time, so it may only ever speed up a writable run: a
 * read-only run reads none, to judge every file as CI does (#235), and a read-only run that
 * finds a pending fix forgets it, because the writable run that failure asks for would
 * otherwise trust a cache that records the pending file as clean.
 *
 * @internal
 */
final readonly class FormatterCache
{
    private function __construct(public string $path)
    {
    }

    public static function in(ToolContext $context, string $name): self
    {
        return new self($context->config->paths->cacheDir . '/' . $name);
    }

    /** The cache file's path, its directory created for a formatter that will not create it. */
    public function prepared(): string
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            \Safe\mkdir($dir, 0o777, true);
        }

        return $this->path;
    }

    public function forget(): void
    {
        if (is_file($this->path)) {
            \Safe\unlink($this->path);
        }
    }
}
