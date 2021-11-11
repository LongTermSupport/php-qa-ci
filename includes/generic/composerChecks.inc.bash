set +e
echo "

Checking Composer Issues - Fix Any Red Stuff (wont fail the process)
---------------------
"
phpNoXdebug -f $(which composer) -- diagnose

set -e
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
