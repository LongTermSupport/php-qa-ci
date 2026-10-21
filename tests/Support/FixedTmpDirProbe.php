<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LTS\PHPQA\Pipeline\Lane\Phpstan\Dto\PhpstanParametersDto;
use LTS\PHPQA\Pipeline\Lane\Phpstan\TmpDirProbeInterface;
use LTS\PHPQA\Pipeline\Tool\ToolContext;

/**
 * Answers the tmpDir question with a fixed value, and the parameters question with that tmpDir and
 * PHPStan's defaults, and records what it was asked, so a lane's tests keep their process queue to
 * the analysis itself.
 */
final class FixedTmpDirProbe implements TmpDirProbeInterface
{
    /** @var list<array{string, ?string}> the config and autoload file of each question */
    public array $askedAbout = [];

    public function __construct(private readonly ?string $tmpDir = null)
    {
    }

    public function projectTmpDir(ToolContext $context, string $config, ?string $autoloadFile): ?string
    {
        $this->askedAbout[] = [$config, $autoloadFile];

        return $this->tmpDir;
    }

    public function parameters(ToolContext $context, string $config, ?string $autoloadFile): PhpstanParametersDto
    {
        $this->askedAbout[] = [$config, $autoloadFile];
        $defaults           = PhpstanParametersDto::defaults();

        return new PhpstanParametersDto($this->tmpDir, $defaults->paths, $defaults->excludePaths, $defaults->fileExtensions);
    }
}
