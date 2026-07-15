# deploy-skills phase: PHPStan custom-rule infrastructure (Defence Before Fix).
#
# Sourced by scripts/deploy-skills.bash. Relies on the orchestrator's
# PROJECT_ROOT and QACI_PATH. Seeds qaConfig/PHPStan and src/PHPStan CLAUDE.md
# guardrails (SEED-ONCE — only when absent) and adds the QaConfig\ autoload-dev
# PSR-4 mapping to composer.json (SHARED / merge — only when missing).

# ============================================================================
# Phase 6: PHPStan Custom Rule Infrastructure (Defence Before Fix)
# ============================================================================
# Scaffold the project for custom PHPStan rules so the "Defence Before Fix"
# workflow can function. See: https://ltscommerce.dev/articles/defence-before-fix-static-analysis
echo ""
echo "📐 Setting up PHPStan custom rule infrastructure..."

QACONFIG_DIR="$PROJECT_ROOT/qaConfig"
PHPSTAN_RULES_DIR="$QACONFIG_DIR/PHPStan/Rules"
SRC_PHPSTAN_DIR="$PROJECT_ROOT/src/PHPStan"
TEMPLATES_DIR="$QACI_PATH/templates"

# Create qaConfig/PHPStan/Rules/ directory
mkdir -p "$PHPSTAN_RULES_DIR"

# Create qaConfig/PHPStan/CLAUDE.md (only if not exists — project may customise)
if [[ ! -f "$QACONFIG_DIR/PHPStan/CLAUDE.md" ]]; then
    if [[ -f "$TEMPLATES_DIR/qaConfig-PHPStan-CLAUDE.md" ]]; then
        cp "$TEMPLATES_DIR/qaConfig-PHPStan-CLAUDE.md" \
           "$QACONFIG_DIR/PHPStan/CLAUDE.md"
        echo "  ✓ Created qaConfig/PHPStan/CLAUDE.md"
    fi
else
    echo "  ✓ qaConfig/PHPStan/CLAUDE.md already exists"
fi

# Create src/PHPStan/CLAUDE.md guardrail (only if not exists)
if [[ -d "$PROJECT_ROOT/src" ]]; then
    mkdir -p "$SRC_PHPSTAN_DIR"
    if [[ ! -f "$SRC_PHPSTAN_DIR/CLAUDE.md" ]]; then
        if [[ -f "$TEMPLATES_DIR/src-PHPStan-CLAUDE.md" ]]; then
            cp "$TEMPLATES_DIR/src-PHPStan-CLAUDE.md" \
               "$SRC_PHPSTAN_DIR/CLAUDE.md"
            echo "  ✓ Created src/PHPStan/CLAUDE.md guardrail"
        fi
    else
        echo "  ✓ src/PHPStan/CLAUDE.md guardrail already exists"
    fi
fi

# Ensure autoload-dev has QaConfig\ namespace mapping
if [[ -f "$PROJECT_ROOT/composer.json" ]]; then
    python3 - "$PROJECT_ROOT/composer.json" << 'PYTHON_AUTOLOAD'
import json
import sys

composer_file = sys.argv[1]
try:
    with open(composer_file, 'r') as f:
        composer = json.load(f)

    autoload_dev = composer.setdefault('autoload-dev', {})
    psr4 = autoload_dev.setdefault('psr-4', {})

    if 'QaConfig\\' not in psr4:
        psr4['QaConfig\\'] = ['qaConfig/']
        new_content = json.dumps(composer, indent=4) + '\n'
        with open(composer_file, 'w') as f:
            f.write(new_content)
        print("  ✓ Added QaConfig\\ autoload-dev PSR-4 entry")
        print("  ⚠ Run 'composer dump-autoload' to regenerate autoloader")
    else:
        print("  ✓ QaConfig\\ autoload-dev entry already present")

except Exception as e:
    print(f"  ⚠️  Could not update composer.json: {e}", file=sys.stderr)
PYTHON_AUTOLOAD
fi

echo "  ✓ PHPStan custom rule infrastructure ready"
