<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * The workflow and composite-action files under a directory, found one way for every test that
 * reads them, so that a test reading a different set of files from the next one cannot happen.
 */
final readonly class WorkflowFiles
{
    private function __construct()
    {
    }

    /**
     * The workflow files directly under a directory.
     *
     * @return list<string> paths, sorted
     */
    public static function in(string $directory): array
    {
        return self::sorted($directory . '/*.yml');
    }

    /**
     * The `action.yml` of each composite action in a directory of actions, one level down.
     *
     * @return list<string> paths, sorted
     */
    public static function actionsIn(string $directory): array
    {
        return self::sorted($directory . '/*/action.yml');
    }

    /** @return list<string> */
    private static function sorted(string $pattern): array
    {
        $files = [];
        foreach (\Safe\glob($pattern) as $file) {
            $files[] = $file;
        }

        sort($files);

        return $files;
    }
}
