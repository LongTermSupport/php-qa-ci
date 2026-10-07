<?php

declare(strict_types=1);

/*
 * Runs the bundled Infection's own source collection on a config file and
 * prints each collected file relative to the given root, one per line, sorted.
 * Run in a separate process so the PHAR's scoped autoloader never meets the
 * test suite's.
 *
 * Usage: php collect-sources.php <infection.phar> <infection.json> <root>
 */

[, $phar, $configPath, $root] = $argv;

require 'phar://' . $phar . '/vendor/autoload.php';

$config      = json_decode((string)file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
$collector   = 'Infected\Infection\Source\Collector\BasicSourceCollector';
$collected   = $collector::create($configPath, $config['source']['directories'], $config['source']['excludes'] ?? [], null)->collect();
$relative    = array_map(static fn (SplFileInfo $file): string => substr((string)$file->getRealPath(), strlen(rtrim($root, '/')) + 1), $collected);
sort($relative);

echo implode("\n", $relative), "\n";
