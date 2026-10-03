<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Changelog\ChangelogCheck;
use LTS\PHPQA\Changelog\ChangelogGit;
use LTS\PHPQA\Changelog\WatchedPaths;
use LTS\PHPQA\Pipeline\Config\EnvironmentReader;
use LTS\PHPQA\Pipeline\Lane\BranchNamePolicy\GitBranches;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * CHANGELOG.md's `## Unreleased` section is valid and records every change
 * to the watched paths ({@see ChangelogCheck}). Opt-in: off unless a project
 * enables it with withChangelogCheck(true) and names its watched paths.
 *
 * @internal
 */
final readonly class ChangelogTool implements ToolInterface
{
    private const string RULE = '==============================================================================';

    public function __construct(
        private ?EnvironmentReader $environment = null,
        private ?ChangelogCheck $check = null,
    ) {
    }

    public function name(): string
    {
        return 'changelog';
    }

    public function identifier(): string
    {
        return ChangelogCheck::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $config = $context->config;
        if (!$config->useChangelogCheck) {
            return ToolResultDto::skipped('off; withChangelogCheck(true) in qaConfig/qa.php enables it');
        }

        $root   = $config->paths->projectRoot;
        $result = ($this->check ?? new ChangelogCheck())->check(
            $root,
            new ChangelogGit($context->processes, $root),
            new GitBranches($context->processes, $root),
            $this->environment ?? new EnvironmentReader(EnvironmentReader::fromProcess()),
            new WatchedPaths(...$config->changelogWatchedPaths),
        );

        foreach ($result->report as $line) {
            $context->writeln('[changelog] ' . $line);
        }

        if ($result->passed()) {
            return ToolResultDto::passed();
        }

        $context->writeln('');
        $context->writeln(self::RULE);
        $context->writeln('changelog: CHANGELOG.md DOES NOT RECORD THIS CHANGE CORRECTLY');
        $context->writeln(self::RULE);
        $context->writeln('');
        foreach ($result->problems as $problem) {
            $context->writeln('  - ' . str_replace("\n", "\n    ", $problem));
        }

        $context->writeln('');
        $context->writeln('Allowed headings and what each releases as: docs/tools/changelog.md');
        $context->writeIdentifier(ChangelogCheck::IDENTIFIER);

        $count = \count($result->problems);

        return ToolResultDto::failed(\sprintf('%d changelog %s', $count, 1 === $count ? 'problem' : 'problems'));
    }
}
