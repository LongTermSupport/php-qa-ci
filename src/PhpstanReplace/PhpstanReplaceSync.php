<?php

declare(strict_types=1);

namespace LTS\PHPQA\PhpstanReplace;

use LTS\PHPQA\PhpstanDocs\PhpstanDocsCatalogue;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Keeps composer.json's `replace` of phpstan/phpstan at the version of the shipped phpstan.phar.
 *
 * PHPStan runs from the phar, so php-qa-ci replaces the Composer package. The replace states which
 * PHPStan Composer may assume is present: at the phar's exact version, Composer resolves only the
 * extension releases the phar can load, where `*` let it install extensions that need a newer
 * PHPStan and the phpstan lane then aborted (#60). So the replace moves with the phar.
 *
 * An install verifies it and never writes. An update, the maintainer path that has just moved the
 * phar, rewrites the one value in place so the rest of composer.json keeps its formatting.
 *
 * @internal
 */
final readonly class PhpstanReplaceSync
{
    /** Verify the replace; never write. */
    public const string MODE_INSTALL = 'install';

    /** The maintainer path: move the replace to the phar's version. */
    public const string MODE_UPDATE = 'update';

    /** The manifest whose replace is kept, relative to the library root. */
    private const string COMPOSER_JSON = '/composer.json';

    /** The value of phpstan/phpstan inside the replace object; group 1 is everything before it. */
    private const string REPLACED_VALUE = '/("replace"\s*:\s*\{[^}]*?"phpstan\/phpstan"\s*:\s*")([^"]*)"/';

    public function __construct(private OutputInterface $output)
    {
    }

    /** @return int the process exit code */
    public static function main(string $libraryRoot, string $mode): int
    {
        return new self(new ConsoleOutput())->run($libraryRoot, $mode);
    }

    public function run(string $libraryRoot, string $mode): int
    {
        $phar = new PhpstanDocsCatalogue($libraryRoot)->installedPhpstanVersion();
        if (null === $phar) {
            return $this->fail('phive.xml records no installed phpstan.phar, so there is no version to replace phpstan/phpstan at.');
        }

        $file     = $libraryRoot . self::COMPOSER_JSON;
        $composer = \Safe\file_get_contents($file);
        if (1 !== \Safe\preg_match(self::REPLACED_VALUE, $composer, $match) || !isset($match[2])) {
            return $this->fail(\sprintf('%s does not replace phpstan/phpstan, so Composer would install PHPStan alongside the phar.', $file));
        }

        $replaced = $match[2];
        if ($replaced === $phar) {
            return 0;
        }

        if (self::MODE_UPDATE !== $mode) {
            return $this->fail(\sprintf(
                "composer.json replaces phpstan/phpstan at %s, but the shipped phpstan.phar is %s.\n"
                . 'This indicates a corrupted or incomplete checkout. A maintainer moves it with: scripts/tool-install.bash update',
                $replaced,
                $phar,
            ));
        }

        \Safe\file_put_contents($file, \Safe\preg_replace(self::REPLACED_VALUE, '${1}' . $phar . '"', $composer, 1));
        $this->output->writeln(\sprintf('phpstan/phpstan replace %s -> %s (composer.json); re-run composer update to resolve the extensions against it', $replaced, $phar));

        return 0;
    }

    private function fail(string $message): int
    {
        $this->output->writeln('<error>' . $message . '</error>');

        return 1;
    }
}
