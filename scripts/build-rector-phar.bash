#!/usr/bin/env bash
set -e
set -u
set -o pipefail

###############################################################################
# build-rector-phar.bash — build the committed vendor-phar/rector.phar.
#
# Rector is delivered as a self-contained PHAR (a peer of the other tools in
# vendor-phar/), NOT as an isolated composer sub-project. This script is the
# MAINTAINER build path; the produced phar is committed to git and consumers
# simply run it — no composer/box needed on the consumer side.
#
# THE phpstan-phar CATCH (why this is not just "box compile"):
# `rector/rector` requires the real `phpstan/phpstan`, which ships NOT as loose
# classes but as `bootstrap.php` + a nested `phpstan.phar`. Its autoloader does
# `require 'phar://' . __DIR__ . '/phpstan.phar/src/...'`. Once bundled inside
# rector.phar, __DIR__ is already a phar:// path, so that becomes an UNOPENABLE
# nested phar:// path and every PHPStan class fails to load — rector fatals on
# boot. So before boxing we EXTRACT phpstan.phar into loose files and REPOINT
# its bootstrap at them. (Verified in CLAUDE/Plan/00002-phar-vendored-rector.)
#
# Usage:
#   scripts/build-rector-phar.bash            # build if missing / manifest changed
#   scripts/build-rector-phar.bash --force    # always rebuild
#
# Requirements: composer, and Box. Box is located via (in order):
#   $BOX_PHAR env var, a `box` on PATH, else a pinned box.phar downloaded to a
#   local cache. Building a PHAR needs phar.readonly=0 (applied via `php -d`).
###############################################################################

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

# The build host's umask leaks into the phar: Box stores each entry's mode, and
# a restrictive umask (e.g. 0077 in a root container) yields 0400 entries. Root
# reads those regardless, so the phar works for the maintainer and then breaks
# for every non-root consumer/CI runner — PHPStan's config loader silently skips
# a neon it cannot is_readable() (seen as "Undefined array key expandRelativePaths").
# Force a conventional umask so entries are world-readable (0444/0555).
umask 0022

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"

BUILD_DIR="$PROJECT_ROOT/build/rector-phar"
BUILD_VENDOR="$BUILD_DIR/vendor"
BOX_CONFIG="$BUILD_DIR/box.json.dist"
OUTPUT_PHAR="$PROJECT_ROOT/vendor-phar/rector.phar"

BOX_VERSION="4.7.0"
BOX_CACHE="$PROJECT_ROOT/.phive-home/box-${BOX_VERSION}.phar"

FORCE=0
for arg in "$@"; do
    case "$arg" in
        -f|--force) FORCE=1 ;;
        *) echo -e "${RED}Unknown argument: $arg${NC}" >&2; exit 1 ;;
    esac
done

if [[ ! -f "$BUILD_DIR/composer.json" ]]; then
    echo -e "${RED}ERROR: no build manifest at $BUILD_DIR/composer.json${NC}" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Locate Box (build-only maintainer dependency; never shipped to consumers).
# stdout of `command -v` is discarded (it prints the path); its exit status is
# what we branch on — stderr is left intact so real errors still surface.
# ---------------------------------------------------------------------------
locate_box() {
    if [[ -n "${BOX_PHAR:-}" && -f "${BOX_PHAR}" ]]; then
        echo "${BOX_PHAR}"
        return 0
    fi
    if command -v box >/dev/null; then
        echo "box"
        return 0
    fi
    if [[ -f "$BOX_CACHE" ]]; then
        echo "$BOX_CACHE"
        return 0
    fi
    echo -e "${YELLOW}Box not found — downloading pinned box ${BOX_VERSION}...${NC}" >&2
    mkdir -p "$( dirname "$BOX_CACHE" )"
    if ! curl -fSL -o "$BOX_CACHE" \
        "https://github.com/box-project/box/releases/download/${BOX_VERSION}/box.phar"; then
        echo -e "${RED}ERROR: failed to download Box ${BOX_VERSION}.${NC}" >&2
        echo "Install Box manually and set BOX_PHAR, or put 'box' on PATH." >&2
        rm -f "$BOX_CACHE"
        return 1
    fi
    chmod 0755 "$BOX_CACHE"
    echo "$BOX_CACHE"
}

# Invoke Box, whether it is an on-PATH binary or a .phar we run via php.
run_box() {
    local box="$1"; shift
    if [[ "$box" == "box" ]]; then
        php -d phar.readonly=0 "$( command -v box )" "$@"
    else
        php -d phar.readonly=0 "$box" "$@"
    fi
}

# ---------------------------------------------------------------------------
# Skip logic: rebuild only when forced, the phar is missing, or the manifest
# lock is newer than the phar (reproducible builds make a no-change rebuild
# pointless, and this keeps routine `composer update` runs cheap — the caller
# tolerates a skip when Box is unavailable).
# ---------------------------------------------------------------------------
if (( FORCE == 0 )) && [[ -f "$OUTPUT_PHAR" ]]; then
    if [[ ! "$BUILD_DIR/composer.lock" -nt "$OUTPUT_PHAR" ]]; then
        echo -e "${GREEN}rector.phar is up to date (manifest unchanged) — skipping build.${NC}"
        exit 0
    fi
fi

BOX_BIN="$(locate_box)" || exit 1

echo -e "${GREEN}Installing Rector build dependencies (build-only, git-ignored)...${NC}"
composer install --working-dir="$BUILD_DIR" --no-interaction --no-dev

PHPSTAN_PKG="$BUILD_VENDOR/phpstan/phpstan"
NESTED_PHAR="$PHPSTAN_PKG/phpstan.phar"
EXTRACTED="$PHPSTAN_PKG/phpstan-src"
BOOTSTRAP="$PHPSTAN_PKG/bootstrap.php"

if [[ ! -f "$NESTED_PHAR" ]]; then
    echo -e "${RED}ERROR: expected nested phpstan.phar at $NESTED_PHAR${NC}" >&2
    echo "phpstan/phpstan layout changed — review the extraction step before shipping." >&2
    exit 1
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

echo -e "${GREEN}Compiling rector.phar via Box...${NC}"
run_box "$BOX_BIN" compile --config="$BOX_CONFIG" --no-parallel
mkdir -p "$( dirname "$OUTPUT_PHAR" )"
mv "$BUILD_DIR/rector.phar" "$OUTPUT_PHAR"
chmod 0755 "$OUTPUT_PHAR"

if BUILT_VERSION="$(php "$OUTPUT_PHAR" --version)"; then
    :
else
    BUILT_VERSION="unknown (phar --version returned non-zero)"
fi
BUILT_SIZE="$(du -h "$OUTPUT_PHAR" | cut -f1)"
echo -e "${GREEN}Built $OUTPUT_PHAR ($BUILT_SIZE) — $BUILT_VERSION${NC}"
echo -e "${GREEN}Commit the updated vendor-phar/rector.phar and build/rector-phar/composer.lock.${NC}"
