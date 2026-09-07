<?php

declare(strict_types=1);

namespace Fixture\ConfigTemplateIgnoreList;

/**
 * A real class-shaped config file. It HAS a namespace, so it is not the kind
 * of file this auditor is concerned with — copying it into qaConfig/ is a
 * normal PSR-4 file and psr4Validate already handles it correctly.
 */
final class GoodWithNamespace
{
}
