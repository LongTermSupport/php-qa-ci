echo "

Validating PSR-4 Roots
------------------------
"

runToolGuarded psr4Validate

echo "

Checking for Composer Issues
----------------------------
"

runToolGuarded composerChecks

echo "

Checking Package Type Is Declared
---------------------------------
"

runToolGuarded packageType

echo "
Setting Strict Types If It's Missing
-------------------------------------
"

runToolGuarded phpStrictTypes

echo "

Running PHP Lint
----------------
"
runToolGuarded phpLint

echo "

Running Composer Require Checker
--------------------------------
"
runToolGuarded composerRequireChecker


echo "

Running Markdown Links Checker
------------------------------
"
runToolGuarded markdownLinks
