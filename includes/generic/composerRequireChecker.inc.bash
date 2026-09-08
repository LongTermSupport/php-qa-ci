# Composer Require Checker — every symbol production code uses must be declared
# in composer.json's require section. (The safe scan-files entries in its
# config are checked against the running PHP by the versionPins lane.)
# shellcheck disable=SC2154 # pharDir/composerRequireCheckerConfig/projectRoot are set by
#   bin/qa (setConfig) before this fragment is sourced — genuine sourced-fragment architecture.
composerRequireCheckExitCode=99
while (( composerRequireCheckExitCode > 0 ))
do
    # Exit code captured via the if so a non-zero status does not abort under errexit.
    if phpNoXdebug "$pharDir"/composer-require-checker.phar check --config-file="${composerRequireCheckerConfig}" -- "${projectRoot}/composer.json"
    then
        composerRequireCheckExitCode=0
    else
        composerRequireCheckExitCode=$?
    fi

    if (( composerRequireCheckExitCode > 0 ))
    then
        echo "

To fix these issues, you probably need to add things to your 'require' section in your projects composer.json

You might do this by moving things from 'require-dev', or it could be things that are brought in by your dependencies that you need to add.

The ones that say 'ext-json' or similar, you just need to add '\"ext-json\": \"*\"'

Of course the other option is that you refactor your code and stop using your dev dependencies in your production code

NOTE - Safe php - special case - you need to modify the scan-files section and add in files as required.

        "

        echo "
HOW TO FIX
----------
1. Add the package to your 'require' section in composer.json (not require-dev).
   If the package is currently in require-dev, either move it to require or stop
   using it in production code (src/).

2. For PHP extensions (ext-json, ext-mbstring, etc.):
   composer require ext-json:\"*\"

3. For Safe functions (thecodingmachine/safe):
   Add the generated file paths to the 'scan-files' section in your
   qaConfig/composerRequireChecker.json override.

⚠️  WARNING — DO NOT ADD THESE SYMBOLS TO THE WHITELIST
--------------------------------------------------------
Never add symbols from dev-dependency packages to the symbol-whitelist.
The whitelist exists for language built-ins and unavoidable edge cases only.

If a symbol's package is in require-dev but your production code (src/) uses it,
adding it to the whitelist silently hides a real problem:

  • In a production (non-dev) install, Composer does NOT install require-dev packages.
  • Transitive dependencies of require-dev packages are also absent.
  • Your application will crash at runtime with class-not-found errors.

The correct fix is always one of:
  a) Move the package from require-dev to require   (it IS a runtime dependency)
  b) Remove the usage from production code          (it should NOT be a runtime dependency)

There is no scenario where whitelisting a dev-dependency symbol is the right answer.
        "

        tryAgainOrAbort "Composer Require Check"
    fi
done
