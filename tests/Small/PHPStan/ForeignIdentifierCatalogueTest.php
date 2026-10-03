<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto;
use LTS\PHPQA\PHPStan\RuleDocResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Detector specification 6.2 and 6.3, for the detectors php-qa-ci routes
 * defences through but did not write: PHPStan itself and the extensions this
 * package installs. Every identifier one of them can print resolves through
 * `rule-doc` to a page on disk, at the version installed, with no network.
 *
 * PHPStan's own pages are carried from phpstan/phpstan at the tag of the
 * shipped phpstan.phar; an extension whose upstream publishes no page has one
 * written here. The identifiers an extension can print are read from its
 * installed source, where they are literals; the few it builds at run time
 * are in PHPStan's catalogue, which PHPStan generates from the same source.
 *
 * @internal
 */
#[CoversClass(RuleDocResolver::class)]
#[UsesClass(RuleDocEntryDto::class)]
#[Small]
final class ForeignIdentifierCatalogueTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    /** The installed extensions whose rules php-qa-ci delivers to every consumer. */
    private const array EXTENSION_SOURCES = [
        'vendor/phpstan/phpstan-strict-rules/src',
        'vendor/phpstan/phpstan-phpunit/src',
        'vendor/phpstan/phpstan-deprecation-rules/src',
        'vendor/tomasvotruba/type-coverage/src',
    ];

    /** A literal identifier, passed to identifier() or held in an IDENTIFIER constant. */
    private const string LITERAL_IDENTIFIER = "/(?:identifier\\(\\s*|IDENTIFIER\\s*=\\s*)'([A-Za-z][A-Za-z0-9]*(?:\\.[A-Za-z0-9]+)+)'/";

    #[Test]
    public function aPhpstanIdentifierRendersPhpstansOwnPageOffline(): void
    {
        $rendered = new RuleDocResolver(self::REPO_ROOT)->render('argument.type');

        self::assertStringStartsWith('argument.type', $rendered);
        self::assertStringContainsString('## How to fix it', $rendered);
        self::assertStringContainsString('PHPStan ' . $this->installedPhpstanVersion(), $rendered);
    }

    #[Test]
    public function everyIdentifierAnInstalledExtensionDeclaresResolvesToAPage(): void
    {
        $resolver = new RuleDocResolver(self::REPO_ROOT);

        $unresolved = [];
        foreach ($this->extensionIdentifiers() as $identifier) {
            $page = $resolver->docPathFor($identifier) ?? $resolver->foreignDocPath($identifier);
            if (null === $page || !is_file($page)) {
                $unresolved[] = $identifier;
            }
        }

        self::assertNotSame([], $this->extensionIdentifiers(), 'the extension sources yielded no identifiers, so this test checked nothing');
        self::assertSame([], $unresolved, 'these identifiers resolve to no page on disk');
    }

    #[Test]
    public function theCarriedCatalogueIsAtTheInstalledPhpstanVersion(): void
    {
        self::assertSame(
            $this->installedPhpstanVersion(),
            trim(\Safe\file_get_contents(self::REPO_ROOT . '/vendor-docs/phpstan/version')),
            'vendor-docs/phpstan/ is not at the version of vendor-phar/phpstan.phar: run bin/phpstan-docs-install update',
        );
    }

    #[Test]
    public function anIdentifierInNoCatalogueStillNamesPhpstansOnlineOne(): void
    {
        $rendered = new RuleDocResolver(self::REPO_ROOT)->render('noSuch.identifierAnywhere');

        self::assertStringStartsWith('noSuch.identifierAnywhere', $rendered);
        self::assertStringContainsString('https://phpstan.org/error-identifiers/noSuch.identifierAnywhere', $rendered);
        self::assertStringContainsString('in none of the catalogues', $rendered);
    }

    #[Test]
    public function anIdentifierThatIsNotOneCannotNameAPathOutsideTheCatalogue(): void
    {
        self::assertNull(new RuleDocResolver(self::REPO_ROOT)->foreignDocPath('../../composer'));
    }

    private function installedPhpstanVersion(): string
    {
        $phive = \Safe\file_get_contents(self::REPO_ROOT . '/phive.xml');
        self::assertSame(1, \Safe\preg_match('/<phar name="phpstan"[^>]*installed="([^"]+)"/', $phive, $match));

        return $match[1];
    }

    /** @return list<string> */
    private function extensionIdentifiers(): array
    {
        $identifiers = [];
        foreach (self::EXTENSION_SOURCES as $source) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::REPO_ROOT . '/' . $source));
            foreach ($files as $file) {
                if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }

                \Safe\preg_match_all(self::LITERAL_IDENTIFIER, \Safe\file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $identifier) {
                    $identifiers[$identifier] = true;
                }
            }
        }

        $identifiers = array_keys($identifiers);
        sort($identifiers);

        return $identifiers;
    }
}
