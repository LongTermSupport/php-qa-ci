<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * The remediation block a mutating tool prints when a read-only run finds
 * changes it would have applied. The tool itself decides the outcome; this
 * only prints the guidance.
 *
 * @internal
 */
final readonly class ReadOnlyGuidance
{
    public static function wouldModify(ToolContext $context, string $toolName, string $qaTarget): void
    {
        $context->writeln('');
        $context->writeln('');
        $context->writeln('    ==================================================');
        $context->writeln('');
        $context->writeln(\sprintf('        %s: pending changes in a READ-ONLY run', $toolName));
        $context->writeln('');
        $context->writeln('    --------------------------------------------------');
        $context->writeln('');
        $context->writeln(\sprintf('    This run is read-only (qaReadOnly=true), so %s did NOT modify any', $toolName));
        $context->writeln('    files. It found changes it WOULD make, which fails the gate. The diff is');
        $context->writeln('    shown above.');
        $context->writeln('');
        $context->writeln('    Read-only mode is auto-enabled on GitHub Actions. It is INDEPENDENT of CI /');
        $context->writeln('    interactivity: a Claude Code or local run is non-interactive (so it never');
        $context->writeln('    hangs) but still WRITES, so you can apply fixes there.');
        $context->writeln('');
        $context->writeln('    TO FIX -- apply the changes where writes are allowed, then commit them:');
        $context->writeln('');
        $context->writeln(\sprintf('        QA_READONLY=0 vendor/bin/qa -t %s', $qaTarget));
        $context->writeln('        git add -A && git commit');
        $context->writeln('');
        $context->writeln('    Then push. CI passes because no pending changes remain.');
        $context->writeln('');
        $context->writeln('    ==================================================');
        $context->writeln('');
    }
}
