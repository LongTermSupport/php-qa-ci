<?php

declare(strict_types=1);

namespace LTS\PHPQA\Installer;

/**
 * An installer that says what it writes under the package directory.
 *
 * `vendor/lts/php-qa-ci/` is a git checkout when a project requires a `dev-` branch, and the shipped
 * pre-commit vendor hook counts an untracked entry there as uncommitted work. So every path an
 * installer leaves in the package directory is either committed or ignored, and the test of the
 * `.gitignore` takes its paths from these declarations: an installer with no declaration, or one
 * that leaves a path out, is the only way a path can reach a checkout unchecked.
 *
 * @internal
 */
interface InstallerOutputsInterface
{
    /**
     * Every project-relative path that reaches the package directory because of this installer,
     * written by it or by a command it prints, in install mode and in maintainer update mode.
     *
     * @return list<InstallerOutput>
     */
    public static function outputs(): array;
}
