<?php

declare(strict_types=1);

namespace LTS\PHPQA;

use Exception;
use RuntimeException;

final class Helper
{
    private static ?string $projectRootDirectory = null;

    /**
     * @return array<int|string,mixed>
     *
     * @throws Exception
     */
    public static function getComposerJsonDecoded(?string $path = null): array
    {
        $path     ??= self::getProjectRootDirectory() . '/composer.json';
        $contents = \Safe\file_get_contents($path);
        if ('' === $contents) {
            throw new RuntimeException('composer.json is empty');
        }

        $decoded = \Safe\json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new RuntimeException('composer.json did not decode to an array: ' . $path);
        }

        return $decoded;
    }

    /**
     * Get the absolute path to the root of the current project.
     *
     * It does this by working from the Composer autoloader which we know will be in a certain place in `vendor`
     *
     * @throws Exception
     */
    public static function getProjectRootDirectory(): string
    {
        if (null === self::$projectRootDirectory) {
            if (!isset($_SERVER['PWD']) || !\is_string($_SERVER['PWD'])) {
                exit('no PWD in _SERVER');
            }

            $pwd = $_SERVER['PWD'];
            if (!file_exists($pwd . '/composer.json')) {
                exit('PWD is ' . $pwd . ' but does not contain composer.json');
            }

            return self::$projectRootDirectory = $pwd;
        }

        return self::$projectRootDirectory;
    }
}
