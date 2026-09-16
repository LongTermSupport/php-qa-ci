<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Agent\AgentStatusEnum;
use LTS\PHPQA\Pipeline\Agent\ArkitectJsonParser;
use LTS\PHPQA\Pipeline\Agent\ClassFileLocator;
use LTS\PHPQA\Pipeline\Agent\Dto\FileErrorDto;
use LTS\PHPQA\Pipeline\Agent\Dto\FileReportDto;
use LTS\PHPQA\Pipeline\Agent\Exception\UnreadableReportException;
use LTS\PHPQA\Pipeline\Agent\FileReportWriter;
use LTS\PHPQA\Pipeline\Agent\TerseReporter;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Architectural rules (class naming, namespace layering, dependency
 * direction) via the shipped phparkitect.phar. The paths to scan live inside
 * the entry config, so the resolved rule tiers, the detected source dir and
 * any project-declared exclude paths are handed to it through the
 * environment rather than argv. Exit 1 means rule violations; anything above
 * is a crash (bad config, unparseable file) that retrying cannot fix.
 *
 * @internal
 */
final readonly class PhpArkitectTool implements ToolInterface
{
    /** The stable identifier a failing run prints, resolved by `bin/rule-doc`. */
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpArkitect';

    /** The per-tool directory under `var/qa` holding both the log and the archive. */
    public const string LOG_DIR = 'phparkitect_logs';

    /** The human console transcript of the last run. */
    public const string LOG_FILE = 'phparkitect.log';

    /** The raw `--format=json` report of the last agent-mode run. */
    public const string JSON_FILE = 'phparkitect.json';

    /** The per-file agent-mode report tree under `var/qa`. */
    public const string REPORT_DIR = 'arch-file-reports';

    /**
     * Where a violation goes when its class cannot be traced to a file. Named
     * by rule rather than by class, so the one report gathers every class that
     * broke the same rule instead of scattering them.
     */
    public const string RULE_FALLBACK_DIR = '_rules';

    /** How much of a rule text a fallback slug keeps; the texts run to a full sentence. */
    private const int SLUG_MAX_LENGTH = 80;

    public function name(): string
    {
        return 'phpArkitect';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $config = $context->config;
        if (!$config->useArkitect) {
            $context->writeln('PHPArkitect: disabled (useArkitect=0) — skipping.');

            return ToolResultDto::skipped('useArkitect=0');
        }

        $entryConfig = $context->configPath('phparkitect.php');
        if (!is_file($entryConfig)) {
            $context->writeln(\sprintf('PHPArkitect: no entry config resolved at %s — skipping.', $entryConfig));

            return ToolResultDto::skipped('no entry config');
        }

        $context->writeln('PHPArkitect: using config ' . $entryConfig);

        $paths  = $config->paths;
        $logDir = $context->logDir(self::LOG_DIR);

        if ($config->agentMode) {
            return $this->runAgent($context, $entryConfig);
        }

        $result = $context->php->withoutXdebug(
            $paths->pharDir . '/phparkitect.phar',
            [
                'check',
                '--config=' . $entryConfig,
                '--autoload=' . $paths->projectRoot . '/vendor/autoload.php',
                '--no-interaction',
            ],
            $paths->projectRoot,
            $this->environment($context),
        );

        \Safe\file_put_contents($logDir . '/' . self::LOG_FILE, $result->output);
        $context->logs->archive('PHPArkitect', $logDir, self::LOG_FILE, null !== $config->specifiedPath, $config->pathsToCheck);

        if ($result->succeeded()) {
            return ToolResultDto::passed();
        }

        if ($result->exitCode > 1) {
            $context->writeln('');
            $context->writeln(\sprintf('PHPArkitect crashed (exit %d) — this is an ERROR, not a rule violation.', $result->exitCode));
            $context->writeln('Likely causes: an invalid phparkitect config, an unparseable source file, or a bad');
            $context->writeln('ClassSet path. Inspect the output above; retrying will not help.');
            $context->writeln("NOTE: an INCOMPLETE autoloader does NOT crash — instead the optional/symfony tiers'");
            $context->writeln('ancestry rules (IsA) silently match nothing (a false green). If those rules seem to');
            $context->writeln("find no violations, make your classes autoloadable: run 'composer dump-autoload'.");
            $context->writeln('');

            return ToolResultDto::crashed(\sprintf('PHPArkitect crashed (exit %d)', $result->exitCode));
        }

        $context->writeIdentifier(self::IDENTIFIER);

        return ToolResultDto::failed('PHPArkitect found rule violations');
    }

    /**
     * Agent mode: the same architecture check, reported for a machine.
     *
     * PHPArkitect matches rules against CLASSES and reports neither a path nor
     * a line, so each violating class is traced back to the file that declares
     * it through the class set. A class the set cannot place still has to be
     * reported, so it falls back to a report named for the rule it broke,
     * carrying the class name in the message.
     *
     * The whole tree is cleared first. `-p` does not reach this lane — the
     * paths live inside the entry config — so an agent-mode arch run is always
     * whole-project, and clearing everything is exactly the right scope.
     */
    private function runAgent(ToolContext $context, string $entryConfig): ToolResultDto
    {
        $config  = $context->config;
        $paths   = $config->paths;
        $writer  = new FileReportWriter($context->logDir(self::REPORT_DIR), $paths->projectRoot);
        $terse   = new TerseReporter($context->stdout);
        $logPath = $context->logDir(self::LOG_DIR) . '/' . self::JSON_FILE;
        $runAt   = time();

        $result = $context->php->withoutXdebug(
            $paths->pharDir . '/phparkitect.phar',
            [
                'check',
                '--config=' . $entryConfig,
                '--autoload=' . $paths->projectRoot . '/vendor/autoload.php',
                '--no-interaction',
                '--format=json',
            ],
            $paths->projectRoot,
            $this->environment($context),
            streamOutput: false,
        );
        \Safe\file_put_contents($logPath, $result->stdout);

        $writer->clearScope(null);

        if ($result->exitCode > 1) {
            return $this->agentCrash($writer, $terse, \sprintf('PHPArkitect crashed (exit %d)', $result->exitCode), $runAt, $logPath);
        }

        try {
            $parsed = new ArkitectJsonParser()->parse($result->stdout);
        } catch (UnreadableReportException $unreadableReportException) {
            return $this->agentCrash($writer, $terse, $unreadableReportException->getMessage(), $runAt, $logPath);
        }

        $locator = new ClassFileLocator($paths->srcDir);
        $byFile  = [];
        foreach ($parsed->classes as $fqcn => $violations) {
            $target = $locator->locate($fqcn) ?? $this->fallbackPath($violations[0]->message);
            foreach ($violations as $violation) {
                $byFile[$target][] = $this->attribute($violation, $fqcn, null !== $locator->locate($fqcn));
            }
        }

        foreach ($byFile as $target => $violations) {
            $writer->write(FileReportDto::forErrors($this->name(), $target, $runAt, $logPath, ...$violations));
        }

        $status = AgentStatusEnum::forErrorCount($parsed->violationCount());
        $index  = $writer->writeIndex($this->name(), $status, $runAt);
        $terse->result($this->name(), $status, $parsed->violationCount(), \count($byFile), $index, 'violation');

        return AgentStatusEnum::Clean === $status ? ToolResultDto::passed() : ToolResultDto::failed('PHPArkitect found rule violations');
    }

    /**
     * A located violation keeps its message as PHPArkitect wrote it. An
     * unlocated one gains the class name, because the report it is going into
     * is named for the rule and would otherwise not say what broke it.
     */
    private function attribute(FileErrorDto $violation, string $fqcn, bool $located): FileErrorDto
    {
        return $located
            ? $violation
            : new FileErrorDto(null, $fqcn . ' ' . $violation->message, null, 'The class could not be traced to a file in the class set; this report is grouped by rule instead.');
    }

    /** `_rules/<slug>.json`, the slug derived from the rule text so the name is stable across runs. */
    private function fallbackPath(string $message): string
    {
        $words = [];
        foreach (\Safe\preg_split('/[^A-Za-z0-9]+/', strtolower($message), -1, \PREG_SPLIT_NO_EMPTY) as $word) {
            if (\is_string($word)) {
                $words[] = $word;
            }
        }

        // Rule texts carry their whole "because ..." clause, so the slug is
        // capped: a stable, readable name matters more than a complete one.
        return self::RULE_FALLBACK_DIR . '/' . substr(implode('-', $words), 0, self::SLUG_MAX_LENGTH);
    }

    /** The check did not produce a verdict. Recorded against the log, since no class is implicated. */
    private function agentCrash(FileReportWriter $writer, TerseReporter $terse, string $reason, int $runAt, string $logPath): ToolResultDto
    {
        $report = $writer->write(FileReportDto::crashed($this->name(), self::RULE_FALLBACK_DIR . '/crash', $runAt, $reason, $logPath));
        $writer->writeIndex($this->name(), AgentStatusEnum::Crashed, $runAt, $reason);
        $terse->crashed($this->name(), $reason, $report);

        return ToolResultDto::crashed($reason);
    }

    /**
     * What the entry config reads: the detected source dir, the resolved rule
     * tiers (each honouring a qaConfig/ override), the consumer API-boundary
     * factory and the project's extra exclude paths, newline-delimited.
     *
     * @return array<string, string>
     */
    private function environment(ToolContext $context): array
    {
        return [
            'PHPQACI_ARKITECT_SRC_DIR'                => $context->config->paths->srcDir,
            'PHPQACI_ARKITECT_RULES_DEFAULT'          => $context->configPath('phparkitect-rules-default.php'),
            'PHPQACI_ARKITECT_RULES_OPTIONAL'         => $context->configPath('phparkitect-rules-optional.php'),
            'PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY' => $context->configPath('phparkitect-rules-optional-symfony.php'),
            'PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY'  => $context->configPath('phparkitect-consumer-api-boundary.php'),
            'PHPQACI_ARKITECT_EXCLUDE_PATHS'          => implode("\n", $context->config->arkitectExcludePaths),
        ];
    }
}
