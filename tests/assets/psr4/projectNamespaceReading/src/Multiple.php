<?php

declare(strict_types=1);

namespace Named\Reading;

/** The first namespace is the file's, and it is the one the path implies. */
final class Multiple
{
}

namespace Elsewhere;

/** A class-like in a later namespace block cannot be autoloaded from this file. */
final class Stray
{
}
