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
            echo "  update     Re-resolve every phive.xml constraint to its newest release (requires phive)" >&2
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

# Self-built PHARs (one per build/<tool>/ manifest) are committed but not
# listed in phive.xml — scripts/build-phar.bash makes them, there is no
# PHIVE-fetchable upstream. Verify them too so a corrupt checkout is caught.
for manifest in "$PROJECT_ROOT"/build/*/composer.json; do
    built_tool="$(basename "$(dirname "$manifest")")"
    if [[ ! -e "$VENDOR_PHAR_DIR/$built_tool.phar" ]]; then
        PHARS_INSTALLED=0
        echo -e "${RED}Missing PHAR: $VENDOR_PHAR_DIR/$built_tool.phar${NC}"
    fi
done

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
        phive_home_for_run="$PHIVE_HOME"
        if [[ "$MODE" == "update" ]]; then
            # A fresh home: install resolves against its local cache first, and
            # a cached older release would satisfy the constraint and win.
            phive_home_for_run="$(mktemp -d)"
        fi
        phive_install_cmd=(phive --home "$phive_home_for_run" install --copy --force-accept-unsigned)
        if [[ ${#TRUSTED_KEYS[@]} -gt 0 ]]; then
            phive_install_cmd+=(--trust-gpg-keys "$(IFS=','; echo "${TRUSTED_KEYS[*]}")")
        fi

        export XDEBUG_MODE=off
        cd "$PROJECT_ROOT"

        if [[ "$MODE" == "update" ]]; then
            echo -e "${GREEN}Updating PHAR dependencies via phive...${NC}"
            # Every release lookup is a GitHub API call; unauthenticated that is
            # 60 an hour, which a handful of runs exhausts. PHIVE reads
            # GITHUB_AUTH_TOKEN; borrow gh's token when one is not already set.
            if [[ -z "${GITHUB_AUTH_TOKEN:-}" ]] && command -v gh >/dev/null && gh_token="$(gh auth token 2>/dev/null)"; then
                export GITHUB_AUTH_TOKEN="$gh_token"
            fi
            # `phive update` cannot be used here: it has no --trust-gpg-keys or
            # --force-accept-unsigned, so it needs a TTY for key import and
            # skips unsigned releases (parallel-lint) outright. `phive install`
            # takes both flags but reinstalls the installed="" pins. So: drop
            # the pins and let install re-pin whatever the constraints allow.
            # A failed run (rate limit, key server down) must not leave the
            # checkout without its tools: keep a copy and put it back.
            update_backup="$(mktemp -d)"
            cp "$PHIVE_XML" "$update_backup/phive.xml"
            cp -a "$VENDOR_PHAR_DIR" "$update_backup/vendor-phar"
            php -r '$f = $argv[1]; file_put_contents($f, preg_replace("/ installed=\"[^\"]*\"/", "", file_get_contents($f)));' "$PHIVE_XML"
            if "${phive_install_cmd[@]}" </dev/null; then
                rm -rf "$update_backup" "$phive_home_for_run"
            else
                cp "$update_backup/phive.xml" "$PHIVE_XML"
                cp -a "$update_backup/vendor-phar/." "$VENDOR_PHAR_DIR/"
                rm -rf "$update_backup" "$phive_home_for_run"
                echo -e "${RED}ERROR: PHAR update failed; phive.xml and vendor-phar/ restored.${NC}" >&2
                echo "A GitHub API rate limit is the usual cause: set GITHUB_AUTH_TOKEN (or log in with gh) and re-run." >&2
                exit 1
            fi
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
# Phase 2: self-built PHARs (build/<tool>/ manifests, committed at
# vendor-phar/<tool>.phar). These tools publish no PHAR upstream, so we box
# them ourselves; each is an isolated composer graph, so nothing leaks into any
# consumer's composer graph and there is no consumer-side composer subprocess.
#
# In update/force mode a maintainer rebuilds them from their manifests.
# Mirroring the phive block above, the rebuild only runs when Box is actually
# available (a maintainer environment); otherwise it degrades to a silent skip
# using the committed phars, so routine composer events never fail on a missing
# Box. build-phar.bash may auto-download Box when invoked directly, but
# tool-install never triggers that download implicitly.
# ============================================================================

if [[ "$MODE" == "update" ]] || [[ $FORCE_INSTALL -eq 1 ]]; then
    box_available=0
    if [[ -n "${BOX_PHAR:-}" && -f "${BOX_PHAR}" ]]; then
        box_available=1
    elif command -v box >/dev/null; then
        box_available=1
    fi

    if (( box_available == 1 )); then
        echo -e "${GREEN}Rebuilding self-built PHARs from build/<tool>/ manifests...${NC}"
        build_args=(--all)
        if [[ $FORCE_INSTALL -eq 1 ]]; then
            build_args+=(--force)
        fi
        "$SCRIPT_DIR/build-phar.bash" "${build_args[@]}"
        echo "IMPORTANT: commit the updated vendor-phar/<tool>.phar files and build/<tool>/composer.lock."
    else
        for manifest in "$PROJECT_ROOT"/build/*/composer.json; do
            built_tool="$(basename "$(dirname "$manifest")")"
            if [[ ! -f "$VENDOR_PHAR_DIR/$built_tool.phar" ]]; then
                echo -e "${RED}ERROR: vendor-phar/$built_tool.phar is missing and Box is not available to build it.${NC}"
                echo "Install Box (or set BOX_PHAR) and re-run, or restore the committed vendor-phar/$built_tool.phar."
                exit 1
            fi
        done
        echo -e "${GREEN}Self-built PHARs present — skipping rebuild (Box not available; not a maintainer build).${NC}"
    fi
fi
