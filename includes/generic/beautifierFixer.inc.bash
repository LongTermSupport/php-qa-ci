set +e

phpNoXdebug -f "$binDir"/phpcbf -- \
    --standard="$phpcsCodingStandardsNameOrPath" \
    --colors \
    --cache="$cacheDir"/phpcbf.cache \
    ${pathsToCheck[@]}

set -e
