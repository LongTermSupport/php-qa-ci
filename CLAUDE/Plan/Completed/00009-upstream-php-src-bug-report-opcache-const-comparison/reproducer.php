<?php

// Minimal reproduction filed upstream. NOT a php-qa-ci source file: it lives in
// the plan folder because running it segfaults the interpreter by design.
//
//   php -n -d opcache.enable_cli=1 -d opcache.file_update_protection=0 reproducer.php
//
// PHP 8.5.10: Segmentation fault (core dumped)
// Expected:   int(1)
//
// -n proves no php.ini and no extension beyond the built-in OPcache is involved.
// file_update_protection=0 only avoids waiting out the default two-second window
// before OPcache will optimise a freshly written file.

function f($x) {
    if (null !== $x || [] !== $x) { return 1; }
    return 2;
}
var_dump(f(null));
