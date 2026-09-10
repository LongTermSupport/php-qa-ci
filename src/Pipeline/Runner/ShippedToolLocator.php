<?php

declare(strict_types=1);

namespace LTS\PHPQA\Pipeline\Runner;

use LTS\PHPQA\Pipeline\Config\Exception\LegacyBashConfigException;
use LTS\PHPQA\Pipeline\Tool\Exception\UnknownToolException;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;
use RuntimeException;

/**
 * The shipped lanes, keyed by canonical name, behind the project's own
 * overrides: qaConfig/tools/<name>.php returns a ToolInterface that replaces
 * the shipped one. A Bash-era tools/<name>.inc.bash is refused with guidance.
 *
 * @internal
 */
final readonly class ShippedToolLocator implements ToolLocatorInterface
{
    /** @param array<string, ToolInterface> $shipped */
    public function __construct(
        private array $shipped,
        private string $projectConfigDir,
    ) {
    }

    public function locate(string $name): ToolInterface
    {
        $toolsDir = $this->projectConfigDir . '/tools';
        if (is_file($toolsDir . '/' . $name . '.inc.bash')) {
            throw LegacyBashConfigException::toolOverride($name, $this->projectConfigDir);
        }

        $override = $toolsDir . '/' . $name . '.php';
        if (is_file($override)) {
            $tool = require $override;
            if (!$tool instanceof ToolInterface) {
                throw new RuntimeException(\sprintf('%s must return a %s, got %s', $override, ToolInterface::class, get_debug_type($tool)));
            }

            return $tool;
        }

        return $this->shipped[$name] ?? throw UnknownToolException::forToken($name);
    }
}
