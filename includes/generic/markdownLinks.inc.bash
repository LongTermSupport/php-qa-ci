if [[ -f $projectRoot/README.md ]]
then
    # Retry loop via the shared driver (M-010) — identical behaviour to the
    # hand-written loop it replaces.
    qaSimpleTool "Markdown Links Checker" phpNoXdebug -f "$binDir"/mdlinks
else
    echo "ERROR: The Markdown Links check requires a README.md in the root of the repository"
    echo "ERROR: You must create a README.md to proceed
    "
    exit 1;
fi
