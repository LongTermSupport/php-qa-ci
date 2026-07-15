#!/usr/bin/env bash

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Find project root (where composer.json is)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_QA_CI_DIR="$(dirname "$SCRIPT_DIR")"

# shellcheck source=scripts/lib/consumer-write.inc.bash
source "$SCRIPT_DIR/lib/consumer-write.inc.bash"

# Look for project root by searching for composer.json
PROJECT_ROOT=""
CURRENT_DIR="$PWD"

while [[ "$CURRENT_DIR" != "/" ]]; do
    if [[ -f "$CURRENT_DIR/composer.json" ]]; then
        # Check if this composer.json has lts/php-qa-ci as a dependency
        if grep -q "lts/php-qa-ci" "$CURRENT_DIR/composer.json" 2>/dev/null; then
            PROJECT_ROOT="$CURRENT_DIR"
            break
        fi
    fi
    CURRENT_DIR="$(dirname "$CURRENT_DIR")"
done

if [[ -z "$PROJECT_ROOT" ]]; then
    echo -e "${RED}Error: Could not find project root with lts/php-qa-ci dependency${NC}"
    echo "Please run this script from your project directory"
    exit 1
fi

echo -e "${GREEN}Found project root: $PROJECT_ROOT${NC}"

# Function to install workflow
install_workflow() {
    local target_dir="$PROJECT_ROOT/.github/workflows"
    local target_file="$target_dir/qa.yml"
    
    echo -e "${YELLOW}Installing GitHub Actions workflow...${NC}"
    
    # Create .github/workflows directory
    mkdir -p "$target_dir"

    # SEED-ONCE artefact: the workflow is consumer-customisable (matrix,
    # triggers, secrets — things qaConfig/ cannot express), so php-qa-ci seeds
    # it once and never re-syncs. Absent → install; present → leave untouched
    # with an info line. See the ownership model in
    # scripts/lib/consumer-write.inc.bash.
    install_seed_once \
        "$PHP_QA_CI_DIR/templates/github-actions/php-qa-ci.yml" \
        "$target_file" \
        "GitHub Actions workflow"
}

# Function to show customization tips
show_customization_tips() {
    echo -e "\n${YELLOW}=== Customization Tips ===${NC}"
    echo "1. To customize QA behavior, create/modify:"
    echo "   - qaConfig/qaConfig.inc.bash for general settings"
    echo "   - qaConfig/phpstan.neon for PHPStan configuration"
    echo "   - qaConfig/phpunit.xml for PHPUnit configuration"
    echo "   - qaConfig/php_cs.php for PHP CS Fixer rules"
    echo ""
    echo "2. To run specific tools in GitHub Actions:"
    echo "   - Go to Actions tab → PHP QA → Run workflow"
    echo "   - Select specific tool from dropdown"
    echo ""
    echo "3. To skip QA tools in CI, add to workflow:"
    echo "   env:"
    echo "     useInfection: 0  # Skip infection testing"
    echo ""
    echo "4. For parallel testing with paratest:"
    echo "   composer require --dev brianium/paratest"
    echo ""
}

# Main installation flow
main() {
    echo -e "${GREEN}=== PHP QA CI - GitHub Actions Setup ===${NC}\n"
    
    # Check if .github/workflows exists
    if [[ -d "$PROJECT_ROOT/.github/workflows" ]]; then
        echo -e "${YELLOW}Found existing .github/workflows directory${NC}"
    fi
    
    # Install the workflow
    install_workflow
    
    # Check for required composer packages
    echo -e "\n${YELLOW}Checking composer dependencies...${NC}"
    
    if ! grep -q "thecodingmachine/safe" "$PROJECT_ROOT/composer.json"; then
        echo -e "${YELLOW}Note: thecodingmachine/safe not found in composer.json${NC}"
        echo "This is required for Rector safe function conversion"
        echo "Run: composer require thecodingmachine/safe"
    fi
    
    # Show customization tips
    show_customization_tips
    
    echo -e "\n${GREEN}=== Setup Complete ===${NC}"
    echo "Next steps:"
    echo "1. Review and customize .github/workflows/qa.yml"
    echo "2. Commit and push the workflow file"
    echo "3. The QA pipeline will run automatically on push/PR"
    echo ""
    echo "To test locally: vendor/bin/qa"
}

# Run main function
main "$@"