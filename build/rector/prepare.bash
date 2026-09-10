# Rector-specific surgery, sourced by scripts/build-phar.bash after
# `composer install` with BUILD_DIR and BUILD_VENDOR set.
#
# `rector/rector` requires the real `phpstan/phpstan`, which ships NOT as loose
# classes but as `bootstrap.php` + a nested `phpstan.phar`. Its autoloader does
# `require 'phar://' . __DIR__ . '/phpstan.phar/src/...'`. Once bundled inside
# rector.phar, __DIR__ is already a phar:// path, so that becomes an UNOPENABLE
# nested phar:// path and every PHPStan class fails to load — rector fatals on
# boot. So before boxing we EXTRACT phpstan.phar into loose files and REPOINT
# its bootstrap at them. (Verified in CLAUDE/Plan/00002-phar-vendored-rector.)

PHPSTAN_PKG="$BUILD_VENDOR/phpstan/phpstan"
NESTED_PHAR="$PHPSTAN_PKG/phpstan.phar"
EXTRACTED="$PHPSTAN_PKG/phpstan-src"
BOOTSTRAP="$PHPSTAN_PKG/bootstrap.php"

if [[ ! -f "$NESTED_PHAR" ]]; then
    echo -e "${RED}ERROR: expected nested phpstan.phar at $NESTED_PHAR${NC}" >&2
    echo "phpstan/phpstan layout changed — review the extraction step before shipping." >&2
    return 1
fi

echo -e "${GREEN}Extracting nested phpstan.phar to loose files (avoids unopenable nested phar)...${NC}"
# shellcheck disable=SC2016 # single-quoted PHP source for `php -r`; $argv is PHP, not shell.
php -d phar.readonly=0 -r '
$nested = $argv[1]; $dest = $argv[2];
if (is_dir($dest)) { exec("rm -rf " . escapeshellarg($dest)); }
$p = new Phar($nested);
$p->extractTo($dest, null, true);
unset($p);
Phar::unlinkArchive($nested);
echo "extracted phpstan source\n";
' "$NESTED_PHAR" "$EXTRACTED"
rm -f "$PHPSTAN_PKG/phpstan.phar.asc"

echo -e "${GREEN}Repointing phpstan bootstrap autoloader at the extracted files...${NC}"
# shellcheck disable=SC2016 # single-quoted PHP source for `php -r`; $argv/$c are PHP, not shell.
php -r '
$f = $argv[1];
$c = file_get_contents($f);
$needle = "\x27phar://\x27 . __DIR__ . \x27/phpstan.phar";
$replacement = "__DIR__ . \x27/phpstan-src";
$patched = str_replace($needle, $replacement, $c);
if ($patched === $c) {
    fwrite(STDERR, "ERROR: bootstrap patch matched nothing — phpstan bootstrap.php changed shape.\n");
    exit(1);
}
file_put_contents($f, $patched);
echo "patched " . substr_count($patched, "phpstan-src") . " path(s)\n";
' "$BOOTSTRAP"
