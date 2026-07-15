#!/usr/bin/env bash
# Shared logic for php-qa-ci bin/ "redirect" stubs — tools that are fully
# managed by the QA pipeline and must not be invoked directly. Each stub
# sources this fragment and calls phpQaCiRedirectStub with a tool label and
# one or more usage lines, then exits 1.
#
# Consumed by: bin/composer-require-checker, bin/infection, bin/php-cs-fixer,
# bin/phpstan.
#
# Usage line convention: write the qa command placeholder as the literal
# token __QACMD__ (NOT $qaCmd, to avoid bash expanding it before it reaches
# this function); it is substituted with the resolved relative qa command.
#
# Output must stay byte-identical to the pre-consolidation per-stub copies.

function phpQaCiRedirectStub() {
    local toolLabel="$1"
    shift

    # Find project root by walking up to composer.json
    local projectRoot="$COMPOSER_RUNTIME_BIN_DIR"
    while [[ ! -f "$projectRoot/composer.json" && "$projectRoot" != "/" ]]; do
        projectRoot="$(dirname "$projectRoot")"
    done
    # Make bin dir relative to project root
    local qaCmd="${COMPOSER_RUNTIME_BIN_DIR#"$projectRoot/"}/qa"

    echo "$toolLabel is managed by php-qa-ci. Run it via the QA pipeline:"
    echo ""
    local usageLine
    for usageLine in "$@"; do
        echo "${usageLine//__QACMD__/$qaCmd}"
    done
    exit 1
}
