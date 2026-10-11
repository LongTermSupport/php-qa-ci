<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Lane\Infection\IncludedFilesProbeInterface;
use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * An autoloader probe that runs nothing: it answers what it was built with
 * and records each autoloader it was asked about.
 */
final class FakeIncludedFilesProbe implements IncludedFilesProbeInterface
{
    /** @var list<string> the autoloaders asked about, in order */
    public array $asked = [];

    /** @param list<string>|string $included the files, or why the probe could not say */
    public function __construct(
        private readonly array|string $included,
    ) {
    }

    public function includedFiles(ToolContext $context, string $autoload): array|string
    {
        $this->asked[] = $autoload;

        return $this->included;
    }
}
