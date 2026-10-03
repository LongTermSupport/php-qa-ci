<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Lane\PhpArkitect;

use LTS\PHPQA\Pipeline\Config\ConfigPathResolver;
use LTS\PHPQA\Pipeline\Config\IgnoredPaths;

/**
 * What a PHPArkitect entry config reads from its environment: the detected
 * source dir, the resolved rule tiers (each honouring a qaConfig/ override),
 * the consumer API-boundary factory, the class-set factory, the project's
 * withIgnoredPaths() as absolute paths and its extra exclude globs, both
 * newline-delimited.
 *
 * The arch lane and `bin/arkitect-rule` both build it here, so a rule probed
 * on a fixture is the rule the pipeline enforces.
 *
 * @internal
 */
final readonly class ArkitectEnvironment
{
    /** The variable naming the shipped class-set factory an entry config builds its class set from. */
    public const string CLASS_SET = 'PHPQACI_ARKITECT_CLASS_SET';

    /** @return array<string, string> */
    public function variables(ConfigPathResolver $configPaths, string $srcDir, IgnoredPaths $ignored, string ...$excludePaths): array
    {
        return [
            'PHPQACI_ARKITECT_SRC_DIR'                => $srcDir,
            'PHPQACI_ARKITECT_RULES_DEFAULT'          => $configPaths->resolve('phparkitect-rules-default.php'),
            'PHPQACI_ARKITECT_RULES_OPTIONAL'         => $configPaths->resolve('phparkitect-rules-optional.php'),
            'PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY' => $configPaths->resolve('phparkitect-rules-optional-symfony.php'),
            'PHPQACI_ARKITECT_CONSUMER_API_BOUNDARY'  => $configPaths->resolve('phparkitect-consumer-api-boundary.php'),
            self::CLASS_SET                           => $configPaths->resolve('phparkitect-class-set.php'),
            'PHPQACI_ARKITECT_IGNORED_PATHS'          => implode("\n", $ignored->absolute),
            'PHPQACI_ARKITECT_EXCLUDE_PATHS'          => implode("\n", $excludePaths),
        ];
    }
}
