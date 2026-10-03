<?php

declare(strict_types=1);

namespace LTS\PHPQA\Arkitect\Dto;

/**
 * The verdict of one `bin/arkitect-rule` run: whether the rule named by
 * `$because` fired on `$path`, and on which classes.
 *
 * A miss is also what a mistyped clause looks like, since PHPArkitect gives a
 * rule no name to check the clause against, so the rendered miss says so and
 * names the entry config the rule was looked for in.
 *
 * @internal
 */
final readonly class ProbeResultDto
{
    /** @var list<ProbeFiringDto> */
    public array $firings;

    public function __construct(
        public string $because,
        public string $path,
        public string $entryConfig,
        ProbeFiringDto ...$firings,
    ) {
        $this->firings = array_values($firings);
    }

    public function fired(): bool
    {
        return [] !== $this->firings;
    }

    public function render(): string
    {
        if (!$this->fired()) {
            return \sprintf(
                "Rule \"%s\" did not fire on %s\n"
                . "  A rule that cannot see the fixture and a clause that names no rule read the same: check the\n"
                . "  text is part of the rule's because clause in %s, then that the fixture breaks it.\n",
                $this->because,
                $this->path,
                $this->entryConfig,
            );
        }

        $lines = [\sprintf('Rule "%s" FIRED (%d) on %s', $this->because, \count($this->firings), $this->path)];
        foreach ($this->firings as $firing) {
            $lines[] = \sprintf('  %s (%s): %s', $firing->fqcn, $firing->file ?? 'file not traced', $firing->message);
        }

        return implode("\n", $lines) . "\n";
    }
}
