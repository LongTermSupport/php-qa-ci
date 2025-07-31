set +e
echo "

Checking Composer Issues - Fix Any Red Stuff (wont fail the process)
---------------------
"
phpNoXdebug -f $(which composer) -- diagnose

set -e

# Check if ergebnis/composer-normalize plugin is allowed
if ! phpNoXdebug -f $(which composer) -- config --json | grep -q '"ergebnis/composer-normalize": true'; then
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

echo "

Running Composer Normalise
---------------------
"
phpNoXdebug -f $(which composer) normalize

echo "

Dumping Composer Autoloader
---------------------
"
phpNoXdebug -f $(which composer) -- dump-autoload
