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

$offsetPattern            = '/b/';
$GLOBALS['offsetPattern'] = $argv[3] ?? '';
preg_match($offsetPattern, $subject);

$globalPattern = '/c/';
phpqaciFixtureOverwriteGlobalPattern($argv[4] ?? '');
preg_match($globalPattern, $subject);

$aliasedPattern = '/d/';
$globalAlias    = &$aliasedPattern;
phpqaciFixtureOverwriteGlobalAlias($argv[5] ?? '');
preg_match($aliasedPattern, $subject);

$quotedPattern = '/' . preg_quote($argv[6] ?? '', '/') . '/';
preg_match($quotedPattern, $subject);
