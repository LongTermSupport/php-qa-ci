echo "

Checking Branch Name Policy
---------------------------
"
runTool branchNamePolicy

echo "

Running PHPStan
---------------------
"
if [[ "$phpqaQuickTests" == "1" ]]
then
    echo "Skipping PHPStan because \$phpqaQuickTests=1"
else
    runTool phpstan
fi

echo "

Checking SensitiveParameter Usage
---------------------------------
"
runTool sensitiveParameterUsage



