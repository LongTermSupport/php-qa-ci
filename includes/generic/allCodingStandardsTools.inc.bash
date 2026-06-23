echo "

Running Rector
----------------------------
"

runToolGuarded rector

echo "

Running PHP-CS-Fixer
----------------------------
"

runToolGuarded phpCsFixer
