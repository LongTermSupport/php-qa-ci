<?php

declare(strict_types=1);

namespace LTS\PHPQA\Installer;

/**
 * One path an installer leaves in the package directory.
 *
 * @internal
 */
final readonly class InstallerOutput
{
    /**
     * @param string $path              project-relative, without a leading slash; for a directory
     *                                  an installer names at random, a sample of what it is called
     * @param bool   $isDirectory       whether the path is a directory, otherwise a file
     * @param bool   $untrackedByDesign whether the package's .gitignore must ignore it, otherwise it
     *                                  is committed and must not be ignored
     * @param bool   $printedCommand    whether a command the installer prints writes it, otherwise
     *                                  the installer itself does
     */
    public function __construct(
        public string $path,
        public bool $isDirectory,
        public bool $untrackedByDesign,
        public bool $printedCommand = false,
    ) {
    }
}
