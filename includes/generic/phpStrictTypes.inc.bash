# Strict-types gate — every .php/.phtml file under pathsToCheck must contain
# a `declare(strict_types=1)`.
#
# HISTORY: until 2026-07-15 the find expression was
#   find $d -name '*.php' -o -name '*.phtml' -exec grep ... \;
# where -exec binds tighter than -o, so grep only ever ran on .phtml files and
# .php files were NEVER scanned — the gate was a silent no-op for the entire
# language it exists for. It also prompted interactively with no CI guard and
# edited files in place with no read-only guard. Rewritten: correct scan, no
# prompts.
#
# Behaviour (mirrors the Rector / PHP CS Fixer read-only contract):
#   - read-only run (qaReadOnly=true): report every offending file, FAIL.
#   - writable run: prepend the declaration to the opening <?php tag
#     automatically and report each fixed file; a file that cannot be fixed
#     (no <?php open tag) FAILS the gate.

strictTypesMissingFiles=()
# shellcheck disable=SC2154 # pathsToCheck is set by bin/qa (setPaths) before this fragment is sourced
for strictTypesDir in "${pathsToCheck[@]}"; do
  if [[ ! -d "$strictTypesDir" ]]; then
    continue
  fi
  strictTypesCandidates=()
  while IFS= read -r -d '' strictTypesFile; do
    strictTypesCandidates+=("$strictTypesFile")
  done < <(find "$strictTypesDir" -type f \( -name '*.php' -o -name '*.phtml' \) -print0)

  if ((${#strictTypesCandidates[@]} == 0)); then
    continue
  fi

  # grep -L lists files WITHOUT the pattern. Exit 1 just means "every file
  # contains it" (the good case); >1 is a genuine error. Captured via the if
  # so errexit does not abort the run.
  strictTypesGrepExit=0
  strictTypesGrepOutput=""
  if strictTypesGrepOutput="$(grep -L 'strict_types' "${strictTypesCandidates[@]}")"; then
    strictTypesGrepExit=0
  else
    strictTypesGrepExit=$?
  fi
  if ((strictTypesGrepExit > 1)); then
    echo "ERROR: strict-types scan failed in $strictTypesDir (grep exit $strictTypesGrepExit)"
    exit 1
  fi

  while IFS= read -r strictTypesFile; do
    if [[ -n "$strictTypesFile" ]]; then
      strictTypesMissingFiles+=("$strictTypesFile")
    fi
  done <<< "$strictTypesGrepOutput"
done

if ((${#strictTypesMissingFiles[@]} == 0)); then
  echo "All PHP files declare strict_types"
  return 0
fi

echo "Files missing declare(strict_types=1):"
printf '  %s\n' "${strictTypesMissingFiles[@]}"

if [[ "true" == "${qaReadOnly:-false}" ]]; then
  echo ""
  echo "This is a READ-ONLY run, so nothing was modified. Add the declaration"
  echo "(or run 'QA_READONLY=0 vendor/bin/qa -t st' where writes are allowed),"
  echo "then commit."
  exit 1
fi

strictTypesUnfixable=()
for strictTypesFile in "${strictTypesMissingFiles[@]}"; do
  strictTypesContent="$(<"$strictTypesFile")"
  strictTypesFixed="${strictTypesContent/<?php/<?php declare(strict_types=1);}"
  if [[ "$strictTypesFixed" == "$strictTypesContent" ]]; then
    strictTypesUnfixable+=("$strictTypesFile")
    continue
  fi
  printf '%s\n' "$strictTypesFixed" > "$strictTypesFile"
  echo "fixed: $strictTypesFile"
done

if ((${#strictTypesUnfixable[@]} > 0)); then
  echo ""
  echo "ERROR: could not add declare(strict_types=1) to the following files"
  echo "(no opening <?php tag found) — fix them manually:"
  printf '  %s\n' "${strictTypesUnfixable[@]}"
  exit 1
fi

echo ""
echo "Added declare(strict_types=1) to ${#strictTypesMissingFiles[@]} file(s) — review and commit the changes."
