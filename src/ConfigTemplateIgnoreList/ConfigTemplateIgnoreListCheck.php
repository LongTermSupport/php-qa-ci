<?php

declare(strict_types=1);

namespace LTS\PHPQA\ConfigTemplateIgnoreList;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;

/**
 * Pipeline lane: the toolchain audits its own shipped configDefaults/generic/
 * so that every namespace-less template a consumer is told to copy into its
 * override directory is covered by psr4-validate-ignore-list.txt. Thin I/O
 * around {@see ConfigTemplateIgnoreListAuditor}.
 *
 * @internal
 */
final readonly class ConfigTemplateIgnoreListCheck
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.configTemplateIgnoreList';

    public function __construct(
        private ConfigTemplateIgnoreListAuditor $auditor,
    ) {
    }

    public function run(): int
    {
        $violations = $this->auditor->main();

        if ([] === $violations) {
            echo 'Every namespace-less configDefaults/generic template is covered by psr4-validate-ignore-list.txt.' . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — config templates the PSR-4 ignore list does not cover' . \PHP_EOL
            . '------------------------------------------------------------' . \PHP_EOL;
        foreach ($violations as $relativePath => $copyDestination) {
            echo '  ' . $relativePath . ' -> would fail psr4Validate as ' . $copyDestination . \PHP_EOL;
        }

        echo \PHP_EOL . 'Add a matching line to configDefaults/generic/psr4-validate-ignore-list.txt, in the shape of'
            . ' the existing entries.' . \PHP_EOL
            . '🪪  ' . self::IDENTIFIER . '  (vendor/bin/rule-doc ' . self::IDENTIFIER . ')' . \PHP_EOL;

        return 1;
    }

    /**
     * Resolved against this class's own location rather than the invoking
     * project's root: the audited directory exists only inside the package,
     * whether it is the root project or installed under a consumer's vendor/.
     */
    public static function main(): int
    {
        $genericDir = \dirname(__DIR__, 2) . '/configDefaults/generic';

        return new self(new ConfigTemplateIgnoreListAuditor(
            $genericDir,
            $genericDir . '/psr4-validate-ignore-list.txt',
        ))->run();
    }
}
