<?php

declare(strict_types=1);

$stream = fopen('php://stderr', 'w');
fwrite(STDERR, 'an entry script owns its process' . PHP_EOL);
exit(main(STDOUT, STDERR));

/**
 * @param resource $stdout
 * @param resource $stderr
 */
function main($stdout, $stderr): int
{
    fwrite($stdout, 'handed in' . PHP_EOL);

    return 0;
}
