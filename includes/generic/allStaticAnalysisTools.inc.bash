echo "

Checking Branch Name Policy
---------------------------
"
runToolGuarded branchNamePolicy

echo "

Running PHPStan
---------------------
"
if [[ "$phpqaQuickTests" == "1" ]]
then
    echo "Skipping PHPStan because \$phpqaQuickTests=1"
else
    runToolGuarded phpstan
fi

echo "

Running PHPArkitect (architecture rules)
----------------------------------------
"
runToolGuarded phpArkitect

echo "

Checking SensitiveParameter Usage
---------------------------------
"
runToolGuarded sensitiveParameterUsage



