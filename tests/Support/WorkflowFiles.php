<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

/**
 * The workflow and composite-action files under a directory, found one way for every test that
 * reads them, so that a test reading a different set of files from the next one cannot happen.
 *
 * GitHub runs a workflow saved as `.yml` and one saved as `.yaml`, so both are listed.
 */
final readonly class WorkflowFiles
{
    private const array EXTENSIONS = ['yml', 'yaml'];

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
        return self::sorted($directory . '/*.');
    }

    /**
     * The `action.yml` or `action.yaml` of each composite action in a directory of actions, one
     * level down.
     *
     * @return list<string> paths, sorted
     */
    public static function actionsIn(string $directory): array
    {
        return self::sorted($directory . '/*/action.');
    }

    /** @return list<string> */
    private static function sorted(string $patternWithoutExtension): array
    {
        $files = [];
        foreach (self::EXTENSIONS as $extension) {
            foreach (\Safe\glob($patternWithoutExtension . $extension) as $file) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }
}
