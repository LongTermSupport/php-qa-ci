# markdownLinks — validates internal file links and external URLs in README.md
# and docs/ via bin/mdlinks. A project README.md is mandatory.

# shellcheck disable=SC2154 # projectRoot/binDir are set by bin/qa (setConfig) before this fragment is sourced
if [[ -f "$projectRoot/README.md" ]]; then
    qaSimpleTool "Markdown Links Checker" phpNoXdebug -f "$binDir"/mdlinks
else
    echo "ERROR: The Markdown Links check requires a README.md in the root of the repository"
    echo "ERROR: You must create a README.md to proceed
    "
    exit 1
fi
