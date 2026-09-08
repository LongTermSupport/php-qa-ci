<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Config\Exception;

use RuntimeException;

/**
 * A Bash-era file was found under qaConfig/. The PHP pipeline does not read
 * it, so it is refused with the migration guidance rather than ignored.
 *
 * @internal
 */
final class LegacyBashConfigException extends RuntimeException
{
    private const string GUIDE = 'vendor/lts/php-qa-ci/docs/upgrading-to-8.5.md';

    public static function qaConfig(string $projectConfigDir): self
    {
        return new self(\sprintf(
            '%s/qaConfig.inc.bash is no longer read: configuration is PHP now. Move its settings to %s/qa.php (return a closure adjusting the QaConfigBuilder), then delete the .bash file. Guide: %s',
            $projectConfigDir,
            $projectConfigDir,
            self::GUIDE,
        ));
    }

    public static function hook(string $hook, string $projectConfigDir): self
    {
        return new self(\sprintf(
            '%s/%s.bash is no longer run: hooks are PHP now. Move it to %s/%s.php (return a callable that receives the ToolContext), then delete the .bash file. Guide: %s',
            $projectConfigDir,
            $hook,
            $projectConfigDir,
            $hook,
            self::GUIDE,
        ));
    }

    public static function toolOverride(string $tool, string $projectConfigDir): self
    {
        return new self(\sprintf(
            '%s/tools/%s.inc.bash is no longer sourced: tool overrides are PHP now. Move it to %s/tools/%s.php (return a ToolInterface), then delete the .inc.bash file. Guide: %s',
            $projectConfigDir,
            $tool,
            $projectConfigDir,
            $tool,
            self::GUIDE,
        ));
    }
}
