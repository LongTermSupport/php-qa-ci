<?php

declare(strict_types=1);

namespace LTS\PHPQA\Turbo;

/**
 * Whether PHPStan ran with Turbo, read from the lines `phpstan.phar diagnose` prints about it.
 * PHPStan falls back to plain PHP without a word when its binary is missing or stale, so the
 * PHPStan lane prints line() on every text-mode run, and names the case php-qa-ci could have
 * prevented: a host it ships a build for, where the build is not running.
 *
 * @internal
 */
final readonly class TurboStatus
{
    /** How each of diagnose's Turbo lines begins. */
    private const string LINE_PREFIX = 'Turbo ';

    /** The diagnose line that says whether the extension runs. */
    private const string EXTENSION_LINE = 'Turbo extension: ';

    /** How that line's value begins when Turbo runs, in the main process or in the workers. */
    private const string ENABLED = 'enabled';

    /** How every report line begins. */
    private const string REPORT = 'PHPStan Turbo: ';

    /**
     * @param string       $extension     the value of diagnose's extension line, '' when it printed none
     * @param list<string> $diagnoseLines every Turbo line diagnose printed
     */
    private function __construct(
        public TurboStateEnum $state,
        private string $extension,
        private array $diagnoseLines,
    ) {
    }

    /** @param bool $shippedForHost whether php-qa-ci's manifest pins a build for this host */
    public static function fromDiagnose(string $diagnose, bool $shippedForHost): self
    {
        $lines = [];
        foreach (explode("\n", $diagnose) as $line) {
            $line = rtrim($line);
            if (str_starts_with($line, self::LINE_PREFIX)) {
                $lines[] = $line;
            }
        }

        foreach ($lines as $line) {
            if (str_starts_with($line, self::EXTENSION_LINE)) {
                $extension = substr($line, \strlen(self::EXTENSION_LINE));

                return new self(self::stateOf($extension, $shippedForHost), $extension, $lines);
            }
        }

        return new self(TurboStateEnum::Unknown, '', $lines);
    }

    /** The report the PHPStan lane prints. */
    public function line(): string
    {
        return self::REPORT . match ($this->state) {
            TurboStateEnum::Enabled         => $this->extension,
            TurboStateEnum::Missing         => "NOT RUNNING, though php-qa-ci ships a build for this host. PHPStan runs without it, and slower.\n"
                . "  Run turbo-install from the Composer bin directory, then phpstan.phar diagnose to confirm. PHPStan reports:\n  "
                . implode("\n  ", $this->diagnoseLines),
            TurboStateEnum::NotBuiltForHost => 'not running; upstream publishes no build for this host, so PHPStan runs without it',
            TurboStateEnum::Unknown         => 'unknown; phpstan.phar diagnose did not report it',
        };
    }

    private static function stateOf(string $extension, bool $shippedForHost): TurboStateEnum
    {
        if (str_starts_with($extension, self::ENABLED)) {
            return TurboStateEnum::Enabled;
        }

        return $shippedForHost ? TurboStateEnum::Missing : TurboStateEnum::NotBuiltForHost;
    }
}
