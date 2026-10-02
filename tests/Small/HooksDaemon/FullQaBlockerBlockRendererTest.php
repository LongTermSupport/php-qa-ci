<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Small\HooksDaemon;

use LTS\PHPQA\HooksDaemon\FullQaBlockerBlockRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FullQaBlockerBlockRenderer::class)]
#[Small]
final class FullQaBlockerBlockRendererTest extends TestCase
{
    private const string COMPOSER_DEFAULT_BIN = 'vendor/bin';

    /**
     * The whole block, pinned: it is what lands in a consuming project's daemon
     * config, and every pattern in it was proven against the daemon's matcher.
     */
    #[Test]
    public function theBlockDeclaresTheFullPipelineAndTheTargetedAlternatives(): void
    {
        $expected = <<<'YAML'
                subagent_full_qa_blocker:
                  # Managed by php-qa-ci: rewritten on composer install/update while this
                  # comment stays. Delete the comment to own the block, or set enabled: false
                  # to opt out. See php-qa-ci docs/hooks-daemon-full-qa-blocker.md.
                  enabled: true
                  priority: 32
                  options:
                    full_qa_patterns:
                      # The full pipeline: qa with neither -t nor -p, from any directory.
                      - id: php-qa-ci-full-pipeline
                        command: qa
                        read_only_flags: ["-t", "-p", "-h"]
                      # The full pipeline pointed at the whole tree: -p src, -p tests, -p .
                      - id: php-qa-ci-full-pipeline-whole-tree
                        command: qa
                        full_args: [src, tests, test]
                        read_only_flags: ["-t", "-h"]
                      # The full pipeline through the PHP binary: php vendor/bin/qa
                      - id: php-qa-ci-full-pipeline-via-php
                        command: php
                        full_words: [vendor/bin/qa, ./vendor/bin/qa]
                        read_only_flags: ["-t", "-p", "-h"]
                    targeted_qa_commands:
                      - "vendor/bin/qa -t <tool> -p <file or directory you changed>"
                      - "vendor/bin/qa --agent-mode -t phpstan -p <file you changed>"
                      - "vendor/bin/qa -t <tool>   (one lane; never the bare pipeline)"
                    unseen_sink_description: "php-qa-ci's run lock refuses a second concurrent qa run in the same project tree (exit 75); nothing else here stops a full run this handler could not read."
            YAML;

        self::assertSame($expected, implode("\n", new FullQaBlockerBlockRenderer()->render(4, 2, self::COMPOSER_DEFAULT_BIN)));
    }

    #[Test]
    public function theIndentAndUnitFollowTheHostFile(): void
    {
        $lines = new FullQaBlockerBlockRenderer()->render(8, 4, 'bin');

        self::assertSame('        subagent_full_qa_blocker:', $lines[0]);
        self::assertSame('            enabled: true', $lines[4]);
        self::assertSame('                full_qa_patterns:', $lines[7]);
        self::assertSame('                    - id: php-qa-ci-full-pipeline', $lines[9]);
        self::assertSame('                      command: qa', $lines[10]);
    }

    #[Test]
    public function theProjectsBinDirNamesTheCommands(): void
    {
        $block = implode("\n", new FullQaBlockerBlockRenderer()->render(4, 2, 'bin'));

        self::assertStringContainsString('full_words: [bin/qa, ./bin/qa]', $block);
        self::assertStringContainsString('- "bin/qa -t <tool> -p <file or directory you changed>"', $block);
        self::assertStringNotContainsString(self::COMPOSER_DEFAULT_BIN, $block);
    }

    #[Test]
    public function theMarkerIsWhatTheEditorRecognisesAsManaged(): void
    {
        $lines = new FullQaBlockerBlockRenderer()->render(0, 2, self::COMPOSER_DEFAULT_BIN);

        self::assertStringStartsWith(FullQaBlockerBlockRenderer::MARKER, trim($lines[1]));
        self::assertSame(FullQaBlockerBlockRenderer::HANDLER_KEY . ':', $lines[0]);
    }
}
