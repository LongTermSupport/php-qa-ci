#!/usr/bin/env bash
set -e
set -u
set -o pipefail

###############################################################################
# build-phar.bash — build a committed vendor-phar/<tool>.phar for a tool whose
# upstream publishes no PHAR.
#
# Each such tool has a build manifest under build/<tool>/:
#   composer.json    the isolated dependency graph (locked by composer.lock)
#   box.json.dist    the Box configuration; "output" must be "<tool>.phar"
#   prepare.bash     optional; sourced after `composer install`, with
#                    BUILD_DIR and BUILD_VENDOR set, for any tool-specific
#                    surgery on the installed tree before boxing
#
# This is the MAINTAINER build path; the produced phar is committed to git and
# consumers simply run it — no composer/box needed on the consumer side.
#
# Usage:
#   scripts/build-phar.bash <tool>            # build if missing / manifest changed
#   scripts/build-phar.bash <tool> --force    # always rebuild
#   scripts/build-phar.bash --all [--force]   # every build/<tool>/ manifest
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
# for every non-root consumer/CI runner. Force a conventional umask so entries
# are world-readable (0444/0555).
umask 0022

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"
PHAR_DIR="$PROJECT_ROOT/vendor-phar"

BOX_VERSION="4.7.0"
BOX_CACHE="$PROJECT_ROOT/.phive-home/box-${BOX_VERSION}.phar"

FORCE=0
ALL=0
TOOLS=()
for arg in "$@"; do
    case "$arg" in
        -f|--force) FORCE=1 ;;
        --all) ALL=1 ;;
        -*) echo -e "${RED}Unknown argument: $arg${NC}" >&2; exit 1 ;;
        *) TOOLS+=("$arg") ;;
    esac
done

if (( ALL == 1 )); then
    for manifest in "$PROJECT_ROOT"/build/*/composer.json; do
        TOOLS+=("$( basename "$( dirname "$manifest" )" )")
    done
fi

if (( ${#TOOLS[@]} == 0 )); then
    echo -e "${RED}Usage: $0 <tool>|--all [--force]${NC}" >&2
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

build_tool() {
    local tool="$1"
    # BUILD_DIR and BUILD_VENDOR are the documented contract for a tool's
    # prepare.bash, which is sourced below. Exported because that is where they
    # are read, not here.
    local -x BUILD_DIR="$PROJECT_ROOT/build/$tool"
    local -x BUILD_VENDOR="$BUILD_DIR/vendor"
    local BOX_CONFIG="$BUILD_DIR/box.json.dist"
    local OUTPUT_PHAR="$PHAR_DIR/$tool.phar"

    if [[ ! -f "$BUILD_DIR/composer.json" || ! -f "$BOX_CONFIG" ]]; then
        echo -e "${RED}ERROR: build/$tool needs composer.json and box.json.dist${NC}" >&2
        return 1
    fi

    # Rebuild only when forced, the phar is missing, or the manifest lock is
    # newer than the phar (reproducible builds make a no-change rebuild
    # pointless, and this keeps routine `composer update` runs cheap).
    if (( FORCE == 0 )) && [[ -f "$OUTPUT_PHAR" ]]; then
        if [[ ! "$BUILD_DIR/composer.lock" -nt "$OUTPUT_PHAR" ]]; then
            echo -e "${GREEN}$tool.phar is up to date (manifest unchanged) — skipping build.${NC}"
            return 0
        fi
    fi

    local BOX_BIN
    BOX_BIN="$(locate_box)" || return 1

    echo -e "${GREEN}Installing $tool build dependencies (build-only, git-ignored)...${NC}"
    composer install --working-dir="$BUILD_DIR" --no-interaction --no-dev

    if [[ -f "$BUILD_DIR/prepare.bash" ]]; then
        echo -e "${GREEN}Running build/$tool/prepare.bash...${NC}"
        # shellcheck disable=SC1090 # per-tool hook, path is data
        source "$BUILD_DIR/prepare.bash"
    fi

    echo -e "${GREEN}Compiling $tool.phar via Box...${NC}"
    run_box "$BOX_BIN" compile --config="$BOX_CONFIG" --working-dir="$BUILD_DIR" --no-parallel
    mkdir -p "$PHAR_DIR"
    mv "$BUILD_DIR/$tool.phar" "$OUTPUT_PHAR"
    chmod 0755 "$OUTPUT_PHAR"

    local BUILT_VERSION BUILT_SIZE
    if BUILT_VERSION="$(php "$OUTPUT_PHAR" --version 2>/dev/null | awk 'NR==1')"; then
        :
    else
        BUILT_VERSION="unknown (phar --version returned non-zero)"
    fi
    BUILT_SIZE="$(du -h "$OUTPUT_PHAR" | cut -f1)"
    echo -e "${GREEN}Built $OUTPUT_PHAR ($BUILT_SIZE) — $BUILT_VERSION${NC}"
    echo -e "${GREEN}Commit the updated vendor-phar/$tool.phar and build/$tool/composer.lock.${NC}"
}

status=0
for tool in "${TOOLS[@]}"; do
    build_tool "$tool" || status=1
done
exit $status
