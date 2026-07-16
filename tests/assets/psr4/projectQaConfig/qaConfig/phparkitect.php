<?php

declare(strict_types=1);

/**
 * Fixture mirroring a real project's qaConfig/phparkitect.php: a non-class
 * config file that legitimately has NO namespace. It lives under the
 * `QaConfig\` PSR-4 root, so the shipped default psr4-validate ignore list must
 * exclude it — otherwise the gate falsely reports it as a Parse Error.
 */

return static function (): void {
};
