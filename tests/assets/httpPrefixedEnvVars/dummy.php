<?php

declare(strict_types=1);

/**
 * Not itself under test — ForbidHttpPrefixedEnvVarsRule triggers on FileNode
 * (once per analysed PHP file), so PHPStan needs at least one real file to
 * analyse in order to fire the rule at all. The rule's own scan target is the
 * projectRoot passed to its constructor, not this file's contents.
 */
class Dummy
{
}
