<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\DeadCode;

use LTS\PHPQA\Filesystem\TemporaryDirectory;
use Phar;

/**
 * The dead-code detector as plain files, unpacked from its PHAR into the project's QA cache
 * once per PHAR content.
 *
 * PHPStan with Turbo active forks its workers, and Turbo guards reads across the fork for
 * phpstan.phar only. A second PHAR opened before the fork is read by every worker through one
 * shared archive descriptor, they race on its cursor, and each parses another's bytes. Plain
 * files have no shared cursor.
 *
 * @internal
 */
final readonly class DetectorUnpacker
{
    /** Under the QA cache directory: one subdirectory per PHAR content. */
    public const string DIR = 'dead-code-detector';

    /** The detector's Composer autoloader, for PHPStan's --autoload-file. */
    public const string AUTOLOAD = 'vendor/autoload.php';

    /** The detector's PHPStan config, for the wrapper neon's includes. */
    public const string RULES_NEON = 'vendor/shipmonk/dead-code-detector/rules.neon';

    /** @return string the directory holding the unpacked detector */
    public function unpack(string $phar, string $cacheDir): string
    {
        $target = $cacheDir . '/' . self::DIR . '/' . substr(\Safe\hash_file('sha256', $phar), 0, 16);
        if (is_file($target . '/' . self::AUTOLOAD)) {
            return $target;
        }

        // Unpacked beside the target and renamed into place, so a copy that exists is complete.
        $staging = TemporaryDirectory::besides($target, '.unpack');

        try {
            new Phar($phar)->extractTo($staging->path);
            \Safe\rename($staging->path, $target);
        } finally {
            $staging->remove();
        }

        return $target;
    }
}
