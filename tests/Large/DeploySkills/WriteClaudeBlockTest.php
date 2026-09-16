<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Large\DeploySkills;

use LTS\PHPQA\Tests\Assets\DeploySkills\ShellRunner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * scripts/write-claude-block.bash injects the managed block into a consumer's
 * root CLAUDE.md on every composer install/update, so anything about its output
 * that a markdown formatter would rewrite becomes a diff the consumer's tooling
 * commits on every deploy, to whatever branch happens to be checked out.
 *
 * The closing tag is where that bites: directly beneath a bullet it reads as
 * list continuation and gets indented, after which the block no longer matches
 * the template and is rewritten for ever. The shipped template happens to carry
 * a blank line there; these tests make it a guarantee of the writer instead of
 * a property of one template that a future edit could quietly remove.
 *
 * @internal
 */
#[CoversNothing]
#[Large]
final class WriteClaudeBlockTest extends TestCase
{
    private const string WRITER = __DIR__ . '/../../../scripts/write-claude-block.bash';

    private const string CLOSE_TAG = '</phpqaci>';

    /** A template whose closing tag sits directly beneath a bullet -- the hazardous shape. */
    private const string TIGHT_TEMPLATE = "<phpqaci>\n\n- a bullet\n- another bullet\n</phpqaci>\n";

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/phpqaci-block-' . \bin2hex(\random_bytes(8));
        \Safe\mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        ShellRunner::run(\sprintf('rm -rf %s', \escapeshellarg($this->workDir)));
    }

    #[Test]
    public function theClosingTagIsAlwaysPrecededByABlankLine(): void
    {
        $target = $this->write('CLAUDE.md', "# Project\n\nSome prose.\n");

        $this->runWriter($this->write('template.md', self::TIGHT_TEMPLATE), $target);

        self::assertSame(
            ['- another bullet', '', self::CLOSE_TAG],
            $this->tailLines($target, 3),
            'a bullet immediately above the closing tag is what lets a formatter indent it',
        );
    }

    #[Test]
    public function theClosingTagStartsAtColumnZero(): void
    {
        $target = $this->write('CLAUDE.md', "# Project\n");

        $this->runWriter($this->write('template.md', self::TIGHT_TEMPLATE), $target);

        self::assertStringContainsString("\n" . self::CLOSE_TAG . "\n", $this->read($target));
    }

    #[Test]
    public function rewritingAnAlreadyNormalisedBlockChangesNothing(): void
    {
        $template = $this->write('template.md', self::TIGHT_TEMPLATE);
        $target   = $this->write('CLAUDE.md', "# Project\n\nSome prose.\n");

        $this->runWriter($template, $target);
        $first = $this->read($target);
        $this->runWriter($template, $target);

        self::assertSame($first, $this->read($target), 'a second deploy with no template change must produce no diff');
    }

    #[Test]
    public function theShippedTemplateSurvivesTheWriterUnchangedBelowItsLastBullet(): void
    {
        $shipped = __DIR__ . '/../../../templates/root-CLAUDE-phpqaci-block.md.template';
        $target  = $this->write('CLAUDE.md', "# Project\n");

        $this->runWriter($shipped, $target);

        $tail = $this->tailLines($target, 2);
        self::assertSame(['', self::CLOSE_TAG], $tail);
    }

    private function runWriter(string $template, string $target): void
    {
        $result = ShellRunner::run(\sprintf(
            'bash %s %s %s',
            \escapeshellarg(self::WRITER),
            \escapeshellarg($template),
            \escapeshellarg($target),
        ));

        self::assertSame(0, $result['exit'], 'writer failed: ' . $result['output']);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->workDir . '/' . $name;
        \Safe\file_put_contents($path, $content);

        return $path;
    }

    private function read(string $path): string
    {
        return \Safe\file_get_contents($path);
    }

    /** @return list<string> */
    private function tailLines(string $path, int $count): array
    {
        $lines = \explode("\n", \rtrim($this->read($path), "\n"));

        return \array_values(\array_slice($lines, -$count));
    }
}
