<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\ManagedSource;

use LTS\PHPQA\ManagedSource\ManagedSourceGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the php-qa-ci managed-source generator: namespace/src resolution from
 * a consumer's composer.json, the rendered managed-file set, and the
 * generate/check (drift) round-trip against a temp project.
 *
 * @internal
 */
#[CoversClass(ManagedSourceGenerator::class)]
#[Small]
final class ManagedSourceGeneratorTest extends TestCase
{
    private const string SRC_PREFIX = 'src/';

    private const string PHP_QA_CI_FACTORY_SEALED_BY_PHP = 'PhpQaCi/FactorySealedBy.php';

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/phpqaci-managed-' . \Safe\getmypid() . '-' . uniqid();
        \Safe\mkdir($this->projectRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->projectRoot);
    }

    public function testResolveTargetTakesTheRuntimePsr4RootAndItsSrcDir(): void
    {
        $composer = [
            'autoload'     => ['psr-4' => ['Ballicom\AccountsIq\\' => self::SRC_PREFIX]],
            'autoload-dev' => ['psr-4' => ['Ballicom\AccountsIq\Dev\\' => 'src-dev/']],
        ];

        $target = ManagedSourceGenerator::resolveTarget($composer);

        self::assertSame('Ballicom\AccountsIq', $target['rootNamespace'], 'trailing separator trimmed');
        self::assertSame('src', $target['srcDir'], 'runtime autoload src dir, never autoload-dev');
    }

    public function testResolveTargetAcceptsAnArrayPsr4Value(): void
    {
        $composer = ['autoload' => ['psr-4' => ['Acme\Lib\\' => ['lib/', 'extra/']]]];

        $target = ManagedSourceGenerator::resolveTarget($composer);

        self::assertSame('Acme\Lib', $target['rootNamespace']);
        self::assertSame('lib', $target['srcDir'], 'first path of an array value');
    }

    public function testManagedFilesRendersFactorySealedByInThePhpQaCiSubNamespace(): void
    {
        $files = new ManagedSourceGenerator()->managedFiles('Ballicom\AccountsIq');

        self::assertArrayHasKey(self::PHP_QA_CI_FACTORY_SEALED_BY_PHP, $files);
        $body = $files['PhpQaCi/FactorySealedBy.php'];

        self::assertStringContainsString('declare(strict_types=1);', $body);
        self::assertStringContainsString('namespace Ballicom\AccountsIq\PhpQaCi;', $body);
        self::assertStringContainsString('#[Attribute(Attribute::TARGET_CLASS)]', $body);
        self::assertStringContainsString('final readonly class FactorySealedBy', $body);
        self::assertStringContainsString('@internal', $body, "classified @internal so a type:library consumer's API-surface rule passes over the managed tree");
        self::assertStringContainsString('GENERATED', $body, 'carries a managed/do-not-edit header');
        self::assertStringContainsString(\LTS\PHPQA\PHPStan\Rules\FactorySealedRule::class, $body, 'points at the enforcing rule');
    }

    public function testGenerateWritesTheManagedTreeAndCheckThenReportsNoDrift(): void
    {
        $this->writeComposerJson(['autoload' => ['psr-4' => ['Ballicom\AccountsIq\\' => self::SRC_PREFIX]]]);

        $written = new ManagedSourceGenerator()->generate($this->projectRoot);

        $expected = $this->projectRoot . '/src/PhpQaCi/FactorySealedBy.php';
        self::assertContains($expected, $written);
        self::assertFileExists($expected);
        self::assertSame([], new ManagedSourceGenerator()->check($this->projectRoot), 'freshly generated tree has no drift');
    }

    public function testCheckReportsDriftWhenAManagedFileIsHandEdited(): void
    {
        $this->writeComposerJson(['autoload' => ['psr-4' => ['Ballicom\AccountsIq\\' => self::SRC_PREFIX]]]);
        $generator = new ManagedSourceGenerator();
        $generator->generate($this->projectRoot);

        \Safe\file_put_contents($this->projectRoot . '/src/PhpQaCi/FactorySealedBy.php', "<?php\n// tampered\n");

        self::assertSame([self::PHP_QA_CI_FACTORY_SEALED_BY_PHP], $generator->check($this->projectRoot), 'a hand edit is detected as drift');
    }

    public function testCheckReportsDriftWhenAManagedFileIsMissing(): void
    {
        $this->writeComposerJson(['autoload' => ['psr-4' => ['Ballicom\AccountsIq\\' => self::SRC_PREFIX]]]);

        self::assertSame([self::PHP_QA_CI_FACTORY_SEALED_BY_PHP], new ManagedSourceGenerator()->check($this->projectRoot), 'a never-generated tree is drift');
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function writeComposerJson(array $composer): void
    {
        \Safe\file_put_contents(
            $this->projectRoot . '/composer.json',
            \Safe\json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    private function deleteRecursive(string $path): void
    {
        if (is_file($path)) {
            \Safe\unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (\Safe\scandir($path) as $entry) {
            if (!\is_string($entry)) {
                continue;
            }

            if ('.' === $entry) {
                continue;
            }

            if ('..' === $entry) {
                continue;
            }

            $this->deleteRecursive($path . '/' . $entry);
        }

        \Safe\rmdir($path);
    }
}
