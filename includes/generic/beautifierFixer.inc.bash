set +e

phpNoXdebug -f "$qaDir"/phpcbf -- \
    --standard="$phpcsCodingStandardsNameOrPath" \
    --colors \
    --cache="$cacheDir"/phpcbf.cache \
    ${pathsToCheck[@]}

set -e
