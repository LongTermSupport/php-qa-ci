<?php

declare(strict_types=1);

namespace LTS\PHPQA\ShellCheck;

/**
 * What an install or update run should do about the vendored ShellCheck.
 *
 * @api
 */
enum InstallActionEnum
{
    /** Nothing to do: the committed binary is the one this checkout wants. */
    case Ready;

    /** Download and commit a release; the decision names which. */
    case Fetch;

    /** The checkout is not usable and nothing here can fix it silently. */
    case Broken;
}
