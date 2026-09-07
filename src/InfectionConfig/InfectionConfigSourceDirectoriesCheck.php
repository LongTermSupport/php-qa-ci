<?php

declare(strict_types=1);

namespace LTS\PHPQA\InfectionConfig;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;

/**
 * Pipeline lane: infection.json's `source.directories` entries must resolve
 * to real directories, relative to infection.json's own location. Thin I/O
 * around {@see InfectionConfigSourceDirectoriesDetector}.
 *
 * @internal
 */
final readonly class InfectionConfigSourceDirectoriesCheck
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.infectionConfigSourceDirectoriesMustExist';

    public function __construct(
        private InfectionConfigSourceDirectoriesDetector $detector = new InfectionConfigSourceDirectoriesDetector(),
    ) {
    }

    public function run(string $configPath): int
    {
        if (!is_file($configPath)) {
            echo 'ERROR — infection.json config path does not exist: ' . $configPath . \PHP_EOL;

            return 1;
        }

        try {
            $raw = \Safe\file_get_contents($configPath);
        } catch (\Safe\Exceptions\FilesystemException $filesystemException) {
            echo 'ERROR — could not read infection.json at: ' . $configPath
                . ' (' . $filesystemException->getMessage() . ')' . \PHP_EOL;

            return 1;
        }

        try {
            $decoded = \Safe\json_decode($raw, true);
        } catch (\Safe\Exceptions\JsonException $jsonException) {
            echo 'ERROR — infection.json could not be parsed as valid JSON: ' . $configPath
                . ' (' . $jsonException->getMessage() . ')' . \PHP_EOL;

            return 1;
        }

        if (!\is_array($decoded)) {
            echo 'ERROR — infection.json could not be parsed as valid JSON: ' . $configPath . \PHP_EOL;

            return 1;
        }

        /** @var array<string, mixed> $decoded */
        $problems = $this->detector->check($decoded, \dirname($configPath));

        if ([] === $problems) {
            echo 'infection.json source.directories all resolve to real directories — OK ('
                . $configPath . ').' . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — infection.json declares a source directory that does not exist' . \PHP_EOL
            . '-------------------------------------------------------------------------' . \PHP_EOL
            . 'Config file: ' . $configPath . \PHP_EOL . \PHP_EOL;
        foreach ($problems as $problem) {
            echo '  - ' . $problem . \PHP_EOL;
        }

        echo \PHP_EOL . '🪪  ' . self::IDENTIFIER . '  (vendor/bin/rule-doc ' . self::IDENTIFIER . ')' . \PHP_EOL;

        return 1;
    }

    public static function main(string $configPath): int
    {
        return new self()->run($configPath);
    }
}
