<?php

declare(strict_types=1);

namespace LTS\PHPQA;

use Composer\Autoload\ClassLoader;
use Exception;
use ReflectionClass;
use RuntimeException;

final class Helper
{
    /**
     * @var string
     */
    private static $projectRootDirectory;

    /**
     * @return array<int|string,mixed>
     *
     * @throws Exception
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public static function getComposerJsonDecoded(?string $path = null,): array
    {
        $path     ??= self::getProjectRootDirectory().'/composer.json';
        $contents = \Safe\file_get_contents($path,);
        if ('' === $contents) {
            throw new RuntimeException('composer.json is empty',);
        }

        // @phpstan-ignore-next-line
        return \Safe\json_decode($contents, true, 512, JSON_THROW_ON_ERROR,);
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
            // rector is including a vendor directory in itself which is messing with composer
//            $reflection                 = new ReflectionClass(ClassLoader::class);
//            self::$projectRootDirectory = \dirname((string)$reflection->getFileName(), 3);
            if (!isset($_SERVER['PWD'],)) {
                die('no PWD in _SERVER');
            }
            $pwd = $_SERVER['PWD'];
            if (!file_exists("$pwd/composer.json",)) {
                die('PWD is '.$pwd.' but does not contain composer.json');
            }

            return self::$projectRootDirectory = $pwd;
        }

        return self::$projectRootDirectory;
    }
}
