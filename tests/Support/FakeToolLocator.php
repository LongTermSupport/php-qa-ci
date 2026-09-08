<?php

declare(strict_types=1);

namespace LTS\PHPQA\Tests\Support;

use LogicException;
use LTS\PHPQA\Pipeline\Runner\ToolLocatorInterface;
use LTS\PHPQA\Pipeline\Tool\Dto\ToolResultDto;
use LTS\PHPQA\Pipeline\Tool\ToolInterface;

/**
 * Serves registered stub tools by name; any name not registered gets a
 * passing stub, so a pipeline test only has to describe the tools it cares
 * about.
 */
final class FakeToolLocator implements ToolLocatorInterface
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): self
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    public function locate(string $name): ToolInterface
    {
        return $this->tools[$name] ??= new StubTool($name, ToolResultDto::passed());
    }

    public function stub(string $name): StubTool
    {
        $tool = $this->locate($name);
        if (!$tool instanceof StubTool) {
            throw new LogicException($name . ' is not a StubTool');
        }

        return $tool;
    }
}
