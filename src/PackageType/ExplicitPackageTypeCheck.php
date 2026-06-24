<?php

declare(strict_types=1);

namespace LTS\PHPQA\PackageType;

use LTS\PHPQA\Helper;

/**
 * Always-on pipeline check: a project's `composer.json` MUST declare `type`
 * explicitly.
 *
 * Composer silently defaults an omitted `type` to `library`, which quietly makes
 * the app-vs-library decision for you. This check rejects that default so the
 * decision is conscious — the file must say `library` (an installable
 * dependency, whose public surface is then API-classified by
 * {@see \LTS\PHPQA\PHPStan\Rules\RequireApiOrInternalTagRule}) or `project` (an
 * application), or any other explicit Composer type.
 *
 * The decision is the pure {@see ExplicitPackageTypeDetector}; this class is the
 * thin I/O runner (read composer.json → print → exit code) invoked by the
 * `packageType` pipeline tool.
 */
final class ExplicitPackageTypeCheck
{
    public function __construct(
        private readonly ExplicitPackageTypeDetector $detector = new ExplicitPackageTypeDetector(),
    ) {
    }

    /**
     * @param array<int|string, mixed> $composerJson decoded composer.json
     *
     * @return int 0 when an explicit type is declared, 1 (build failure) otherwise
     */
    public function run(array $composerJson): int
    {
        $message = $this->detector->check($composerJson);

        if (null === $message) {
            echo 'composer.json declares an explicit package type — OK.' . \PHP_EOL;

            return 0;
        }

        echo \PHP_EOL . 'ERROR — composer.json package type is not declared explicitly' . \PHP_EOL
            . '------------------------------------------------------------' . \PHP_EOL
            . $message . \PHP_EOL;

        return 1;
    }

    public static function main(): int
    {
        return new self()->run(Helper::getComposerJsonDecoded());
    }
}
