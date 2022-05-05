#!/usr/bin/env bash
readonly DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd $DIR
set -e
set -u
set -o pipefail
standardIFS="$IFS"
IFS=$'\n\t'
echo "
===========================================
$(hostname) $0 $@
===========================================
"

## progpilot
## https://github.com/designsecurity/progpilot
## A static analyzer for security purposes
readonly progpilotVersion="v0.8.0"
readonly progPilotPharUrl="https://github.com/designsecurity/progpilot/releases/download/$progpilotVersion/progpilot_$progpilotVersion.phar"

declare -A phars
phars["progpilot"]="$progPilotPharUrl"

echo "Clearing out current phar files"
rm -f "$DIR/*.phar"
echo "Done"

echo "Downloading phar files"
for phar in ${!phars[@]}; do
  echo "Downloading $phar"
  url="${phars["$phar"]}"
  filename="$phar".phar
  wget -q --show-progress $url -O "$filename"
  chmod +x "$filename"
  echo "Done"
done
