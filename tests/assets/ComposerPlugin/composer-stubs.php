<?php

declare(strict_types=1);

/*
 * Minimal fake of the Composer runtime API surface used by php-qa-ci's Composer
 * plugins (src/ComposerPlugin/*).
 *
 * WHY THIS EXISTS: composer/composer is NOT a dependency of this package (only
 * composer-plugin-api is required, which ships no classes). The real Composer\*
 * classes only exist inside a running `composer` process, so under phpunit they
 * are absent and the plugins cannot even be loaded (they `implements
 * Composer\Plugin\PluginInterface`). This file supplies just enough of that
 * surface — guarded by *_exists() so it never clashes with the real Composer if
 * it is ever present — for the plugin unit tests to construct the objects the
 * plugins consume.
 *
 * It lives under tests/assets/ so the QA pipeline ignores it (psr4-validate
 * ignore list, phpstan excludePaths, lint pathsToIgnore all cover tests/assets).
 * It is require_once'd explicitly by the plugin tests; it is never autoloaded.
 */

namespace Composer\IO {
    if (!\interface_exists(IOInterface::class, false)) {
        interface IOInterface
        {
            public function write($messages, bool $newline = true, int $verbosity = 1): void;

            public function writeError($messages, bool $newline = true, int $verbosity = 1): void;
        }
    }
}

namespace Composer {
    if (!\class_exists(Config::class, false)) {
        final class Config
        {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values = [])
            {
            }

            public function get(string $key): mixed
            {
                return $this->values[$key] ?? null;
            }
        }
    }
}

namespace Composer\Package {
    if (!\interface_exists(RootPackageInterface::class, false)) {
        interface RootPackageInterface
        {
            public function getName(): string;

            /** @return array<string, mixed> */
            public function getRequires(): array;

            /** @return array<string, mixed> */
            public function getDevRequires(): array;
        }
    }
}

namespace Composer {
    if (!\class_exists(Composer::class, false)) {
        final class Composer
        {
            public function __construct(
                private \Composer\Config $config,
                private \Composer\Package\RootPackageInterface $package,
            ) {
            }

            public function getConfig(): \Composer\Config
            {
                return $this->config;
            }

            public function getPackage(): \Composer\Package\RootPackageInterface
            {
                return $this->package;
            }
        }
    }
}

namespace Composer\Plugin {
    if (!\interface_exists(PluginInterface::class, false)) {
        interface PluginInterface
        {
            public function activate(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void;

            public function deactivate(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void;

            public function uninstall(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void;
        }
    }
}

namespace Composer\EventDispatcher {
    if (!\interface_exists(EventSubscriberInterface::class, false)) {
        interface EventSubscriberInterface
        {
            /** @return array<string, mixed> */
            public static function getSubscribedEvents(): array;
        }
    }
}

namespace Composer\Script {
    if (!\class_exists(ScriptEvents::class, false)) {
        final class ScriptEvents
        {
            public const string POST_INSTALL_CMD = 'post-install-cmd';

            public const string POST_UPDATE_CMD = 'post-update-cmd';
        }
    }

    if (!\class_exists(Event::class, false)) {
        final class Event
        {
            public function __construct(
                private \Composer\Composer $composer,
                private \Composer\IO\IOInterface $io,
            ) {
            }

            public function getComposer(): \Composer\Composer
            {
                return $this->composer;
            }

            public function getIO(): \Composer\IO\IOInterface
            {
                return $this->io;
            }
        }
    }
}

namespace Composer\Util {
    if (!\class_exists(ProcessExecutor::class, false)) {
        final class ProcessExecutor
        {
            public function __construct(?\Composer\IO\IOInterface $io = null)
            {
            }

            /** @param string|null $output */
            public function execute(string $command, &$output = null, ?string $cwd = null): int
            {
                $output = '';

                return 1;
            }
        }
    }
}

namespace Composer\Semver {
    if (!\class_exists(Semver::class, false)) {
        final class Semver
        {
            public static function satisfies(string $version, string $constraints): bool
            {
                return true;
            }
        }
    }
}

namespace LTS\PHPQA\Tests\Assets\ComposerPlugin {
    /**
     * IOInterface test double that records every message written to it, so a
     * test can assert exactly what a plugin reported without a real Composer IO.
     */
    final class CapturingIO implements \Composer\IO\IOInterface
    {
        /** @var list<string> */
        public array $out = [];

        /** @var list<string> */
        public array $err = [];

        public function write($messages, bool $newline = true, int $verbosity = 1): void
        {
            foreach ((array) $messages as $message) {
                $this->out[] = $message;
            }
        }

        public function writeError($messages, bool $newline = true, int $verbosity = 1): void
        {
            foreach ((array) $messages as $message) {
                $this->err[] = $message;
            }
        }

        public function allText(): string
        {
            return \implode("\n", [...$this->out, ...$this->err]);
        }
    }

    /**
     * RootPackageInterface test double.
     */
    final class FakeRootPackage implements \Composer\Package\RootPackageInterface
    {
        /**
         * @param array<string, mixed> $requires
         * @param array<string, mixed> $devRequires
         */
        public function __construct(
            private string $name = 'acme/consumer',
            private array $requires = [],
            private array $devRequires = [],
        ) {
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getRequires(): array
        {
            return $this->requires;
        }

        public function getDevRequires(): array
        {
            return $this->devRequires;
        }
    }
}
