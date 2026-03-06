#!/usr/bin/env bash
set -e
set -u
set -o pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Debug output to show script is running
echo -e "${BLUE}[DEBUG] tool-install.bash script started with args: $*${NC}" >&2

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"
PHIVE_XML="$PROJECT_ROOT/phive.xml"
VENDOR_PHAR_DIR="$PROJECT_ROOT/vendor-phar"
PHIVE_HOME="$PROJECT_ROOT/.phive-home"

# Default mode is install
MODE="install"
FORCE_INSTALL=0

# Parse arguments
for arg in "$@"; do
    case $arg in
        update)
            MODE="update"
            ;;
        -f|--force)
            FORCE_INSTALL=1
            ;;
        *)
            echo "Usage: $0 [update] [-f|--force]" >&2
            echo "  update     Run phive update instead of install" >&2
            echo "  -f|--force Force reinstall even if PHARs exist" >&2
            exit 1
            ;;
    esac
done

echo -e "${BLUE}[DEBUG] MODE=$MODE, FORCE_INSTALL=$FORCE_INSTALL${NC}" >&2

# Check if phive.xml exists
if [[ ! -f "$PHIVE_XML" ]]; then
    echo -e "${RED}No phive.xml found at $PHIVE_XML${NC}"
    exit 1
fi

# For install mode, check if all PHARs are already installed (unless -f flag is used)
# Do this BEFORE checking for phive - if everything is present, phive is not needed
if [[ "$MODE" == "install" ]] && [[ $FORCE_INSTALL -eq 0 ]]; then
    ALL_INSTALLED=1

    # Extract PHAR locations from phive.xml
    while IFS= read -r location; do
        PHAR_PATH="$PROJECT_ROOT/$location"
        if [[ ! -e "$PHAR_PATH" ]]; then
            ALL_INSTALLED=0
            break
        fi
    done < <(grep -oP 'location="\K[^"]+' "$PHIVE_XML")

    # Also check isolated composer tools
    if [[ ! -f "$PROJECT_ROOT/tools/rector/vendor/bin/rector" ]]; then
        ALL_INSTALLED=0
    fi

    if [[ $ALL_INSTALLED -eq 1 ]]; then
        # Quick exit - all tools already installed
        exit 0
    fi
fi

# Check if PHIVE is installed (only needed when we actually need to install/update)
if ! which phive 1>&2; then
    echo -e "${RED}Error: PHIVE is not installed${NC}"
    echo "Please install PHIVE from https://phar.io/"
    exit 1
fi

# Create vendor-phar and phive-home directories if they don't exist
mkdir -p "$VENDOR_PHAR_DIR"
mkdir -p "$PHIVE_HOME"

# PHAR GPG keys configuration
# Add trusted keys here as we add more tools
TRUSTED_KEYS=(
    "C6D76C329EBADE2FB9C458CFC5095986493B4AA0"  # Infection
    "51C67305FFC2E5C0"                          # PHPStan
    "E82B2FB314E9906E"                          # PHP CS Fixer
    "033E5F8D801A2F8D"                          # Composer Require Checker
)

# Build trust keys argument for install only
TRUST_KEYS_ARG=""
if [[ ${#TRUSTED_KEYS[@]} -gt 0 ]]; then
    TRUST_KEYS_ARG="--trust-gpg-keys $(IFS=','; echo "${TRUSTED_KEYS[*]}")"
fi

export XDEBUG_MODE=off

# Run PHIVE command
cd "$PROJECT_ROOT"
if [[ "$MODE" == "update" ]]; then
    echo -e "${GREEN}Updating PHAR dependencies...${NC}"
    # phive update doesn't support --trust-gpg-keys, causing TTY prompts in CI
    # Workaround: remove existing PHARs and re-install to get latest versions
    echo -e "${BLUE}[DEBUG] Removing existing PHARs to force fresh install${NC}" >&2
    for phar_file in "$VENDOR_PHAR_DIR"/*.phar; do
        if [[ -f "$phar_file" ]]; then
            echo -e "${BLUE}[DEBUG] Removing $phar_file${NC}" >&2
            rm "$phar_file"
        fi
    done
    echo -e "${BLUE}[DEBUG] Running: phive --home $PHIVE_HOME install --copy $TRUST_KEYS_ARG${NC}" >&2
    eval "phive --home \"$PHIVE_HOME\" install --copy $TRUST_KEYS_ARG"
    echo -e "${GREEN}PHAR dependencies updated successfully${NC}"
else
    echo -e "${GREEN}Installing PHAR dependencies from phive.xml...${NC}"
    echo -e "${BLUE}[DEBUG] Running: phive --home $PHIVE_HOME install --copy $TRUST_KEYS_ARG${NC}" >&2
    # Use --copy to copy PHARs instead of symlinking (works better cross-platform)
    # Use --home to isolate GPG keys and cache to this library
    eval "phive --home \"$PHIVE_HOME\" install --copy $TRUST_KEYS_ARG"
    echo -e "${GREEN}PHAR dependencies installed successfully${NC}"
fi

# ============================================================================
# Isolated Composer Tools
# These tools are installed in their own sub-composer projects to prevent
# their dependencies from leaking into the project's vendor directory.
# ============================================================================

RECTOR_DIR="$PROJECT_ROOT/tools/rector"

if [[ "$MODE" == "update" ]]; then
    echo -e "${GREEN}Updating isolated Rector installation...${NC}"
    composer update --working-dir="$RECTOR_DIR" --no-interaction --no-dev 2>&1
    echo -e "${GREEN}Rector updated successfully${NC}"
elif [[ ! -f "$RECTOR_DIR/vendor/bin/rector" ]]; then
    echo -e "${GREEN}Installing isolated Rector...${NC}"
    composer install --working-dir="$RECTOR_DIR" --no-interaction --no-dev 2>&1
    echo -e "${GREEN}Rector installed successfully${NC}"
else
    echo -e "${BLUE}[DEBUG] Rector already installed, skipping${NC}" >&2
fi
