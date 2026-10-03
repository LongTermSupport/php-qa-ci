<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use LTS\PHPQA\PHPStan\InstalledPhpstanExtensions;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The configuration phpstan/extension-installer hands PHPStan: every
 * installed package's extra.phpstan.includes, read from the GeneratedConfig
 * class the installer writes, without loading it. This is how php-qa-ci's own
 * bundled rules reach a consuming project, so a listing that skipped it would
 * list none of them there.
 *
 * @internal
 */
#[CoversClass(InstalledPhpstanExtensions::class)]
#[Small]
final class InstalledPhpstanExtensionsTest extends TestCase
{
    private const string GENERATED = 'vendor/phpstan/extension-installer/src/GeneratedConfig.php';

    private const string ACME = 'acme/rules';

    private const string EMPTY_RULES = "rules: []\n";

    private const string RULES_NEON = 'rules.neon';

    private const string ACME_RULES_FILE = 'vendor/acme/rules/rules.neon';

    private const string ACME_INSTALL_PATH = '../../../acme/rules';

    private const string ACME_RULES_UNDER_VENDOR = '/acme/rules/rules.neon';

    private TempDir $project;

    protected function setUp(): void
    {
        $this->project = TempDir::create('phpqa-installed-extensions');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    #[Test]
    public function everyInstalledExtensionsIncludesResolveFromItsInstallPath(): void
    {
        $this->project->write(self::ACME_RULES_FILE, self::EMPTY_RULES);
        $this->project->write('vendor/acme/rules/conf/extension.neon', "parameters: []\n");
        $this->project->write('vendor/lts/php-qa-ci/rules-default.neon', self::EMPTY_RULES);
        $this->generatedConfig([
            self::ACME         => [self::ACME_INSTALL_PATH, [self::RULES_NEON, 'conf/extension.neon']],
            'lts/php-qa-ci'    => ['../../../lts/php-qa-ci', ['rules-default.neon']],
            'no/includes'      => ['../../../no/includes', null],
            'gone/package'     => ['../../../gone/package', [self::RULES_NEON]],
        ]);

        $vendor = $this->vendorDir();
        self::assertSame(
            [
                self::ACME      => [$vendor . self::ACME_RULES_UNDER_VENDOR, $vendor . '/acme/rules/conf/extension.neon'],
                'lts/php-qa-ci' => [$vendor . '/lts/php-qa-ci/rules-default.neon'],
            ],
            new InstalledPhpstanExtensions()->includes($this->project->path),
        );
    }

    #[Test]
    public function aProjectWithoutTheInstallerHasNoExtensions(): void
    {
        self::assertSame([], new InstalledPhpstanExtensions()->includes($this->project->path));
    }

    #[Test]
    public function aCustomVendorDirIsHonoured(): void
    {
        $this->project->write('composer.json', '{"config": {"vendor-dir": "lib"}}');
        $this->project->write('lib/acme/rules/rules.neon', self::EMPTY_RULES);
        $this->generatedConfig([self::ACME => [self::ACME_INSTALL_PATH, [self::RULES_NEON]]], 'lib/phpstan/extension-installer/src/GeneratedConfig.php');

        self::assertSame(
            [self::ACME => [\Safe\realpath($this->project->path . '/lib') . self::ACME_RULES_UNDER_VENDOR]],
            new InstalledPhpstanExtensions()->includes($this->project->path),
        );
    }

    #[Test]
    public function aGeneratedConfigWithoutTheExtensionsConstantHasNoExtensions(): void
    {
        $this->project->write(self::GENERATED, "<?php\nnamespace PHPStan\\ExtensionInstaller;\nfinal class GeneratedConfig\n{\n    public const NOT_INSTALLED = [];\n}\n");

        self::assertSame([], new InstalledPhpstanExtensions()->includes($this->project->path));
    }

    #[Test]
    public function aComposerConfigWithoutAVendorDirFallsBackToVendor(): void
    {
        $this->project->write('composer.json', '{"config": {"sort-packages": true}}');
        $this->project->write(self::ACME_RULES_FILE, self::EMPTY_RULES);
        $this->generatedConfig([self::ACME => [self::ACME_INSTALL_PATH, [self::RULES_NEON]]]);

        self::assertSame(
            [self::ACME => [$this->vendorDir() . self::ACME_RULES_UNDER_VENDOR]],
            new InstalledPhpstanExtensions()->includes($this->project->path),
        );
    }

    /**
     * Each malformed entry is skipped on its own fault, even where following
     * it would land on a real file: a package keyed by a number, and an
     * install path that is not a string (here 7, and a "7" directory exists).
     * An `extra` with no includes contributes nothing, without reading the
     * missing key (failOnWarning makes that read a failure).
     */
    #[Test]
    public function aMalformedExtensionEntryIsSkipped(): void
    {
        $this->project->write(self::ACME_RULES_FILE, self::EMPTY_RULES);
        $this->project->write('vendor/phpstan/extension-installer/src/7/rules.neon', self::EMPTY_RULES);
        $install = var_export(self::ACME_INSTALL_PATH, true);
        $valid   = "array ('relative_install_path' => " . $install . ", 'extra' => array ('includes' => array ('rules.neon')))";
        $this->project->write(
            self::GENERATED,
            "<?php\nnamespace PHPStan\\ExtensionInstaller;\nfinal class GeneratedConfig\n{\n    public const EXTENSIONS = array (\n"
            . '  0 => ' . $valid . ",\n"
            . "  'numeric/path' => array ('relative_install_path' => 7, 'extra' => array ('includes' => array ('rules.neon'))),\n"
            . "  'no/includes' => array ('relative_install_path' => " . $install . ", 'extra' => array ('phpstan' => array ())),\n"
            . "  'acme/kept' => " . $valid . ",\n"
            . "    );\n}\n",
        );

        self::assertSame(
            ['acme/kept' => [$this->vendorDir() . self::ACME_RULES_UNDER_VENDOR]],
            new InstalledPhpstanExtensions()->includes($this->project->path),
        );
    }

    private function vendorDir(): string
    {
        return \Safe\realpath($this->project->path . '/vendor');
    }

    /** @param array<string, array{string, list<string>|null}> $extensions name => [relative install path, includes] */
    private function generatedConfig(array $extensions, string $path = self::GENERATED): void
    {
        $entries = [];
        foreach ($extensions as $name => [$relative, $includes]) {
            $extra     = null === $includes ? 'null' : "array ('includes' => " . var_export($includes, true) . ')';
            $entries[] = \sprintf("  %s => array ('install_path' => '/elsewhere', 'relative_install_path' => %s, 'extra' => %s, 'version' => '1.0.0')", var_export($name, true), var_export($relative, true), $extra);
        }

        $this->project->write(
            $path,
            "<?php declare(strict_types = 1);\n\nnamespace PHPStan\\ExtensionInstaller;\n\nfinal class GeneratedConfig\n{\n    public const EXTENSIONS = array (\n"
            . implode(",\n", $entries) . "\n    );\n\n    public const NOT_INSTALLED = array ();\n}\n",
        );
    }
}
