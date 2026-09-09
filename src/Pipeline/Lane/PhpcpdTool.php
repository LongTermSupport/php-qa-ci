<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\PHPStan\Rules\RuleIdentifierInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Copy/paste detection over the checked paths via the shipped
 * vendor-phar/phpcpd.phar, after the gate has passed.
 * Informational by design: duplication is a judgement call, not a defect, and
 * a lane that failed on it would be turned off within a week.
 *
 * phpcpd returns 1 both for "found clones" and for its own errors, so neither
 * can fail the run; anything outside 0 and 1 is a genuine crash and is
 * reported as one, which still does not fail the pipeline here.
 *
 * @internal
 */
final readonly class PhpcpdTool implements ToolInterface
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.phpcpd';

    public const string LOG_FILE = 'phpcpd.json';

    private const string PHAR = 'phpcpd.phar';

    private const int EXIT_CLONES_OR_ERROR = 1;

    public function name(): string
    {
        return 'phpcpd';
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $paths  = $context->config->paths;
        $logDir = $context->logDir($this->name());
        $result = $context->php->withoutXdebug(
            $paths->pharDir . '/' . self::PHAR,
            ['--log-json=' . $logDir . '/' . self::LOG_FILE, ...$context->config->pathsToCheck],
            $paths->projectRoot,
        );

        if ($result->exitCode > self::EXIT_CLONES_OR_ERROR) {
            $context->writeln(\sprintf('phpcpd could not run (exit %d) — informational lane, not failing the run.', $result->exitCode));
        }

        return ToolResultDto::passed();
    }
}
