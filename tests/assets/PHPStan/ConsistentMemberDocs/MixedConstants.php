<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Assets\PHPStan\ConsistentMemberDocs;

enum MixedConstants: string
{
    /** Retry interactively. */
    case Failed = 'failed';
    case Passed = 'passed';
    case Skipped = 'skipped';
}
