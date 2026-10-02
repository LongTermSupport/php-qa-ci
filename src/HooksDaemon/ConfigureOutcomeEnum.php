<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

/**
 * How one attempt to configure `subagent_full_qa_blocker` in a consuming
 * project ended. Only Written changes the project.
 *
 * @internal
 */
enum ConfigureOutcomeEnum
{
    /** There is no hooks-daemon config file to configure. */
    case NoDaemonConfig;

    /** The config exists but no daemon install with a readable version sits beside it. */
    case NoDaemonInstall;

    /** The installed daemon predates the handler. */
    case DaemonTooOld;

    /** The php-qa-ci block is already current. */
    case Unchanged;

    /** The project switched the handler off, and that stands. */
    case DisabledByProject;

    /** The project wrote the handler block itself, so it is left alone. */
    case ProjectOwned;

    /** The config's layout cannot be edited safely as text. */
    case Unsupported;

    /** The daemon CLI that would validate the change is missing, so nothing is written. */
    case CannotValidate;

    /** The daemon's config-validate refused the candidate, so nothing is written. */
    case Rejected;

    /** The validated block is in the config; the daemon loads it on its next start. */
    case Written;
}
