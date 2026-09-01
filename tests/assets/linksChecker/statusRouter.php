<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in web server, used by LinksCheckerTest to serve
 * arbitrary HTTP status codes locally (a hermetic stand-in for httpstat.us,
 * whose outages used to fail the suite). GET /200 -> 200, GET /404 -> 404 &c.
 */
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$code = (int)trim($path, '/');
if ($code < 100 || $code > 599) {
    $code = 404;
}
http_response_code($code);
echo $code;
