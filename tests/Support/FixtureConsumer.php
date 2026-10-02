<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A throwaway consumer project with php-qa-ci installed under
 * vendor/lts/php-qa-ci, for tests that run the real bin/qa end to end. Running
 * against a fixture consumer (never this repository) keeps such a test off
 * this repository's run lock, so it cannot collide with a concurrent bin/qa
 * here.
 *
 * PHP resolves __DIR__ through symlinks, so a symlinked library would find
 * THIS repository's autoloader and treat this repository as the project. The
 * consumer therefore gets a real bin/ directory holding copies of the
 * entrypoint and its bootstrap, a vendor/autoload.php that delegates to the
 * real one, and symlinks for everything else the pipeline reads from the
 * library root (configDefaults, vendor-phar, phive.xml, ...).
 */
final readonly class FixtureConsumer
{
    public const string INSTALLED_LIBRARY = 'vendor/lts/php-qa-ci';

    public const string AUTOLOAD = '/vendor/autoload.php';

    private const string LIBRARY_ROOT = __DIR__ . '/../..';

    private const string BIN = '/bin/';

    private function __construct(public TempDir $dir)
    {
    }

    public static function create(string $prefix): self
    {
        $consumer = new self(TempDir::create($prefix));
        $consumer->installLibrary();
        $consumer->dir->write('composer.json', <<<'JSON'
            {
              "name": "fixture/consumer",
              "type": "project",
              "require": { "php": "^8.5" },
              "autoload": { "psr-4": { "Fixture\\": "src/" } }
            }
            JSON);
        $consumer->dir->write('src/Thing.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class Thing {}\n");
        $consumer->dir->mkdir('tests');

        return $consumer;
    }

    /**
     * The consumer's bin/qa as an unstarted process, in CI mode.
     *
     * @param array<string, string> $env
     */
    public function qa(array $env, string ...$args): Process
    {
        return new Process(
            ['php', $this->dir->path . '/' . self::INSTALLED_LIBRARY . '/bin/qa', ...$args],
            $this->dir->path,
            ['CI' => 'true', ...$env],
            null,
            120,
        );
    }

    public function remove(): void
    {
        $this->dir->remove();
    }

    private function installLibrary(): void
    {
        $installed = $this->dir->mkdir(self::INSTALLED_LIBRARY);
        $this->dir->mkdir(self::INSTALLED_LIBRARY . '/bin');
        $libraryRoot = \Safe\realpath(self::LIBRARY_ROOT);

        foreach (['qa', 'bootstrap.php'] as $script) {
            \Safe\copy($libraryRoot . self::BIN . $script, $installed . self::BIN . $script);
            \Safe\chmod($installed . self::BIN . $script, 0o755);
        }

        foreach (\Safe\scandir($libraryRoot) as $entry) {
            if (!\is_string($entry) || \in_array($entry, ['.', '..', 'bin', '.git', 'vendor', 'var', 'untracked'], true)) {
                continue;
            }

            \Safe\symlink($libraryRoot . '/' . $entry, $installed . '/' . $entry);
        }

        $this->dir->write('vendor/autoload.php', \sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn require %s;\n",
            var_export($libraryRoot . self::AUTOLOAD, true),
        ));
    }
}
