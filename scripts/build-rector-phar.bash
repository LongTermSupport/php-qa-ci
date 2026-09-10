#!/usr/bin/env bash
# Compatibility entry point: Rector is one of the tools scripts/build-phar.bash
# builds from build/<tool>/ manifests. Forwards every argument (e.g. --force).
set -e
set -u
set -o pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
exec "$SCRIPT_DIR/build-phar.bash" rector "$@"
