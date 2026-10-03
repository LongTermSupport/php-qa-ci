<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\PHPStan;

use LTS\PHPQA\DefectRecord\Dto\DefectRecordDto;
use LTS\PHPQA\DefectRecord\Dto\DeferredDefectDto;
use LTS\PHPQA\DefectRecord\Dto\NoPatternConclusionDto;
use LTS\PHPQA\PHPStan\ActiveRulesLister;
use LTS\PHPQA\PHPStan\Dto\ActiveDefencesListingDto;
use LTS\PHPQA\PHPStan\Dto\ActiveRuleEntryDto;
use LTS\PHPQA\PHPStan\Dto\PipelineLaneDto;
use LTS\PHPQA\PHPStan\Dto\ProjectRecordEntryDto;
use PHPUnit\Framework\TestCase;

/**
 * Exact-output coverage for ActiveRulesLister::renderText()/renderJson(),
 * driving them directly with hand-built DTOs (bypassing the filesystem/
 * registry entirely) so every branch — every null-coalesce, every
 * null-check-gated concatenation, every array_map key — can be pinned with
 * full string/array equality rather than the "contains"/"not empty" style
 * assertions ActiveRulesListerTest uses for its end-to-end fixture.
 *
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ActiveRulesLister::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ActiveDefencesListingDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ActiveRuleEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(PipelineLaneDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(ProjectRecordEntryDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\LTS\PHPQA\PHPStan\RuleDocResolver::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(DefectRecordDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(DeferredDefectDto::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(NoPatternConclusionDto::class)]
#[\PHPUnit\Framework\Attributes\Small]
final class ActiveRulesListerRenderTest extends TestCase
{
    private const string QA_CI_ROOT = __DIR__ . '/../../..';

    private const string CONFIG_PATH = '/fixture/qaConfig/phpstan.neon';

    private const string DOC_PATH = '/docs/example.md';

    private const string RECORD_PATH = '/fixture/qaConfig/defect-record.neon';

    public function testRenderTextProducesTheExactExpectedString(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $this->listing();

        $expected = <<<'TEXT'
            Active defences (from /fixture/qaConfig/phpstan.neon)

            PHPStan rules:
              - My\Rule\WithEverything
                  identifier: phpqaci.example
                  summary:    An example summary.
                  doc:        /docs/example.md
              - My\Rule\Bare
                  identifier: not declared
                  summary:    no summary (no IDENTIFIER constant declared)
                  doc:        no documentation page
              - Vendor\Rule\Delivered
                  from:       vendor/rules (phpstan/extension-installer)
                  identifier: not declared
                  summary:    no summary (no IDENTIFIER constant declared)
                  doc:        documented by vendor/rules; its findings carry PHPStan identifiers, which bin/rule-doc routes

            Pipeline lanes (every lane bin/qa registers, minus phpstan — covered above):
              - laneWithPhaseAndOptIn [staticAnalysis]: Lane summary one.
                  identifier: phpqaci.laneWithPhaseAndOptIn
                  doc:        /docs/example.md
                  opt-in:     gated on useLaneOne
              - laneWithNothing [no phase recorded]: Lane summary two.

            Project record (ignoreErrors):
              - identifier: class.notFound path: some/path.php count: 3
                  justification: Justification one.
              - message: a message raw: raw text
                  justification: (none recorded)

            Defect record (qaConfig/defect-record.neon):
              Deferred defects:
                - defect:      The cache key omits the locale.
                  class:       A cache key built from a subset of its inputs.
                  found:       src/Cache/KeyBuilder.php
                  deferred by: Owner
                - defect:      A timeout is swallowed.
                  class:       none apparent yet
                  found:       src/Http/Retry.php
                  deferred by: Owner, pending the client rewrite
              No-pattern conclusions:
                - defect:     The total was rounded twice.
                  found:      src/Invoice/Total.php
                  conclusion: No pattern exists.
                  techniques: a text search for round(; reading every caller of Money::round()

            TEXT;

        self::assertSame($expected, $lister->renderText($listing));
    }

    public function testRenderTextEmptyListingsShowNoneMarkers(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = new ActiveDefencesListingDto(self::CONFIG_PATH, [], [], []);

        $expected = <<<'TEXT'
            Active defences (from /fixture/qaConfig/phpstan.neon)

            PHPStan rules:
              (none)

            Pipeline lanes (every lane bin/qa registers, minus phpstan — covered above):

            Project record (ignoreErrors):
              (none)

            Defect record (qaConfig/defect-record.neon, not written yet):
              Deferred defects: (none)
              No-pattern conclusions: (none)

            TEXT;

        self::assertSame($expected, $lister->renderText($listing));
    }

    public function testRenderJsonProducesTheExactExpectedStructureAndPreservesSlashesUnescaped(): void
    {
        $lister  = new ActiveRulesLister(self::QA_CI_ROOT);
        $listing = $this->listing();

        $json = $lister->renderJson($listing);

        // The BitwiseOr JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES flag combination:
        // if JSON_UNESCAPED_SLASHES were dropped, docPath's "/" would be escaped
        // to "\/" in the raw JSON text.
        self::assertStringNotContainsString('\/', $json);
        self::assertStringContainsString(self::DOC_PATH, $json);

        $decoded = \Safe\json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            [
                'configPath'    => self::CONFIG_PATH,
                'rules'         => [
                    [
                        'ruleClass'  => 'My\Rule\WithEverything',
                        'identifier' => 'phpqaci.example',
                        'summary'    => 'An example summary.',
                        'docPath'    => self::DOC_PATH,
                        'package'    => null,
                    ],
                    [
                        'ruleClass'  => 'My\Rule\Bare',
                        'identifier' => null,
                        'summary'    => null,
                        'docPath'    => null,
                        'package'    => null,
                    ],
                    [
                        'ruleClass'  => 'Vendor\Rule\Delivered',
                        'identifier' => null,
                        'summary'    => null,
                        'docPath'    => null,
                        'package'    => 'vendor/rules',
                    ],
                ],
                'pipelineLanes' => [
                    [
                        'name'          => 'laneWithPhaseAndOptIn',
                        'identifier'    => 'phpqaci.laneWithPhaseAndOptIn',
                        'summary'       => 'Lane summary one.',
                        'phase'         => 'staticAnalysis',
                        'optInVariable' => 'useLaneOne',
                        'docPath'       => self::DOC_PATH,
                    ],
                    [
                        'name'          => 'laneWithNothing',
                        'identifier'    => null,
                        'summary'       => 'Lane summary two.',
                        'phase'         => null,
                        'optInVariable' => null,
                        'docPath'       => null,
                    ],
                ],
                'projectRecord' => [
                    [
                        'identifier'    => 'class.notFound',
                        'message'       => null,
                        'path'          => 'some/path.php',
                        'count'         => 3,
                        'raw'           => null,
                        'justification' => 'Justification one.',
                    ],
                    [
                        'identifier'    => null,
                        'message'       => 'a message',
                        'path'          => null,
                        'count'         => null,
                        'raw'           => 'raw text',
                        'justification' => null,
                    ],
                ],
                'defectRecord' => [
                    'path'     => self::RECORD_PATH,
                    'deferred' => [
                        [
                            'defect'     => 'The cache key omits the locale.',
                            'class'      => 'A cache key built from a subset of its inputs.',
                            'found'      => 'src/Cache/KeyBuilder.php',
                            'deferredBy' => 'Owner',
                        ],
                        [
                            'defect'     => 'A timeout is swallowed.',
                            'class'      => null,
                            'found'      => 'src/Http/Retry.php',
                            'deferredBy' => 'Owner, pending the client rewrite',
                        ],
                    ],
                    'noPattern' => [
                        [
                            'defect'     => 'The total was rounded twice.',
                            'found'      => 'src/Invoice/Total.php',
                            'conclusion' => 'No pattern exists.',
                            'techniques' => ['a text search for round(', 'reading every caller of Money::round()'],
                        ],
                    ],
                ],
            ],
            $decoded,
        );
    }

    private function listing(): ActiveDefencesListingDto
    {
        return new ActiveDefencesListingDto(
            self::CONFIG_PATH,
            [
                new ActiveRuleEntryDto('My\Rule\WithEverything', 'phpqaci.example', 'An example summary.', self::DOC_PATH),
                new ActiveRuleEntryDto('My\Rule\Bare', null, null, null),
                new ActiveRuleEntryDto('Vendor\Rule\Delivered', null, null, null, 'vendor/rules'),
            ],
            [
                new PipelineLaneDto('laneWithPhaseAndOptIn', 'phpqaci.laneWithPhaseAndOptIn', 'Lane summary one.', 'staticAnalysis', 'useLaneOne', self::DOC_PATH),
                new PipelineLaneDto('laneWithNothing', null, 'Lane summary two.', null, null),
            ],
            [
                new ProjectRecordEntryDto('class.notFound', null, 'some/path.php', 3, null, 'Justification one.'),
                new ProjectRecordEntryDto(null, 'a message', null, null, 'raw text', null),
            ],
            new DefectRecordDto(
                self::RECORD_PATH,
                [
                    new DeferredDefectDto('The cache key omits the locale.', 'A cache key built from a subset of its inputs.', 'src/Cache/KeyBuilder.php', 'Owner'),
                    new DeferredDefectDto('A timeout is swallowed.', null, 'src/Http/Retry.php', 'Owner, pending the client rewrite'),
                ],
                [
                    new NoPatternConclusionDto('The total was rounded twice.', 'src/Invoice/Total.php', 'No pattern exists.', ['a text search for round(', 'reading every caller of Money::round()']),
                ],
                [],
            ),
        );
    }
}
