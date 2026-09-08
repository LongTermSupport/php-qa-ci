<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane;

use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolContext;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use LTS\PHPQA\VersionPins\VersionPinsCheck;

/**
 * phpunit.xml, composer-require-checker safe scan-files and GitHub Actions
 * PHP pins must match the toolchain in use.
 *
 * @internal
 */
final readonly class VersionPinsTool implements ToolInterface
{
    public function name(): string
    {
        return 'versionPins';
    }

    public function identifier(): string
    {
        return VersionPinsCheck::IDENTIFIER;
    }

    public function run(ToolContext $context): ToolResultDto
    {
        $root    = $context->config->paths->projectRoot;
        $phpunit = $context->configPath('phpunit.xml');
        $crc     = $context->configPath('composerRequireChecker.json');
        $exit    = OutputCapture::run(static fn (): int => VersionPinsCheck::main($root, $phpunit, $crc), $context->output);

        return 0 === $exit ? ToolResultDto::passed() : ToolResultDto::failed('a version pin does not match the toolchain in use');
    }
}
