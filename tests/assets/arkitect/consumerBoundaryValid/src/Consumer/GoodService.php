<?php

declare(strict_types=1);

namespace Consumer;

use Acme\Widget\Facade\WidgetFacade;

/**
 * Reaches the library only through its public @api facade — no internal use.
 */
final class GoodService
{
    public function __construct(private readonly WidgetFacade $widget)
    {
    }

    public function run(): string
    {
        return $this->widget->describe();
    }
}
