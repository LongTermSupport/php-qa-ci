<?php

declare(strict_types=1);

namespace Fixture\WithoutAttribute;

/**
 * Fixture: a project that NEVER uses #[\SensitiveParameter] in src/.
 *
 * The string "#[\SensitiveParameter]" appears here in a comment ONLY, to prove
 * the scanner uses the AST (it must NOT false-match this comment text).
 *
 * The usage scanner must report zero usages and signal failure (unless the
 * escape hatch is set).
 */
final class PlainService
{
    public function describe(string $label): string
    {
        return $label;
    }
}
