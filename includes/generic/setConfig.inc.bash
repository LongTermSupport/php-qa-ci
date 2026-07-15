# Global PHP memory limit for all QA tools
# Override with: export phpqaMemoryLimit=8G
# Default is 4G which should handle most projects
phpqaMemoryLimit=${phpqaMemoryLimit:-4G}

# Skip long running tests if globally set to 1
phpqaQuickTests=${phpqaQuickTests:-0}

# the path in the project to check for config
projectConfigPath="$projectRoot/qaConfig/"

# project var dir, sub directory for qa cache files and output files
varDir="$projectRoot/var/qa";

cacheDir="$varDir/cache";

# PHAR directory for tools installed via PHIVE
pharDir="$qaDir/../vendor-phar";

phpVersion="$($phpBinPath -v | grep ^PHP | cut -d' ' -f2)"
noXdebugConfigPath="$varDir/phpqa-no-xdebug.$phpVersion.ini"

# the path in this library for default config
defaultConfigPath="$(readlink -f ./../configDefaults/)"

# configPath function can only be used after this point

# PSR4 validation — one regex pattern per line; -t strips trailing newlines so
# each array entry is a clean argument for bin/psr4-validate.
psr4IgnoreListPath="$(configPath psr4-validate-ignore-list.txt)"
readarray -t psr4IgnoreList < "$psr4IgnoreListPath"

# PHPStan configs
phpstanConfigPath="$(configPath phpstan.neon)"

# PHPArkitect config (opt-in architectural rules).
# configPath returns the project override (qaConfig/phparkitect.php) when present,
# otherwise the generic path — which intentionally does NOT exist by default, so
# the tool skips unless a project opts in. See includes/generic/phpArkitect.inc.bash.
phpArkitectConfigPath="$(configPath phparkitect.php)"

##PHPUnit Configs

#Iterative Mode - prioritises runnign failed tests and stops on first error
phpUnitIterativeMode=${phpUnitIterativeMode:-0}

# PHPUnit Quick Tests - optional skip slow tests
phpUnitQuickTests=${phpUnitQuickTests:-0}

# PHPUnit Coverage - default ENABLED (needed for Infection mutation testing).
# When enabled, tests run with Xdebug and generate coverage (a lot slower).
# Disable per-project with `export phpUnitCoverage=0` in qaConfig/qaConfig.inc.bash.
phpUnitCoverage=${phpUnitCoverage:-1}

# Now check if we are generating coverage and configure the correct file to include
phpUnitConfigPath=$(configPath phpunit.xml)

## Infection options
# Let's use infection by default
useInfection=${useInfection:-1}

# This is the path to our configuration
infectionConfig=$(configPath infection.json)
# Speeds up the tests https://infection.github.io/guide/command-line-options.html#threads
# Can cause issues if the test rely on the database
infectionThreads=${infectionThreads:-$(grep -c ^processor /proc/cpuinfo)}
# Only Covered
infectionOnlyCovered=${infectionOnlyCovered:-0}

# Derivations that depend on project-overridable variables (coverage→infection
# gating, MSI floors) live in deriveDependentConfig (functions.inc.bash). It
# runs here AND again in bin/qa after qaConfig/qaConfig.inc.bash is sourced,
# so project overrides of the inputs actually take effect.
deriveDependentConfig

composerRequireCheckerConfig=$(configPath composerRequireChecker.json)

phpCsConfigPath=$(configPath php_cs.php)
phpCsCacheFile="$varDir/cache/php_cs.cache"

# If a CI variable is set, we use that, otherwise default to false.
# Travis-CI sets a CI variable. You can easily set this in any other CI system
# The value should the the string 'true' if this is CI
# Also detect Claude Code (CLAUDECODE=1) as CI environment
if [[ "${CLAUDECODE:-}" == "1" ]]; then
    CI='true'
else
    CI=${CI:-'false'}
fi
