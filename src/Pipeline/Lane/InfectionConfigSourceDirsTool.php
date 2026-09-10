<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\InfectionConfig\InfectionConfigSourceDirectoriesCheck;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * infection.json's source.directories must resolve, relative to the config
 * file's own directory, to real directories: the resolved infection.json the
 * Infection lane will read.
 *
 * @internal
 */
final readonly class InfectionConfigSourceDirsTool implements ToolInterface
{
    public function __construct(private InfectionConfigSourceDirectoriesCheck $check = new InfectionConfigSourceDirectoriesCheck())
    {
    }

    public function name(): string
    {
        return 'infectionConfigSourceDirs';
    }

    public function identifier(): string
    {
        return InfectionConfigSourceDirectoriesCheck::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $configPath = $context->configPath('infection.json');
        $exit       = OutputCapture::run(fn (): int => $this->check->run($configPath), $context->output);

        return 0 === $exit ? ToolResultDto::passed() : ToolResultDto::failed('infection.json declares a source directory that does not exist');
    }
}
