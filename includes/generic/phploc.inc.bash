
if [[ -f "$binDir"/phploc ]]; then
  phpNoXdebug -f "$binDir"/phploc ${pathsToCheck[@]}
fi
