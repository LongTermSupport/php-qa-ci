#!/usr/bin/env bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )";
readonly DIR
cd "$DIR";
set -e
set -u
set -o pipefail
# Note: no standardIFS here — bin/qa runs as its own process and re-derives its
# own standardIFS internally, so setting it in this entrypoint did nothing.
IFS=$'\n\t'
echo "
===========================================
$(hostname) $0 $*
===========================================
"
export phpqaQuickTests=0
export phpUnitQuickTests=0
export phpUnitCoverage=${phpunitCoverage:-0}
export CI=true

# run the QA pipeline, echo tee to stdout and also to log file
mkdir -p var/qa/
bin/qa |& tee var/qa/ci.log

echo "
===========================================
$(hostname) $0 $* COMPLETED
===========================================
"
