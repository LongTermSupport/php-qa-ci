<?php

declare(strict_types=1);

namespace LTS\PHPQA\ComposerRequireChecker;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;

/**
 * Preflight of the composerRequireChecker lane: the config's thecodingmachine/safe
 * `scan-files` entries must be the generated files safe loads on the running
 * PHP version. Thin I/O around {@see SafeScanFilesDetector}.
 *
 * @internal
 */
final readonly class SafeScanFilesCheck
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.composerRequireCheckerSafeScanFiles';

    public function __construct(
        private string $phpMajorMinor,
        private SafeScanFilesDetector $detector = new SafeScanFilesDetector(),
    ) {
    }

    public function run(string $configPath, string $projectRoot): int
    {
        if (!is_file($configPath)) {
            echo 'ERROR — composer-require-checker config path does not exist: ' . $configPath . \PHP_EOL;

            return 1;
        }

        try {
            $decoded = \Safe\json_decode(\Safe\file_get_contents($configPath), true);
        } catch (\Safe\Exceptions\JsonException $jsonException) {
            echo 'ERROR — composer-require-checker config could not be parsed as valid JSON: ' . $configPath
                . ' (' . $jsonException->getMessage() . ')' . \PHP_EOL;

            return 1;
        }

        $scanFiles = \is_array($decoded) ? ($decoded['scan-files'] ?? []) : [];
        if (!\is_array($scanFiles)) {
            echo 'ERROR — composer-require-checker config "scan-files" is not a list: ' . $configPath . \PHP_EOL;

            return 1;
        }

        $problems = $this->detector->check(array_values(array_filter($scanFiles, is_string(...))), $projectRoot, $this->phpMajorMinor);

        if ([] === $problems) {
            echo 'composer-require-checker scan-files name the safe files loaded on PHP ' . $this->phpMajorMinor
                . ' — OK (' . $configPath . ').' . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — composer-require-checker scan-files list safe files PHP ' . $this->phpMajorMinor . ' does not load' . \PHP_EOL
            . '-----------------------------------------------------------------------------------' . \PHP_EOL
            . 'Config file: ' . $configPath . \PHP_EOL . \PHP_EOL;
        foreach ($problems as $problem) {
            echo '  - ' . $problem . \PHP_EOL;
        }

        echo \PHP_EOL . '🪪  ' . self::IDENTIFIER . '  (vendor/bin/rule-doc ' . self::IDENTIFIER . ')' . \PHP_EOL;

        return 1;
    }

    public static function main(string $configPath, string $projectRoot): int
    {
        return new self(\PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION)->run($configPath, $projectRoot);
    }
}
