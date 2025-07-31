#!/usr/bin/env bash
set -e
set -u
set -o pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"
PHIVE_XML="$PROJECT_ROOT/phive.xml"
VENDOR_PHAR_DIR="$PROJECT_ROOT/vendor-phar"

# Parse options
FORCE_INSTALL=0
while getopts "f" opt; do
    case $opt in
        f) FORCE_INSTALL=1 ;;
        *) echo "Usage: $0 [-f]" >&2; exit 1 ;;
    esac
done

# Check if PHIVE is installed
if ! command -v phive &> /dev/null; then
    echo -e "${RED}Error: PHIVE is not installed${NC}"
    echo "Please install PHIVE from https://phar.io/"
    exit 1
fi

# Check if phive.xml exists
if [[ ! -f "$PHIVE_XML" ]]; then
    echo -e "${YELLOW}No phive.xml found at $PHIVE_XML${NC}"
    exit 0
fi

# Check if all PHARs are already installed (unless -f flag is used)
if [[ $FORCE_INSTALL -eq 0 ]]; then
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

echo -e "${GREEN}Installing PHAR dependencies from phive.xml...${NC}"

# Create vendor-phar directory if it doesn't exist
mkdir -p "$VENDOR_PHAR_DIR"

# Install PHARs with trusted GPG keys
# Add trusted keys here as we add more tools
TRUSTED_KEYS=(
    "C6D76C329EBADE2FB9C458CFC5095986493B4AA0"  # Infection
)

# Build trust keys argument
TRUST_KEYS_ARG=""
if [[ ${#TRUSTED_KEYS[@]} -gt 0 ]]; then
    TRUST_KEYS_ARG="--trust-gpg-keys $(IFS=','; echo "${TRUSTED_KEYS[*]}")"
fi

# Run PHIVE install
cd "$PROJECT_ROOT"
eval "phive install $TRUST_KEYS_ARG"

echo -e "${GREEN}PHAR dependencies installed successfully${NC}"