#!/usr/bin/env bash
set -e
set -u
set -o pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

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

# Check if phive.xml exists
if [[ ! -f "$PHIVE_XML" ]]; then
    echo -e "${RED}No phive.xml found at $PHIVE_XML${NC}"
    exit 1
fi

# For install mode, check if all PHARs are already installed (unless -f flag is used)
# Do this BEFORE checking for phive — if everything is present, phive is not needed
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
        # Quick exit — all tools already installed
        exit 0
    fi
fi

# ============================================================================
# PHAR Installation via PHIVE
# ============================================================================

if ! which phive 1>&2; then
    echo -e "${RED}Error: phive is not installed${NC}"
    echo ""
    echo "PHIVE (PHAR Installation and Verification Environment) is required"
    echo "to install QA tool PHARs with version tracking and GPG verification."
    echo ""
    echo "Install phive: https://phar.io/#Install"
    echo ""
    echo "  wget -O phive.phar https://phar.io/releases/phive.phar"
    echo "  chmod +x phive.phar"
    echo "  sudo mv phive.phar /usr/local/bin/phive"
    echo ""
    exit 1
fi

mkdir -p "$VENDOR_PHAR_DIR"
mkdir -p "$PHIVE_HOME"

# PHAR GPG keys configuration
TRUSTED_KEYS=(
    "C6D76C329EBADE2FB9C458CFC5095986493B4AA0"  # Infection
    "51C67305FFC2E5C0"                          # PHPStan
    "E82B2FB314E9906E"                          # PHP CS Fixer
    "033E5F8D801A2F8D"                          # Composer Require Checker
)

TRUST_KEYS_ARG=""
if [[ ${#TRUSTED_KEYS[@]} -gt 0 ]]; then
    TRUST_KEYS_ARG="--trust-gpg-keys $(IFS=','; echo "${TRUSTED_KEYS[*]}")"
fi

export XDEBUG_MODE=off
cd "$PROJECT_ROOT"

if [[ "$MODE" == "update" ]]; then
    echo -e "${GREEN}Updating PHAR dependencies via phive...${NC}"
    # phive update doesn't support --trust-gpg-keys, causing TTY prompts in CI
    # Workaround: remove existing PHARs and re-install to get latest versions
    for phar_file in "$VENDOR_PHAR_DIR"/*.phar; do
        if [[ -f "$phar_file" ]]; then
            rm "$phar_file"
        fi
    done
    eval "phive --home \"$PHIVE_HOME\" install --copy $TRUST_KEYS_ARG"
else
    echo -e "${GREEN}Installing PHAR dependencies via phive...${NC}"
    eval "phive --home \"$PHIVE_HOME\" install --copy $TRUST_KEYS_ARG"
fi
echo -e "${GREEN}PHAR dependencies installed successfully${NC}"

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
fi
