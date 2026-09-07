<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use LTS\PHPQA\PHPStan\ActiveRulesLister;
use LTS\PHPQA\PHPStan\Dto\ProjectRecordEntryDto;
use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Exact-output coverage for ActiveRulesLister's private parsing/derivation
 * internals, invoked directly via reflection. Companion to
 * ActiveRulesListerTest (which exercises the public list()/renderText()/
 * renderJson() surface end to end) — this file pins the individual regex
 * branches, the opt-in derivation rule, the doc-table join, the
 * justification-comment recovery, and the class-name resolution helper that
 * ActiveRulesListerTest's loose ("contains"/"not empty") assertions do not.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ActiveRulesLister::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ActiveDefencesListingDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\PipelineLaneDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ProjectRecordEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\Dto\RuleDocEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\RuleDocResolver::class)]
#[\PHPUnit\Framework\Attributes\Small]
final class ActiveRulesListerParsingTest extends TestCase
{
    private const string QA_CI_ROOT = __DIR__ . '/../../..';

    private ActiveRulesLister $lister;

    /** @var ReflectionClass<ActiveRulesLister> */
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->lister     = new ActiveRulesLister(self::QA_CI_ROOT);
        $this->reflection = new ReflectionClass($this->lister);
    }

    // -- parseBashIndexedArray -----------------------------------------

    public function testParseBashIndexedArraySingleEntry(): void
    {
        $contents = "FOO=(\n  bar\n)\n";
        self::assertSame(['bar'], $this->invoke('parseBashIndexedArray', $contents, 'FOO'));
    }

    public function testParseBashIndexedArrayMultipleEntriesWithCommentsAndBlankLines(): void
    {
        $contents = "FOO=(\n  bar\n  # a comment line\n\n  baz # trailing comment\n)\n";
        self::assertSame(['bar', 'baz'], $this->invoke('parseBashIndexedArray', $contents, 'FOO'));
    }

    public function testParseBashIndexedArrayEmptyArray(): void
    {
        $contents = "FOO=(\n)\n";
        self::assertSame([], $this->invoke('parseBashIndexedArray', $contents, 'FOO'));
    }

    public function testParseBashIndexedArrayDoesNotMatchAssocArrayOfSameName(): void
    {
        $contents = "declare -A FOO=(\n  [a]=b\n)\n";
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Could not find bash indexed array FOO in the tool registry.');
        $this->invoke('parseBashIndexedArray', $contents, 'FOO');
    }

    public function testParseBashIndexedArrayMissingThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Could not find bash indexed array MISSING in the tool registry.');
        $this->invoke('parseBashIndexedArray', 'no arrays here', 'MISSING');
    }

    /**
     * The var name is preg_quote()'d before being spliced into the regex. A
     * var name containing a regex metacharacter (here '.') proves this: an
     * UNQUOTED '.' would match any character, so "FOOXBAR=(...)" would
     * WRONGLY match a search for "FOO.BAR". With quoting it correctly does
     * not match, so this call throws (var name not found).
     */
    public function testParseBashIndexedArrayVarNameIsRegexEscaped(): void
    {
        $contents = "FOOXBAR=(\n  entry\n)\n";
        $this->expectException(RuntimeException::class);
        $this->invoke('parseBashIndexedArray', $contents, 'FOO.BAR');
    }

    // -- parseBashAssocArray --------------------------------------------

    public function testParseBashAssocArraySingleEntry(): void
    {
        $contents = "declare -A FOO=(\n  [alpha]=beta\n)\n";
        self::assertSame(['alpha' => 'beta'], $this->invoke('parseBashAssocArray', $contents, 'FOO'));
    }

    public function testParseBashAssocArrayMultipleEntriesQuotedAndUnquoted(): void
    {
        $contents = "declare -A FOO=(\n  [alpha]=\"beta gamma\"\n  [delta]=epsilon\n)\n";
        self::assertSame(
            ['alpha' => 'beta gamma', 'delta' => 'epsilon'],
            $this->invoke('parseBashAssocArray', $contents, 'FOO'),
        );
    }

    public function testParseBashAssocArraySkipsMalformedLines(): void
    {
        $contents = "declare -A FOO=(\n  not a valid entry\n  [alpha]=beta\n  =missingKey\n)\n";
        self::assertSame(['alpha' => 'beta'], $this->invoke('parseBashAssocArray', $contents, 'FOO'));
    }

    public function testParseBashAssocArrayEmpty(): void
    {
        $contents = "declare -A FOO=(\n)\n";
        self::assertSame([], $this->invoke('parseBashAssocArray', $contents, 'FOO'));
    }

    public function testParseBashAssocArrayMissingThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Could not find bash associative array MISSING in the tool registry.');
        $this->invoke('parseBashAssocArray', 'no arrays here', 'MISSING');
    }

    public function testParseBashAssocArrayVarNameIsRegexEscaped(): void
    {
        $contents = "declare -A FOOXBAR=(\n  [alpha]=beta\n)\n";
        $this->expectException(RuntimeException::class);
        $this->invoke('parseBashAssocArray', $contents, 'FOO.BAR');
    }

    // -- unquoteBashValue (via parseBashAssocArray, its only caller) ----

    public function testUnquoteBashValueHandlesEscapedQuoteAndBackslash(): void
    {
        $contents = "declare -A FOO=(\n  [alpha]=\"a \\\"quoted\\\" value with a \\\\\\\\ backslash\"\n)\n";
        $result   = $this->invoke('parseBashAssocArray', $contents, 'FOO');
        self::assertIsArray($result);
        self::assertSame('a "quoted" value with a \\\ backslash', $result['alpha']);
    }

    public function testUnquoteBashValueLeavesUnquotedValueAsIs(): void
    {
        $contents = "declare -A FOO=(\n  [alpha]=bareword\n)\n";
        $result   = $this->invoke('parseBashAssocArray', $contents, 'FOO');
        self::assertIsArray($result);
        self::assertSame('bareword', $result['alpha']);
    }

    // -- optInVariable ----------------------------------------------------

    public function testOptInVariableFromGateOtherThanNotQuick(): void
    {
        self::assertSame('useInfection', $this->invoke('optInVariable', 'infection', ''));
    }

    public function testOptInVariableNullWhenGateIsNotQuick(): void
    {
        self::assertNull($this->invoke('optInVariable', 'notQuick', 'usage text with no use marker'));
    }

    public function testOptInVariableFromUsageTextWhenNoGate(): void
    {
        self::assertSame(
            'useArkitect',
            $this->invoke('optInVariable', null, 'arch|arkitect::PHPArkitect (on by default; useArkitect=0 to disable)'),
        );
    }

    public function testOptInVariableNullWhenNeitherConventionPresent(): void
    {
        self::assertNull($this->invoke('optInVariable', null, 'plain description with no use marker at all'));
    }

    public function testOptInVariableGateTakesPrecedenceOverUsageText(): void
    {
        self::assertSame(
            'useInfection',
            $this->invoke('optInVariable', 'infection', 'usage mentioning useSomethingElse'),
        );
    }

    // -- pipelineLanesDocTable --------------------------------------------

    public function testPipelineLanesDocTableJoinsByAliasWhenTablePresent(): void
    {
        $lister = new ActiveRulesLister(__DIR__ . '/../../assets/ActiveRulesLister/qaCiRootWithDocTable');
        $result = new ReflectionClass($lister)->getMethod('pipelineLanesDocTable')
            ->invoke($lister, 'phpunit', 'infection', 'unknownLane')
        ;

        self::assertSame(
            [
                'phpunit'   => ['identifier' => 'phpqaci.phpunit'],
                'infection' => ['identifier' => 'phpqaci.infection'],
            ],
            $result,
        );
    }

    public function testPipelineLanesDocTableReturnsEmptyWhenReadmeAbsent(): void
    {
        // The real QA_CI_ROOT (this repo) has no docs/phpstan-rules/README.md
        // "## Pipeline lanes" section on this branch.
        $result = $this->invoke('pipelineLanesDocTable', 'phpunit', 'infection');
        self::assertSame([], $result);
    }

    // -- justificationAbove -------------------------------------------------

    public function testJustificationAboveFindsCommentDirectlyAbove(): void
    {
        $raw = "# Justification: legacy code.\nneedleLine: 1\n";
        self::assertSame('Justification: legacy code.', $this->invoke('justificationAbove', 'needleLine', $raw));
    }

    public function testJustificationAboveNullWhenNoCommentAbove(): void
    {
        $raw = "someOtherLine: 1\nneedleLine: 1\n";
        self::assertNull($this->invoke('justificationAbove', 'needleLine', $raw));
    }

    public function testJustificationAboveSkipsBlankLinesBetweenCommentAndEntry(): void
    {
        $raw = "# Justification: still counts.\n\n\nneedleLine: 1\n";
        self::assertSame('Justification: still counts.', $this->invoke('justificationAbove', 'needleLine', $raw));
    }

    public function testJustificationAboveDoesNotBleedAcrossEntries(): void
    {
        $raw = "# Justification: belongs to the OTHER entry.\notherNeedle: 1\nneedleLine: 1\n";
        self::assertNull($this->invoke('justificationAbove', 'needleLine', $raw));
    }

    public function testJustificationAboveSkipsBareListMarkerLine(): void
    {
        $raw = "# Justification: above the dash.\n-\nneedleLine: 1\n";
        self::assertSame('Justification: above the dash.', $this->invoke('justificationAbove', 'needleLine', $raw));
    }

    public function testJustificationAboveEmptyNeedleReturnsNull(): void
    {
        self::assertNull($this->invoke('justificationAbove', '', "# comment\nneedleLine: 1\n"));
    }

    // -- parseIgnoreError -----------------------------------------------

    public function testParseIgnoreErrorAnchorsOnIdentifierFirst(): void
    {
        $raw    = "# Justification: found via identifier.\nidentifier: some.identifier\npath: some/path.php\n";
        $entry  = ['identifier' => 'some.identifier', 'path' => 'some/path.php'];
        $result = $this->invoke('parseIgnoreError', $entry, $raw);
        self::assertInstanceOf(ProjectRecordEntryDto::class, $result);
        self::assertSame('Justification: found via identifier.', $result->justification);
    }

    public function testParseIgnoreErrorAnchorsOnMessageWhenIdentifierAbsent(): void
    {
        $raw    = "# Justification: found via message.\nmessage: some message text\npath: some/path.php\n";
        $entry  = ['message' => 'some message text', 'path' => 'some/path.php'];
        $result = $this->invoke('parseIgnoreError', $entry, $raw);
        self::assertInstanceOf(ProjectRecordEntryDto::class, $result);
        self::assertSame('Justification: found via message.', $result->justification);
    }

    public function testParseIgnoreErrorAnchorsOnPathWhenIdentifierAndMessageAbsent(): void
    {
        $raw    = "# Justification: found via path.\npath: some/path.php\n";
        $entry  = ['path' => 'some/path.php'];
        $result = $this->invoke('parseIgnoreError', $entry, $raw);
        self::assertInstanceOf(ProjectRecordEntryDto::class, $result);
        self::assertSame('Justification: found via path.', $result->justification);
    }

    public function testParseIgnoreErrorNullAnchorSkipsJustificationLookupEntirely(): void
    {
        // No identifier/message/path at all: the anchor is null, so
        // justificationAbove must not even be invoked (it would otherwise
        // find the comment above the "count: 4" line, which is not this
        // entry's justification).
        $raw    = "# Unrelated comment above a bare count.\ncount: 4\n";
        $entry  = ['count' => 4];
        $result = $this->invoke('parseIgnoreError', $entry, $raw);
        self::assertInstanceOf(ProjectRecordEntryDto::class, $result);
        self::assertNull($result->justification);
        self::assertSame(4, $result->count);
    }

    public function testParseIgnoreErrorNonArrayNonStringEntryYieldsAllNullFields(): void
    {
        $result = $this->invoke('parseIgnoreError', 42, "# comment\nsomething\n");
        self::assertInstanceOf(ProjectRecordEntryDto::class, $result);
        self::assertNull($result->identifier);
        self::assertNull($result->message);
        self::assertNull($result->path);
        self::assertNull($result->count);
        self::assertNull($result->raw);
        self::assertNull($result->justification);
    }

    // -- resolvedClassName --------------------------------------------------

    public function testResolvedClassNameReturnsSelfLiteral(): void
    {
        self::assertSame('self', $this->invoke('resolvedClassName', new Node\Name('self')));
    }

    public function testResolvedClassNameReturnsStaticLiteral(): void
    {
        self::assertSame('static', $this->invoke('resolvedClassName', new Node\Name('static')));
    }

    public function testResolvedClassNameUsesResolvedAttributeWhenPresent(): void
    {
        $name     = new Node\Name('Foo');
        $resolved = new Node\Name\FullyQualified('My\Fully\Qualified\Foo');
        $name->setAttribute('resolvedName', $resolved);
        self::assertSame('My\Fully\Qualified\Foo', $this->invoke('resolvedClassName', $name));
    }

    public function testResolvedClassNameFallsBackToNameTextWithoutResolvedAttribute(): void
    {
        self::assertSame('Foo', $this->invoke('resolvedClassName', new Node\Name('Foo')));
    }

    public function testResolvedClassNameReturnsNullForNonNameNode(): void
    {
        self::assertNull($this->invoke('resolvedClassName', new Node\Scalar\String_('not-a-name')));
    }

    /**
     * A resolvedName attribute set to something OTHER than 'self'/'static'
     * distinguishes the early-return branch from the attribute fallback: if
     * the 'self'/'static' check is skipped (LogicalOr mutated to And, or the
     * early return removed), the method would return the resolvedName's
     * DIFFERENT text instead of the literal 'self'.
     */
    public function testResolvedClassNameReturnsSelfLiteralEvenWithADifferentResolvedNameAttribute(): void
    {
        $name = new Node\Name('self');
        $name->setAttribute('resolvedName', new Node\Name\FullyQualified('Some\Other\Class'));
        self::assertSame('self', $this->invoke('resolvedClassName', $name));
    }

    public function testResolvedClassNameReturnsStaticLiteralEvenWithADifferentResolvedNameAttribute(): void
    {
        $name = new Node\Name('static');
        $name->setAttribute('resolvedName', new Node\Name\FullyQualified('Some\Other\Class'));
        self::assertSame('static', $this->invoke('resolvedClassName', $name));
    }

    // -- classFilePath ------------------------------------------------------

    public function testClassFilePathReturnsNullForAnUnknownClass(): void
    {
        self::assertNull($this->invoke('classFilePath', 'Totally\Unknown\ClassThatDoesNotExist'));
    }

    public function testClassFilePathResolvesARealAutoloadableClass(): void
    {
        $path = $this->invoke('classFilePath', self::class);
        self::assertIsString($path);
        self::assertSame(\Safe\realpath(__FILE__), \Safe\realpath($path));
    }

    // -- scalar (non-list) neon values are still followed -------------------

    public function testScalarIncludesRulesAndNonArrayServiceEntriesAreHandledCorrectly(): void
    {
        $listing = $this->lister->list(__DIR__ . '/../../assets/ActiveRulesLister/scalarCastProject');

        $ruleClasses = array_map(
            static fn (\LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto $rule): string => $rule->ruleClass,
            $listing->rules,
        );

        self::assertSame(
            [
                \LTS\PHPQA\PHPStan\Rules\ForbidDangerousFunctionsRule::class,
                \LTS\PHPQA\Tests\Assets\ActiveRulesLister\FixtureProjectRule::class,
            ],
            $ruleClasses,
        );
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflectionMethod = $this->reflection->getMethod($method);

        return $reflectionMethod->invoke($this->lister, ...$args);
    }
}
