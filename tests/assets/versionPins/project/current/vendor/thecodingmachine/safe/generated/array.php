<?php

declare(strict_types=1);

if (str_starts_with(PHP_VERSION, "8.2.")) {
    require_once __DIR__ . '/8.2/array.php';
}
if (str_starts_with(PHP_VERSION, "8.4.")) {
    require_once __DIR__ . '/8.4/array.php';
}
if (str_starts_with(PHP_VERSION, "8.5.")) {
    require_once __DIR__ . '/8.4/array.php';
}
