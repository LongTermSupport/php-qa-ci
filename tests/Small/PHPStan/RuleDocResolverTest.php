<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use InvalidArgumentException;
use LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto;
use LTS\PHPQA\PHPStan\RuleDocResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The resolver is the mechanism behind `bin/rule-doc <identifier>`: the one
 * string a PHPStan failure prints must lead, offline and from the installed
 * package, to the rule's documentation. These tests pin that contract and
 * audit it over every identifier the index publishes.
 *
 * @internal
 */
#[CoversClass(RuleDocResolver::class)]
#[UsesClass(RuleDocEntryDto::class)]
#[Small]
final class RuleDocResolverTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    #[Test]
    public function anIdentifierWithARemediationPageResolvesToThatPage(): void
    {
        $entry = $this->resolver()->resolve('phpqaci.emptyCatchBlock');

        self::assertSame('ForbidEmptyCatchBlockRule', $entry->ruleClass);
        self::assertNotNull($entry->docPath);
        self::assertFileExists($entry->docPath);
        self::assertStringEndsWith('docs/phpstan-rules/forbid-empty-catch-block.md', $entry->docPath);
        self::assertFileExists($entry->sourcePath);
    }

    #[Test]
    public function anIdentifierWithoutAPageStillResolvesToItsIndexEntry(): void
    {
        $entry = $this->resolver()->resolve('phpqaci.nestedTernary');

        self::assertSame('ForbidNestedTernaryRule', $entry->ruleClass);
        self::assertNull($entry->docPath);
        self::assertSame('No nested ternary expressions', $entry->summary);
        self::assertFileExists($entry->sourcePath);
    }

    #[Test]
    public function anUnknownIdentifierIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('phpqaci.noSuchRule');

        $this->resolver()->resolve('phpqaci.noSuchRule');
    }

    #[Test]
    public function everyPublishedIdentifierResolvesAndEveryLinkedPageExists(): void
    {
        $resolver    = $this->resolver();
        $identifiers = $resolver->identifiers();
        self::assertNotSame([], $identifiers);

        foreach ($identifiers as $identifier) {
            $entry = $resolver->resolve($identifier);
            self::assertFileExists($entry->sourcePath, $identifier . ' names a rule class that is not on disk');
            if (null !== $entry->docPath) {
                self::assertFileExists($entry->docPath, $identifier . ' links a remediation page that is not on disk');
            }
        }
    }

    #[Test]
    public function renderingAnEntryLeadsWithTheIdentifierAndIncludesThePage(): void
    {
        $rendered = $this->resolver()->render('phpqaci.emptyCatchBlock');

        self::assertStringStartsWith('phpqaci.emptyCatchBlock', $rendered);
        self::assertStringContainsString('ForbidEmptyCatchBlockRule', $rendered);
        self::assertStringContainsString('## The correct construction', $rendered);
    }

    private function resolver(): RuleDocResolver
    {
        return new RuleDocResolver(self::REPO_ROOT);
    }
}
