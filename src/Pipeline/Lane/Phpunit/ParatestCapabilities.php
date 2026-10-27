<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpunit;

/**
 * What the installed ParaTest accepts, read from its own `--help` rather than
 * guessed from a version number. ParaTest 5 and earlier select the PHPUnit
 * binary with `--phpunit`; ParaTest 7 and later run the project's PHPUnit
 * itself and forward PHPUnit's own flags (the strictness flags among them);
 * ParaTest 6 has neither, so the lane's argv cannot be expressed in it.
 *
 * @internal
 */
final readonly class ParatestCapabilities
{
    private function __construct(
        public bool $acceptsPhpunitOption,
        private bool $forwardsPhpunitFlags,
    ) {
    }

    public static function fromHelp(string $help): self
    {
        return new self(
            1 === \Safe\preg_match('/--phpunit=/', $help),
            1 === \Safe\preg_match('/--fail-on-risky(?![\w-])/', $help),
        );
    }

    /** True when the lane's argv can be passed to this ParaTest. */
    public function isUsable(): bool
    {
        return $this->acceptsPhpunitOption || $this->forwardsPhpunitFlags;
    }
}
