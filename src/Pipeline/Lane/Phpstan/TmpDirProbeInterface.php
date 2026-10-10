<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\Phpstan;

use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * How TmpDirNeon learns, when its own walk of the config finds no `tmpDir`,
 * whether PHPStan nonetheless resolves one the project chose. The shipped
 * answer is DumpParametersTmpDirProbe, which asks the phar itself.
 *
 * @internal
 */
interface TmpDirProbeInterface
{
    /**
     * The `tmpDir` PHPStan resolves for `$config` when it is not PHPStan's
     * default; null when it is the default, or when PHPStan could not say.
     *
     * @param ?string $autoloadFile the `--autoload-file` the lane passes PHPStan, if any
     */
    public function projectTmpDir(ToolContext $context, string $config, ?string $autoloadFile): ?string;
}
