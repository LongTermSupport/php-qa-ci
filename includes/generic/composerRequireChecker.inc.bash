#!/usr/bin/env bash

composerRequireCheckExitCode=99
while (( composerRequireCheckExitCode > 0 ))
do
    set +e
    # Capture the output to parse it
    requireCheckerOutput=$(phpNoXdebug "$pharDir"/composer-require-checker.phar check --config-file="${composerRequireCheckerConfig}" -- "${projectRoot}/composer.json" 2>&1)
    composerRequireCheckExitCode=$?
    
    # Display the original output
    echo "$requireCheckerOutput"
    
    set -e
    if (( $composerRequireCheckExitCode > 0 ))
    then
        echo "

To fix these issues, you probably need to add things to your 'require' section in your projects composer.json

You might do this by moving things from 'require-dev', or it could be things that are brought in by your dependencies that you need to add.

The ones that say 'ext-json' or similar, you just need to add '\"ext-json\": \"*\"'

Of course the other option is that you refactor your code and stop using your dev dependencies in your production code

NOTE - Safe php - special case - you need to modify the scan-files section and add in files as required.

        "
        
        # Parse the output and build composer require command
        echo "
============================================
Analyzing missing dependencies...
============================================"
        
        # Extract guessed dependencies from the table output
        missingDeps=$(echo "$requireCheckerOutput" | grep -E '^\|.*\|.*ext-.*\|$' | awk -F'|' '{gsub(/^[ \t]+|[ \t]+$/, "", $3); print $3}' | sort -u)
        
        if [[ -n "$missingDeps" ]]; then
            requireCommand="composer require"
            
            # Check what's already installed
            echo "
Checking installed packages..."
            
            while IFS= read -r dep; do
                if [[ "$dep" == ext-* ]]; then
                    # For PHP extensions, check if already in composer.json
                    if ! grep -q "\"$dep\"" "${projectRoot}/composer.json"; then
                        requireCommand="$requireCommand $dep:\"*\""
                    else
                        echo "  ✓ $dep already in composer.json"
                    fi
                else
                    # For regular packages, check if installed
                    if ! composer info "$dep" &>/dev/null; then
                        requireCommand="$requireCommand $dep"
                    else
                        echo "  ✓ $dep already installed"
                    fi
                fi
            done <<< "$missingDeps"
            
            if [[ "$requireCommand" != "composer require" ]]; then
                echo "
============================================
Suggested composer command:
============================================

$requireCommand

============================================
"
            else
                echo "
All dependencies appear to be already declared!
"
            fi
        fi
        
        tryAgainOrAbort "Composer Require Check"
    fi
done
