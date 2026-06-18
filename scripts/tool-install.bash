#!/usr/bin/env bash
set -e
set -u
set -o pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
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
            echo "  update     Run phive update instead of install (requires phive)" >&2
            echo "  -f|--force Force reinstall even if PHARs exist (requires phive)" >&2
            exit 1
            ;;
    esac
done

# ============================================================================
# Phase 1: Check PHAR dependencies
# PHARs are committed to the repo, so they should always be present.
# Phive is only needed for 'update' mode (maintainer workflow) or --force.
# ============================================================================

PHARS_INSTALLED=1
if [[ ! -f "$PHIVE_XML" ]]; then
    echo -e "${RED}No phive.xml found at $PHIVE_XML${NC}"
    exit 1
fi

# Check if all PHARs are present
while IFS= read -r location; do
    PHAR_PATH="$PROJECT_ROOT/$location"
    if [[ ! -e "$PHAR_PATH" ]]; then
        PHARS_INSTALLED=0
        echo -e "${RED}Missing PHAR: $PHAR_PATH${NC}"
        break
    fi
done < <(grep -oP 'location="\K[^"]+' "$PHIVE_XML")

if [[ "$MODE" == "update" ]] || [[ $FORCE_INSTALL -eq 1 ]]; then
    # Maintainer workflow: use phive to update/reinstall PHARs
    if ! which phive 1>&2; then
        if [[ $PHARS_INSTALLED -eq 1 ]]; then
            # PHARs are already present (committed to repo) — skip silently.
            # This happens when composer normalize or other commands trigger post-update-cmd.
            echo -e "${GREEN}PHARs already present — skipping phive update (phive not installed)${NC}"
        else
            echo -e "${RED}Error: phive is not installed${NC}"
            echo ""
            echo "Phive is required for updating PHAR dependencies."
            echo "PHARs are committed to the repo, so phive is only needed by maintainers."
            echo ""
            echo "Install phive: https://phar.io/#Install"
            echo ""
            exit 1
        fi
    else
        mkdir -p "$VENDOR_PHAR_DIR"
        mkdir -p "$PHIVE_HOME"

        # PHAR GPG keys configuration
        TRUSTED_KEYS=(
            "C6D76C329EBADE2FB9C458CFC5095986493B4AA0"  # Infection
            "51C67305FFC2E5C0"                          # PHPStan
            "E82B2FB314E9906E"                          # PHP CS Fixer
            "033E5F8D801A2F8D"                          # Composer Require Checker
            "47CD54B6398FE21B3709D0A4D9C905CED1932CA2"  # PHPArkitect (Michele Orselli)
        )

        TRUST_KEYS_ARG=""
        if [[ ${#TRUSTED_KEYS[@]} -gt 0 ]]; then
            TRUST_KEYS_ARG="--trust-gpg-keys $(IFS=','; echo "${TRUSTED_KEYS[*]}")"
        fi

        export XDEBUG_MODE=off
        cd "$PROJECT_ROOT"

        if [[ "$MODE" == "update" ]]; then
            echo -e "${GREEN}Updating PHAR dependencies via phive...${NC}"
            # Remove existing PHARs and re-install to get latest versions
            for phar_file in "$VENDOR_PHAR_DIR"/*.phar; do
                if [[ -f "$phar_file" ]]; then
                    rm "$phar_file"
                fi
            done
            eval "phive --home \"$PHIVE_HOME\" install --copy $TRUST_KEYS_ARG"
        else
            echo -e "${GREEN}Force-installing PHAR dependencies via phive...${NC}"
            eval "phive --home \"$PHIVE_HOME\" install --copy $TRUST_KEYS_ARG"
        fi
        echo -e "${GREEN}PHAR dependencies installed successfully${NC}"
        echo ""
        echo "IMPORTANT: PHARs are tracked in git. Commit the updated vendor-phar/ and phive.xml."
    fi

elif [[ $PHARS_INSTALLED -eq 0 ]]; then
    echo -e "${RED}ERROR: PHAR dependencies are missing but should be committed to the repo.${NC}"
    echo ""
    echo "This indicates a corrupted or incomplete checkout."
    echo "PHARs should be present in vendor-phar/ as they are tracked in version control."
    echo ""
    echo "To fix: re-clone the repository, or run with --force flag (requires phive)."
    exit 1
fi

# ============================================================================
# Phase 2: Isolated Composer Tools (Rector)
# These are installed via composer in their own sub-projects to prevent
# dependency conflicts. composer.json and composer.lock are tracked,
# but vendor/ is not (too large). Installed on first use.
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
