<?php

declare(strict_types=1);

// A COMPLETE autoloader is mandatory for the optional tier: arkitect's
// IsA(\Throwable) rule reflects each analysed class to resolve its ancestry, so
// the classes must be loadable. With an empty autoloader the rule silently
// matches nothing (a false green) — this fixture proves the rule fires, so it
// registers a real PSR-4-style loader for the ArkitectFixture\ namespace.
spl_autoload_register(static function (string $class): void {
    $prefix = 'ArkitectFixture\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, \strlen($prefix));
    $file     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
