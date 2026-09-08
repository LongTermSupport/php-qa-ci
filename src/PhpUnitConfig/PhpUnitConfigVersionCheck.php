<?php

declare(strict_types=1);

namespace LTS\PHPQA\PhpUnitConfig;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use PHPUnit\Runner\Version;

/**
 * Pipeline lane: the resolved phpunit.xml's version pins (schema URL and any
 * SYMFONY_PHPUNIT_VERSION pin) must match the installed PHPUnit major. Thin
 * I/O around {@see PhpUnitConfigVersionDetector}.
 *
 * @internal
 */
final readonly class PhpUnitConfigVersionCheck
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpunitConfigVersion';

    public function __construct(
        private string $installedVersion,
        private PhpUnitConfigVersionDetector $detector = new PhpUnitConfigVersionDetector(),
    ) {
    }

    public function run(string $configPath): int
    {
        if (!is_file($configPath)) {
            echo 'ERROR — phpunit.xml config path does not exist: ' . $configPath . \PHP_EOL;

            return 1;
        }

        $problems = $this->detector->check(\Safe\file_get_contents($configPath), $this->installedVersion);

        if ([] === $problems) {
            echo 'phpunit.xml version pins agree with the installed PHPUnit ' . $this->installedVersion
                . ' — OK (' . $configPath . ').' . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — phpunit.xml pins a PHPUnit version that is not the one installed' . \PHP_EOL
            . '--------------------------------------------------------------------------' . \PHP_EOL
            . 'Config file: ' . $configPath . \PHP_EOL . \PHP_EOL;
        foreach ($problems as $problem) {
            echo '  - ' . $problem . \PHP_EOL;
        }

        echo \PHP_EOL . '🪪  ' . self::IDENTIFIER . '  (vendor/bin/rule-doc ' . self::IDENTIFIER . ')' . \PHP_EOL;

        return 1;
    }

    public static function main(string $configPath): int
    {
        return new self(Version::id())->run($configPath);
    }
}
