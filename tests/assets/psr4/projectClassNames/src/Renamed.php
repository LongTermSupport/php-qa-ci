<?php

declare(strict_types=1);

namespace Named\Classes;

/**
 * The file's one class-like is not the one its path names: Named\Classes\Renamed would be looked
 * for here, and Named\Classes\NotRenamed is looked for in a file that does not exist.
 */
final class NotRenamed
{
}
