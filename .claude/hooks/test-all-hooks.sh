#!/bin/bash
# The hooks live beside this script, wherever the package is checked out; an
# absolute path here only ever matched one machine.
cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1

echo "🧪 Running self-tests for all hooks..."
echo "======================================"

total_passed=0
total_failed=0

for hook in php-qa-ci__*.py; do
  if grep -q "def self_test" "$hook"; then
    echo ""
    echo "Testing: $hook"
    if python3 "$hook" --self-test; then
      ((total_passed++))
    else
      ((total_failed++))
    fi
  else
    echo ""
    echo "⚠️  Skipping: $hook (no --self-test)"
  fi
done

echo ""
echo "======================================"
echo "Overall: $total_passed passed, $total_failed failed"
exit $total_failed
