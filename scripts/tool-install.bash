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

# rector.phar is committed but deliberately NOT listed in phive.xml — it is
# self-built (scripts/build-rector-phar.bash), with no PHIVE-fetchable upstream
# source. Verify it separately so a missing/corrupt checkout is still caught.
if [[ ! -e "$VENDOR_PHAR_DIR/rector.phar" ]]; then
    PHARS_INSTALLED=0
    echo -e "${RED}Missing PHAR: $VENDOR_PHAR_DIR/rector.phar${NC}"
fi

if [[ "$MODE" == "update" ]] || [[ $FORCE_INSTALL -eq 1 ]]; then
    # Maintainer workflow: use phive to update/reinstall PHARs
    if ! command -v phive >/dev/null; then
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
            # Read from the release signatures. The upstream README states a different
            # fingerprint; it is stale. Verification record: plan 00004 journal.
            "6371FDC534E47BD979208B6F21A10B2F4F0488C9"  # Twig CS Fixer (Vincent Langlet)
            # Read from the release signature (keyserver UID Andreas Möller). Verification record: plan 00006 journal.
            "0FDE18AE1D09E19F60F6B1CBC00543248C87FB13"  # composer-normalize (Andreas Möller)
        )

        # Build the phive invocation as an argument array (no eval). eval'ing a
        # command string that splices a comma-joined key list is exactly the
        # quoting hazard eval invites; an array keeps every argument intact.
        # parallel-lint publishes an unsigned release asset (no GPG signature
        # to verify); every other entry is checked against TRUSTED_KEYS.
        phive_install_cmd=(phive --home "$PHIVE_HOME" install --copy --force-accept-unsigned)
        if [[ ${#TRUSTED_KEYS[@]} -gt 0 ]]; then
            phive_install_cmd+=(--trust-gpg-keys "$(IFS=','; echo "${TRUSTED_KEYS[*]}")")
        fi

        export XDEBUG_MODE=off
        cd "$PROJECT_ROOT"

        if [[ "$MODE" == "update" ]]; then
            echo -e "${GREEN}Updating PHAR dependencies via phive...${NC}"
            # Remove existing phive-managed PHARs and re-install to get latest
            # versions. rector.phar is NOT phive-managed (self-built, no upstream
            # source); it is rebuilt separately in Phase 2, so never delete it here.
            for phar_file in "$VENDOR_PHAR_DIR"/*.phar; do
                if [[ -f "$phar_file" && "$(basename "$phar_file")" != "rector.phar" ]]; then
                    rm "$phar_file"
                fi
            done
            "${phive_install_cmd[@]}"
        else
            echo -e "${GREEN}Force-installing PHAR dependencies via phive...${NC}"
            "${phive_install_cmd[@]}"
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
# Phase 2: Rector PHAR (self-built, committed at vendor-phar/rector.phar).
# Rector is NOT a phive tool (no upstream phar) and NOT an isolated composer
# sub-project any more — the committed phar bundles its own extracted phpstan,
# so nothing leaks into any composer graph and there is no consumer-side
# composer subprocess.
#
# In update/force mode a maintainer rebuilds the phar from the build/rector-phar/
# manifest. Mirroring the phive block above, the rebuild only runs when Box is
# actually available (a maintainer environment); otherwise it degrades to a
# silent skip using the committed phar, so routine composer events never fail on
# a missing Box. build-rector-phar.bash may auto-download Box when invoked
# directly, but tool-install never triggers that download implicitly.
# ============================================================================

if [[ "$MODE" == "update" ]] || [[ $FORCE_INSTALL -eq 1 ]]; then
    rector_box_available=0
    if [[ -n "${BOX_PHAR:-}" && -f "${BOX_PHAR}" ]]; then
        rector_box_available=1
    elif command -v box >/dev/null; then
        rector_box_available=1
    fi

    if (( rector_box_available == 1 )); then
        echo -e "${GREEN}Rebuilding rector.phar from build/rector-phar/ manifest...${NC}"
        rector_build_args=()
        if [[ $FORCE_INSTALL -eq 1 ]]; then
            rector_build_args+=(--force)
        fi
        "$SCRIPT_DIR/build-rector-phar.bash" "${rector_build_args[@]}"
        echo "IMPORTANT: commit the updated vendor-phar/rector.phar and build/rector-phar/composer.lock."
    elif [[ -f "$VENDOR_PHAR_DIR/rector.phar" ]]; then
        echo -e "${GREEN}rector.phar present — skipping rebuild (Box not available; not a maintainer build).${NC}"
    else
        echo -e "${RED}ERROR: vendor-phar/rector.phar is missing and Box is not available to build it.${NC}"
        echo "Install Box (or set BOX_PHAR) and re-run, or restore the committed vendor-phar/rector.phar."
        exit 1
    fi
fi
