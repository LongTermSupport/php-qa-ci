<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpunit;

/**
 * The options the installed ParaTest declares in its own `--help`, and the
 * version it reports, so the lane checks the flags it is about to pass against
 * what ParaTest accepts rather than guessing from one option or a version.
 *
 * @internal
 */
final readonly class ParatestCapabilities
{
    private const string UNREPORTED = 'ParaTest (version not reported)';

    /** @param list<string> $options every declared option, long and short, by name */
    private function __construct(
        private array $options,
        private string $label,
    ) {
    }

    public static function fromProbes(string $help, string $versionOutput): self
    {
        $options = [];
        // An option line starts with its (possibly colour-coded) name; a flag
        // mentioned in a description is not at a line start and is not read.
        foreach (explode("\n", $help) as $line) {
            if (1 !== \Safe\preg_match('/^(?:\s|\x1B\[[0-9;]*m)*((?:-\w,\s+)?)(--[A-Za-z][\w-]*)/', $line, $match) || !isset($match[1], $match[2])) {
                continue;
            }

            foreach ([substr($match[1], 0, 2), $match[2]] as $name) {
                if ('' !== $name) {
                    $options[] = $name;
                }
            }
        }

        $label = 1 === \Safe\preg_match('/(\d+\.\d+\.\d+)/', $versionOutput, $version) && isset($version[1])
            ? 'ParaTest ' . $version[1]
            : self::UNREPORTED;

        return new self($options, $label);
    }

    /** The name ParaTest is reported under: its version when it prints one. */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * The given flags this ParaTest does not declare, in the order given.
     *
     * @return list<string>
     */
    public function lacking(string ...$flags): array
    {
        return array_values(array_filter($flags, fn (string $flag): bool => !\in_array($flag, $this->options, true)));
    }
}
