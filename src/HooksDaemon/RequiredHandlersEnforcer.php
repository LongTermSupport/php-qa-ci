<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Enforces the hooks-daemon handler settings php-qa-ci needs (`git_stash` in
 * deny mode, and the plan, instruction and markdown handlers on) in a
 * consuming project's daemon config. Runs on every composer install/update
 * (scripts/deploy-skills.bash, through bin/hooks-daemon-required-handlers).
 *
 * The file is edited as text by RequiredHandlersYamlEditor and written only
 * when a setting actually differs, so a project whose config is right sees no
 * change at all, and one whose config differs sees only the differing lines
 * move. A layout the editor cannot edit in place is refused: the file is left
 * as it was and the message names what to set by hand. So is an edit that
 * fails RequiredHandlersEditInvariant, the read-back that every required
 * setting is right and only the enforced keys' lines moved.
 *
 * @api bin/hooks-daemon-required-handlers is the caller
 */
final readonly class RequiredHandlersEnforcer
{
    /** Matches the deploy script's own output indent. */
    private const string INDENT = '  ';

    public function __construct(
        private OutputInterface $output,
        private RequiredHandlersEditorInterface $editor = new RequiredHandlersYamlEditor(),
        private RequiredHandlersEditInvariant $invariant = new RequiredHandlersEditInvariant(),
    ) {
    }

    public static function main(string $daemonConfig, OutputInterface $output): int
    {
        return new self($output)->run($daemonConfig);
    }

    /** @return int 0 when the config is right or has been made right, 1 when it was left as it was for a person to change */
    public function run(string $daemonConfig): int
    {
        if (!is_file($daemonConfig)) {
            $this->say(\sprintf('ℹ️  no hooks-daemon config at %s — required handlers not enforced', $daemonConfig));

            return 0;
        }

        try {
            $original = \Safe\file_get_contents($daemonConfig);
            $edit     = $this->editor->apply($original);
            if (null !== $edit->unsupported) {
                return $this->refuse($daemonConfig, $edit->unsupported . " cannot be edited in place, and a rewrite would drop the file's comments.");
            }

            $violation = $this->invariant->violation($original, $edit);
            if (null !== $violation) {
                return $this->refuse($daemonConfig, \sprintf('the edit failed its safety check (%s) and was not written.', $violation));
            }

            if ([] === $edit->changes) {
                $this->say('✓ All required handlers already configured correctly');

                return 0;
            }

            \Safe\file_put_contents($daemonConfig, $edit->yaml);
        } catch (Throwable $throwable) {
            $this->say('⚠️  Could not enforce the required handlers in ' . $daemonConfig . ': ' . $throwable->getMessage());

            return 1;
        }

        $this->say('Configuration updated:');
        foreach ($edit->changes as $change) {
            $this->say('✓ ' . $change);
        }

        return 0;
    }

    private function refuse(string $daemonConfig, string $reason): int
    {
        $this->say(\sprintf('⚠️  %s left untouched: %s', $daemonConfig, $reason));
        $this->say('Set these by hand, then re-run composer install:');
        foreach (RequiredHandlersYamlEditor::REQUIRED as $required) {
            $this->say(self::INDENT . \sprintf(
                '%s.%s.%s.%s: %s',
                RequiredHandlersYamlEditor::HANDLERS_KEY,
                RequiredHandlersYamlEditor::EVENT_KEY,
                $required['handler'],
                implode('.', $required['path']),
                $required['value'],
            ));
        }

        return 1;
    }

    private function say(string $message): void
    {
        $this->output->writeln(self::INDENT . $message, OutputInterface::OUTPUT_RAW);
    }
}
