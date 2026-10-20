<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\Markdown;

use Iterator;
use LTS\PHPQA\Markdown\QaCommandTokenFinding;
use LTS\PHPQA\Markdown\QaCommandTokenScanner;
use LTS\PHPQA\Pipeline\Tool\ToolRegistry;
use LTS\PHPQA\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(QaCommandTokenScanner::class)]
#[CoversClass(QaCommandTokenFinding::class)]
#[UsesClass(ToolRegistry::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\PhaseDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Dto\ToolDefinitionDto::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\Exception\UnknownToolException::class)]
#[UsesClass(\LTS\PHPQA\Pipeline\Tool\PhaseEnum::class)]
#[Small]
final class QaCommandTokenScannerTest extends TestCase
{
    private const string FILE = 'doc.md';

    private const string REJECTED = 'allCs';

    private const string TEMP_PREFIX = 'qa-tokens';

    private const string README = 'README.md';

    private QaCommandTokenScanner $scanner;

    private ?TempDir $project = null;

    protected function setUp(): void
    {
        $this->scanner = new QaCommandTokenScanner(ToolRegistry::shipped());
    }

    protected function tearDown(): void
    {
        $this->project?->remove();
    }

    #[Test]
    public function aTokenTheRegistryRejectsIsReportedWithItsPlace(): void
    {
        $findings = $this->scanner->scanText("# Fixer\n\n5. Run `{bin}/qa -t allCs -p [changed-files]`\n", self::FILE);

        self::assertCount(1, $findings);
        self::assertSame(self::FILE, $findings[0]->file);
        self::assertSame(3, $findings[0]->line);
        self::assertSame(self::REJECTED, $findings[0]->token);
        self::assertSame('{bin}/qa -t allCs -p [changed-files]', $findings[0]->command);
        self::assertSame("'allCs' is not a tool bin/qa accepts", $findings[0]->problem);
    }

    #[Test]
    public function aTokenDifferingOnlyInCaseSuggestsTheTokenTheRegistryAccepts(): void
    {
        $findings = $this->scanner->scanText("vendor/bin/qa -t allCs\n");

        self::assertCount(1, $findings);
        self::assertSame('did you mean -t allCS?', $findings[0]->hint);
    }

    #[Test]
    public function theSuggestedTokenWarnsWhenItTooWouldRefuseThePath(): void
    {
        $findings = $this->scanner->scanText("`{bin}/qa -t allCs -p [changed-files]`\n");

        self::assertCount(1, $findings);
        self::assertSame('did you mean -t allCS? It takes no -p; its path-taking tools take -p: -t r, -t f', $findings[0]->hint);
    }

    #[Test]
    public function aLaneNameThatIsNotATokenSuggestsTheLanesTokens(): void
    {
        $findings = $this->scanner->scanText("vendor/bin/qa -t markdownLinks\n");

        self::assertCount(1, $findings, 'a canonical name is not a -t token unless it is also an alias');
        self::assertSame('did you mean -t ml (or -t markdown)?', $findings[0]->hint);
    }

    #[Test]
    public function aTokenResemblingNothingCarriesNoHint(): void
    {
        $findings = $this->scanner->scanText("vendor/bin/qa -t frobnicate\n");

        self::assertCount(1, $findings);
        self::assertNull($findings[0]->hint);
    }

    #[Test]
    #[DataProvider('pathOnAToolThatTakesNone')]
    public function aPathOnAToolThatTakesNoneIsReportedBecauseBinQaRefusesIt(string $line): void
    {
        $findings = $this->scanner->scanText($line . "\n");

        self::assertCount(1, $findings);
        self::assertSame('allCS', $findings[0]->token);
        self::assertSame("'allCS' takes no -p, so bin/qa refuses the command", $findings[0]->problem);
        self::assertSame('its path-taking tools take -p: -t r, -t f', $findings[0]->hint);
    }

    /** @return Iterator<string, array{string}> */
    public static function pathOnAToolThatTakesNone(): Iterator
    {
        yield '-p after the token' => ['`{bin}/qa -t allCS -p [changed-files]`'];
        yield '-p before -t' => ['vendor/bin/qa -p src -t allCS'];
    }

    #[Test]
    public function aPathOnALeafToolThatTakesNoneCarriesNoHint(): void
    {
        $findings = $this->scanner->scanText("vendor/bin/qa -t ml -p docs\n");

        self::assertCount(1, $findings);
        self::assertNull($findings[0]->hint);
    }

    #[Test]
    #[DataProvider('acceptedCommands')]
    public function aTokenTheRegistryAcceptsIsNotReported(string $line): void
    {
        self::assertSame([], $this->scanner->scanText($line . "\n"));
    }

    /** @return Iterator<string, array{string}> */
    public static function acceptedCommands(): Iterator
    {
        yield 'the composer bin dir' => ['vendor/bin/qa -t stan'];
        yield 'the bin placeholder' => ['`{bin}/qa -t allCS`'];
        yield 'a path on a tool that takes one' => ['`{bin}/qa -t fixer -p [changed-files]`'];
        yield 'a checkout of php-qa-ci' => ['bin/qa -t ml'];
        yield 'behind an environment assignment' => ['CI=true vendor/bin/qa -t unit -p tests/'];
        yield 'after a chained export' => ['export CI=true && {bin}/qa -t yaml'];
        yield 'an option before -t' => ['vendor/bin/qa --agent-mode -t phpstan -p src'];
        yield 'a canonical name that is also an alias' => ['vendor/bin/qa -t docsProse'];
    }

    #[Test]
    #[DataProvider('notATokenOrNotACommand')]
    public function onlyAQaInvocationWithALiteralTokenIsRead(string $line): void
    {
        self::assertSame([], $this->scanner->scanText($line . "\n"));
    }

    /** @return Iterator<string, array{string}> */
    public static function notATokenOrNotACommand(): Iterator
    {
        yield 'an angle-bracket placeholder' => ['vendor/bin/qa -t <tool> -p <path>'];
        yield 'a brace placeholder' => ['export CI=true && {bin}/qa -t {tool}'];
        yield 'prose naming the flag' => ['qa with neither -t nor -p, from any directory.'];
        yield 'a comment after another flag' => ['vendor/bin/qa -h        # every tool and its -t alias'];
        yield 'a word ending in qa' => ['php-qa -t allCs'];
        yield 'a hyphenated name' => ['qa-ci -t allCs'];
        yield 'an attached flag value' => ['vendor/bin/qa -tallCs'];
    }

    #[Test]
    public function everyInvocationOnALineIsRead(): void
    {
        $findings = $this->scanner->scanText("`vendor/bin/qa -t stan` then `vendor/bin/qa -t allCs` then `bin/qa -t yamlLint`\n");

        self::assertSame([self::REJECTED, 'yamlLint'], array_map(static fn (QaCommandTokenFinding $finding): string => $finding->token, $findings));
    }

    #[Test]
    public function aFencedBlockIsReadBecauseThatIsWhereCommandsAreWritten(): void
    {
        $findings = $this->scanner->scanText("Run:\n\n```bash\nvendor/bin/qa -t allCs\n```\n");

        self::assertCount(1, $findings);
        self::assertSame(4, $findings[0]->line);
    }

    #[Test]
    public function aTokenTheSameDocumentRegistersIsAccepted(): void
    {
        $markdown = <<<'MD'
            ```php
            return static fn (PipelineBuilder $pipeline): PipelineBuilder => $pipeline
                ->withPhase(
                    new PhaseDto('security', 'Running All Security Tools', 'allSecurityTools', ['allSec'], 'all security tools'),
                )
                ->withTool(
                    new ToolDefinitionDto('securityAudit', ['sa', 'securityAudit'], 'audit', 'security', false),
                    new SecurityAuditTool(),
                );
            ```

            ```bash
            vendor/bin/qa -t sa
            vendor/bin/qa -t allSec
            vendor/bin/qa -t securityAudit
            vendor/bin/qa -t allSecurity
            ```
            MD;

        $findings = $this->scanner->scanText($markdown);

        self::assertSame(['allSecurity'], array_map(static fn (QaCommandTokenFinding $finding): string => $finding->token, $findings));
    }

    #[Test]
    public function aTokenRegisteredInAnotherDocumentIsNotAcceptedHere(): void
    {
        $this->project = TempDir::create(self::TEMP_PREFIX);
        $this->project->write('docs/extending.md', "new ToolDefinitionDto('securityAudit', ['sa'], 'audit', 'security', false)\n");
        $this->project->write(self::README, "vendor/bin/qa -t sa\n");

        $findings = $this->scanner->scan($this->project->path);

        self::assertCount(1, $findings);
        self::assertSame(self::README, $findings[0]->file);
    }

    #[Test]
    public function theAgentFacingTreesAreScannedAndPlanFoldersAreNot(): void
    {
        $this->project = TempDir::create(self::TEMP_PREFIX);
        $bad           = "vendor/bin/qa -t allCs\n";
        $this->project->write(self::README, $bad);
        $this->project->write('CLAUDE.md', $bad);
        $this->project->write('docs/tools/a.md', $bad);
        $this->project->write('CLAUDE/howto.md', $bad);
        $this->project->write('.claude/agents/fixer.md', $bad);
        $this->project->write('.claude/skills/qa/SKILL.md', $bad);
        $this->project->write('qaConfig/PHPStan/CLAUDE.md', $bad);
        $this->project->write('templates/agent.md', $bad);
        $this->project->write('CLAUDE/Plan/00001-old/PLAN.md', $bad);
        $this->project->write('CHANGELOG.md', $bad);
        $this->project->write('src/notes.md', $bad);
        $this->project->write('docs/tools/a.txt', $bad);

        $files = array_map(static fn (QaCommandTokenFinding $finding): string => $finding->file, $this->scanner->scan($this->project->path));

        self::assertSame(
            [
                '.claude/agents/fixer.md',
                '.claude/skills/qa/SKILL.md',
                'CLAUDE.md',
                'CLAUDE/howto.md',
                self::README,
                'docs/tools/a.md',
                'qaConfig/PHPStan/CLAUDE.md',
                'templates/agent.md',
            ],
            $files,
        );
    }

    #[Test]
    public function aProjectWithNoneOfTheTreesHasNothingToReport(): void
    {
        $this->project = TempDir::create(self::TEMP_PREFIX);

        self::assertSame([], $this->scanner->scan($this->project->path));
    }
}
