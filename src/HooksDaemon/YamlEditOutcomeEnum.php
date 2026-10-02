<?php

declare(strict_types=1);

namespace LTS\PHPQA\HooksDaemon;

/**
 * What the YAML edit found and did to the `subagent_full_qa_blocker` handler.
 *
 * @internal
 */
enum YamlEditOutcomeEnum
{
    /** The handler was absent and has been added. */
    case Inserted;

    /** A php-qa-ci-managed block was out of date and has been rewritten. */
    case Updated;

    /** The managed block is already current. */
    case Unchanged;

    /** The project switched the handler off; that choice stands. */
    case DisabledByProject;

    /** The project configured the handler itself (no php-qa-ci marker); it is left alone. */
    case ProjectOwned;

    /** The file's layout cannot be edited as text safely (flow mappings, CRLF line endings). */
    case Unsupported;
}
