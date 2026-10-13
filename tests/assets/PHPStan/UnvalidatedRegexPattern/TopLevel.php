<?php

declare(strict_types=1);

$pattern = $argv[1] ?? '';
$subject = $argv[2] ?? '';

try {
    preg_match($pattern, $subject);
} finally {
    restore_error_handler();
}

set_error_handler(static fn (): bool => true);
set_error_handler(static fn (): bool => true);
try {
    preg_match($pattern, $subject);
} finally {
    restore_error_handler();
    restore_error_handler();
}
