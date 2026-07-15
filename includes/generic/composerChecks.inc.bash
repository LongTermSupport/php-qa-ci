echo "

Checking Composer Issues - Fix Any Red Stuff (wont fail the process)
---------------------
"
# composer diagnose is informational and must NOT fail the pipeline. Capturing
# its status via the `if` keeps a non-zero result from aborting under errexit,
# without suppressing the output (which is shown above).
if ! phpNoXdebug -f "$(which composer)" -- diagnose; then
    echo "composer diagnose reported issues (shown above) — informational only, not failing the pipeline."
fi

# Check if ergebnis/composer-normalize plugin is allowed
if [ "$(phpNoXdebug -f "$(which composer)" -- config allow-plugins.ergebnis/composer-normalize)" != "true" ]; then
    echo "
ERROR: The ergebnis/composer-normalize plugin is not allowed in your composer.json

To fix this, add the following to your composer.json config section:

    \"config\": {
        \"allow-plugins\": {
            ...
            \"ergebnis/composer-normalize\": true
        }
    }

Then run: composer update nothing

This is required for the PHP QA pipeline to run properly.
"
    exit 1
fi

if [[ "true" == "${qaReadOnly:-false}" ]]; then
    echo "

Checking Composer Normalisation (read-only)
---------------------
"
    # Read-only runs must not modify composer.json. --dry-run makes normalize
    # report (non-zero exit) instead of writing when it WOULD change the file;
    # we then fail with the standard remediation guidance rather than mutating.
    normalizeExit=0
    if phpNoXdebug -f "$(which composer)" -- normalize --dry-run; then
        normalizeExit=0
    else
        normalizeExit=$?
    fi
    if (( normalizeExit != 0 )); then
        reportReadOnlyWouldModify "Composer Normalize" "com"
    fi
else
    echo "

Running Composer Normalise
---------------------
"
    phpNoXdebug -f "$(which composer)" -- normalize
fi

echo "

Dumping Composer Autoloader
---------------------
"
# dump-autoload only (re)writes generated autoloader files under vendor/ — these
# are generated artefacts, not tracked source, so regenerating them is safe even
# in a read-only verification run. This is deliberately NOT gated behind
# qaReadOnly.
phpNoXdebug -f "$(which composer)" -- dump-autoload
