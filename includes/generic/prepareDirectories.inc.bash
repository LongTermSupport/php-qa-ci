mkdir -p "$varDir";
echo '
*
!.gitignore
' > "$varDir/.gitignore"

mkdir -p "$cacheDir";
echo '
*
!.gitignore
' > "$cacheDir/.gitignore"
