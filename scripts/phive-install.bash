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
echo -e "${BLUE}[DEBUG] phive-install.bash script started with args: $*${NC}" >&2

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"
PHIVE_XML="$PROJECT_ROOT/phive.xml"
VENDOR_PHAR_DIR="$PROJECT_ROOT/vendor-phar"

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

# Check if PHIVE is installed
if ! command -v phive &> /dev/null; then
    echo -e "${RED}Error: PHIVE is not installed${NC}"
    echo "Please install PHIVE from https://phar.io/"
    exit 1
fi

# Check if phive.xml exists
if [[ ! -f "$PHIVE_XML" ]]; then
    echo -e "${RED}No phive.xml found at $PHIVE_XML${NC}"
    exit 1
fi

# For install mode, check if all PHARs are already installed (unless -f flag is used)
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

    if [[ $ALL_INSTALLED -eq 1 ]]; then
        # Quick exit - all PHARs already installed
        exit 0
    fi
fi

# Create vendor-phar directory if it doesn't exist
mkdir -p "$VENDOR_PHAR_DIR"

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
    echo -e "${BLUE}[DEBUG] Running: phive update $TRUST_KEYS_ARG${NC}" >&2
    eval "phive update $TRUST_KEYS_ARG"
    echo -e "${GREEN}PHAR dependencies updated successfully${NC}"
else
    echo -e "${GREEN}Installing PHAR dependencies from phive.xml...${NC}"
    echo -e "${BLUE}[DEBUG] Running: phive install $TRUST_KEYS_ARG${NC}" >&2
    eval "phive install $TRUST_KEYS_ARG"
    echo -e "${GREEN}PHAR dependencies installed successfully${NC}"
fi
