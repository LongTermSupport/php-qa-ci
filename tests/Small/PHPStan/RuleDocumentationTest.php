<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Defence for the class "a bundled rule blocks a build without explaining itself".
 *
 * A PHPStan failure hands the practitioner one string: the identifier, e.g.
 * phpqaci.nullCoalescingFalse. Everything they need in order to act on it has to
 * be reachable FROM that string, offline, from the installed package. Two ways
 * that breaks, both of which had happened when this guard was written:
 *
 *  1. A rule points its docblock at docs/phpstan-rules/<name>.md and the file
 *     does not exist. A dangling reference is worse than no reference, because
 *     it consumes the reader's attention before failing them.
 *  2. A rule ships an identifier that is absent from the identifier index, so
 *     the only lookup the practitioner can actually perform returns nothing.
 *
 * Neither is visible from inside a code review of the rule itself, which is why
 * this is a mechanical check rather than a convention.
 *
 * Every source file is scanned, not the PHPStan rules alone: a pipeline lane that
 * prints an identifier of its own is held to the same index (toolchain
 * specification 10.1). EXAMPLE_IDENTIFIERS lists identifiers that appear in source
 * only as illustrative examples inside docblocks and failure messages.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
#[\PHPUnit\Framework\Attributes\Small]
final class RuleDocumentationTest extends TestCase
{
    private const string REPO_ROOT = __DIR__ . '/../../..';

    private const string SRC_DIR = self::REPO_ROOT . '/src';

    private const string INDEX = self::REPO_ROOT . '/docs/phpstan-rules/README.md';

    /** @var list<string> */
    private const array EXAMPLE_IDENTIFIERS = [
        'phpqaci.myCheck',
        'phpqaci.something',
    ];

    public function testEveryReferencedRemediationDocumentExists(): void
    {
        $missing = [];

        foreach ($this->ruleSourceFiles() as $path => $contents) {
            foreach ($this->matchAllGroup('#docs/phpstan-rules/[a-z0-9-]+\.md#', $contents, 0) as $reference) {
                if (is_file(self::REPO_ROOT . '/' . $reference)) {
                    continue;
                }

                $missing[] = \sprintf('%s references %s', basename($path), $reference);
            }
        }

        self::assertSame(
            [],
            $missing,
            "Rules reference remediation documentation that does not exist:\n"
            . implode("\n", $missing),
        );
    }

    public function testEveryRuleIdentifierAppearsInTheIndex(): void
    {
        $index   = \Safe\file_get_contents(self::INDEX);
        $missing = [];

        foreach ($this->declaredIdentifiers() as $identifier) {
            if (str_contains($index, $identifier)) {
                continue;
            }

            $missing[] = $identifier;
        }

        self::assertSame(
            [],
            $missing,
            "Identifiers absent from docs/phpstan-rules/README.md:\n" . implode("\n", $missing)
            . "\n\nA practitioner is given the identifier and nothing else, so an index that "
            . 'omits one leaves them with no lookup they can perform.',
        );
    }

    public function testIndexDoesNotListIdentifiersThatNoRuleDeclares(): void
    {
        $index    = \Safe\file_get_contents(self::INDEX);
        $declared = $this->declaredIdentifiers();
        $stale    = [];

        foreach (array_unique($this->matchAllGroup('#`(phpqaci\.[A-Za-z]+)`#', $index, 1)) as $indexed) {
            if (\in_array($indexed, $declared, true)) {
                continue;
            }

            $stale[] = $indexed;
        }

        self::assertSame(
            [],
            $stale,
            "Index lists identifiers no rule declares (renamed or removed):\n" . implode("\n", $stale),
        );
    }

    /**
     * @return list<string>
     */
    private function declaredIdentifiers(): array
    {
        $identifiers = [];

        foreach ($this->ruleSourceFiles() as $contents) {
            // The sanctioned form: a constant composed from RuleIdentifierInterface::PREFIX.
            foreach ($this->matchAllGroup("#PREFIX \\. '(\\.[A-Za-z]+)'#", $contents, 1) as $suffix) {
                $identifiers['phpqaci' . $suffix] = true;
            }

            // The magic-string form, so a rule that bypasses the constant is still indexed.
            foreach ($this->matchAllGroup("#->identifier\\('(phpqaci\\.[A-Za-z]+)'\\)#", $contents, 1) as $literal) {
                $identifiers[$literal] = true;
            }
        }

        foreach (self::EXAMPLE_IDENTIFIERS as $example) {
            unset($identifiers[$example]);
        }

        $unique = array_keys($identifiers);
        sort($unique);

        return $unique;
    }

    /**
     * @return array<string, string> absolute path => file contents
     */
    private function ruleSourceFiles(): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            self::SRC_DIR,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $files[$file->getPathname()] = \Safe\file_get_contents($file->getPathname());
        }

        ksort($files);
        self::assertNotSame([], $files, 'No sources found — the directory path is wrong.');

        return $files;
    }

    /**
     * Runs preg_match_all and returns one capture group from every match,
     * filtered to genuine strings. A runtime guard against
     * \Safe\preg_match_all's loosely-typed $matches out-param, so callers get
     * a real list<string> without a suppression.
     *
     * @return list<string>
     */
    private function matchAllGroup(string $pattern, string $subject, int $group): array
    {
        \Safe\preg_match_all($pattern, $subject, $matches);

        $captured = $matches[$group] ?? [];
        if (!\is_array($captured)) {
            return [];
        }

        $strings = [];
        foreach ($captured as $value) {
            if (\is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
