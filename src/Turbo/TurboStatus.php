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

    /**
     * How that line's value begins when `diagnose` itself runs without the extension but the restart
     * an `analyse` run performs loads it: diagnose never restarts, so it cannot say "enabled".
     */
    private const string LOADS_ON_RESTART = 'not loaded here, loaded by the restart';

    /** The diagnose line that names the binary the restart loads. */
    private const string WORKER_LINE = 'Turbo worker binary: ';

    /** What that line's value is when no binary was found. */
    private const string NO_WORKER = 'none found';

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

                return new self(self::stateOf($extension, self::hasWorkerBinary(...$lines), $shippedForHost), $extension, $lines);
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

    private static function stateOf(string $extension, bool $workerBinaryFound, bool $shippedForHost): TurboStateEnum
    {
        if (str_starts_with($extension, self::ENABLED)) {
            return TurboStateEnum::Enabled;
        }

        if (str_starts_with($extension, self::LOADS_ON_RESTART) && $workerBinaryFound) {
            return TurboStateEnum::Enabled;
        }

        return $shippedForHost ? TurboStateEnum::Missing : TurboStateEnum::NotBuiltForHost;
    }

    /** Whether diagnose names a worker binary among the Turbo lines it printed. */
    private static function hasWorkerBinary(string ...$lines): bool
    {
        foreach ($lines as $line) {
            if (str_starts_with($line, self::WORKER_LINE)) {
                return self::NO_WORKER !== substr($line, \strlen(self::WORKER_LINE));
            }
        }

        return false;
    }
}
