###############################################################################
# sensitiveParameterUsage — always-on security baseline check.
#
# Fails the pipeline if the native #[\SensitiveParameter] attribute is used
# NOWHERE in the project's src/ directory. PHP 8.2+ redacts a so-marked argument
# from Throwable::getTrace(), keeping passwords / tokens / secrets out of logs
# and error reporters. The check is AST-based (nikic/php-parser) so it never
# false-matches the attribute in strings or comments.
#
# Why a pipeline tool and not a PHPStan rule: PHPStan rules are opt-in (a project
# must include php-qa-ci's rules neon), so they cannot be relied on estate-wide.
# This tool runs for EVERY consumer via `bin/qa`, unconditionally.
#
# ESCAPE HATCH (opt-out, on by default):
#   A project that genuinely never handles a sensitive parameter sets, in
#   qaConfig/qaConfig.inc.bash:
#       export useSensitiveParameterCheck=0
#
# The PHP entrypoint (bin/sensitive-parameter-usage) also honours this env var,
# but we gate here too so the skip is loud and we avoid spawning PHP needlessly.
###############################################################################

# Default the escape-hatch flag to enabled (1). A project opts out with 0.
useSensitiveParameterCheck=${useSensitiveParameterCheck:-1}
export useSensitiveParameterCheck

if [[ "0" == "$useSensitiveParameterCheck" ]]; then
  echo "
SensitiveParameter usage check is disabled for this project (useSensitiveParameterCheck=0) — skipping.
"
else
  sensitiveParameterExitCode=99
  while ((sensitiveParameterExitCode > 0)); do
    # Run inside an `if` so a non-zero exit is captured without aborting under
    # the pipeline's `set -e` — no error suppression, the failure is handled
    # explicitly by the retry/abort branch below.
    if phpNoXdebug -f "$binDir"/sensitive-parameter-usage; then
      sensitiveParameterExitCode=0
    else
      sensitiveParameterExitCode=$?
      tryAgainOrAbort "SensitiveParameter Usage Check"
    fi
  done
fi
