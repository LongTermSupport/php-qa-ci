<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Pipeline;

use Iterator;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation guard for the consolidated bin/ stubs (M-071).
 *
 * bin/composer-require-checker, bin/infection, bin/php-cs-fixer and
 * bin/phpstan are thin "this tool is managed by php-qa-ci" redirect stubs
 * that share bin/lib-redirect-stub.inc.bash. bin/mdlinks,
 * bin/package-type-check, bin/psr4-validate, bin/sensitive-parameter-usage
 * and bin/managed-source share the Composer-autoload-discovery preamble in
 * bin/bootstrap.php. These are structural (source-content) checks — they
 * lock the SHAPE of the delegation so a future edit cannot silently
 * reintroduce a duplicated autoload block or an unresolvable fragment path.
 * The actual runtime output of every stub (byte-identical to the
 * pre-consolidation copies, including both bootstrap failure-message
 * variants) was verified manually against the pre-change originals when
 * this consolidation landed; see the M-071 task notes.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
#[\PHPUnit\Framework\Attributes\Small]
final class BinStubConsolidationTest extends TestCase
{
    private const string BIN_DIR = __DIR__ . '/../../../bin';

    #[\PHPUnit\Framework\Attributes\DataProvider('provideRedirectStubs')]
    public function testRedirectStubSourcesTheSharedFragmentByResolvedLocationAndCallsIt(
        string $stubName,
        string $expectedLabel,
        string $expectedGuidanceSnippet,
    ): void {
        $stubPath = self::BIN_DIR . '/' . $stubName;
        $contents = \Safe\file_get_contents($stubPath);

        self::assertStringContainsString(
            'lib-redirect-stub.inc.bash',
            $contents,
            \sprintf('Stub %s must source the shared lib-redirect-stub.inc.bash fragment.', $stubName),
        );
        self::assertStringContainsString(
            'BASH_SOURCE',
            $contents,
            \sprintf('Stub %s must resolve the fragment from its own real location (BASH_SOURCE), not cwd.', $stubName),
        );
        self::assertStringContainsString(
            'phpQaCiRedirectStub "' . $expectedLabel . '"',
            $contents,
            \sprintf('Stub %s must call phpQaCiRedirectStub with the label "%s".', $stubName, $expectedLabel),
        );
        self::assertStringContainsString(str_replace('bin/qa', '__QACMD__', $expectedGuidanceSnippet), $contents);
    }

    /** @return Iterator<string, array{string, string, string}> */
    public static function provideRedirectStubs(): Iterator
    {
        yield 'composer-require-checker' => ['composer-require-checker', 'Composer Require Checker', 'bin/qa -t cr'];
        yield 'infection' => ['infection', 'Infection', 'bin/qa -t infection'];
        yield 'php-cs-fixer' => ['php-cs-fixer', 'PHP CS Fixer', 'bin/qa -t fixer'];
        yield 'phpstan' => ['phpstan', 'PHPStan', 'bin/qa -t phpstan'];
    }

    public function testSharedRedirectFragmentDefinesTheFunctionAndExitsOne(): void
    {
        $fragmentPath = self::BIN_DIR . '/lib-redirect-stub.inc.bash';
        $contents     = \Safe\file_get_contents($fragmentPath);
        self::assertStringContainsString('function phpQaCiRedirectStub()', $contents);
        self::assertStringContainsString('exit 1', $contents);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providePhpEntrypoints')]
    public function testPhpEntrypointDelegatesAutoloadDiscoveryToSharedBootstrap(string $entrypointName): void
    {
        $contents = \Safe\file_get_contents(self::BIN_DIR . '/' . $entrypointName);
        self::assertStringContainsString(
            "require __DIR__.'/bootstrap.php';",
            $contents,
            $entrypointName . ' must delegate autoload discovery to the shared bin/bootstrap.php.',
        );
        self::assertStringNotContainsString(
            "autoload.php',",
            $contents,
            $entrypointName . ' must not duplicate the autoload candidate list inline any more.',
        );
    }

    /** @return Iterator<string, array{string}> */
    public static function providePhpEntrypoints(): Iterator
    {
        yield 'mdlinks' => ['mdlinks'];
        yield 'package-type-check' => ['package-type-check'];
        yield 'psr4-validate' => ['psr4-validate'];
        yield 'sensitive-parameter-usage' => ['sensitive-parameter-usage'];
        yield 'managed-source' => ['managed-source'];
    }

    public function testManagedSourceOverridesTheDefaultBootstrapFailureMessage(): void
    {
        $contents = \Safe\file_get_contents(self::BIN_DIR . '/managed-source');
        self::assertStringContainsString(
            '$phpQaCiBootstrapFailureMessage = \'You need to set up the project dependencies using composer install\'.PHP_EOL;',
            $contents,
        );
    }

    public function testSharedBootstrapFileFallsBackToTheDefaultCurlMessageWhenNoOverrideIsSet(): void
    {
        $contents = \Safe\file_get_contents(self::BIN_DIR . '/bootstrap.php');
        self::assertStringContainsString('curl -s http://getcomposer.org/installer | php', $contents);
        self::assertStringContainsString('$phpQaCiBootstrapFailureMessage ??', $contents);
    }
}
