# `phpqaci.pathWordSubstringCheck` — decide what a project is from the project, not a word in its path

**Rule**: `ForbidPathWordSubstringCheckRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

A search for a bare word in a string known to be an absolute path:

```php
private function addMissingPathError(string $path, string $namespaceRoot, string $absPathRoot): void
{
    if (false !== stripos($absPathRoot, 'Magento')) {
        // "this is a Magento project"
```

The search is `str_contains`, `strpos`, `stripos`, `strrpos`, `strripos`, `strstr` or `stristr`, or
one of their `mb_` forms. The needle is a word: a literal, or a value PHPStan knows, made of letters,
digits, `_` and `-` only, such as `'Magento'`, `'vendor'` or `'drupal'`.

The haystack is known to be a path when it is:

- `__DIR__` or `__FILE__`;
- the result of `realpath()`, `getcwd()`, `dirname()`, `sys_get_temp_dir()` or `tempnam()`, or of
  their `Safe\` wrappers;
- `SplFileInfo::getRealPath()`, `getPathname()` or `getPath()`; a `ReflectionClass`,
  `ReflectionFunctionAbstract` or PHPStan `ClassReflection` `getFileName()`; PHPStan's
  `Scope::getFile()`;
- a string (or nullable string) parameter or property whose name states an absolute or root
  location, read word by word across underscores and case changes:
  - `root` after another word: `$absPathRoot`, `$projectRoot`;
  - a directory as the last word, or last two run together: `dir`, `dirname`, `directory`,
    `workdir`, `cwd` or `folder` (`$cacheDir`, `$rootDir`, `$workingDirectory`, `$imageFolder`);
  - `path`, `pathname` or `filepath` after `abs`, `absolute`, `real` or `full` (`$absPath`,
    `$realPath`, `$fullPath`, `$absoluteFilePath`), or the one word `$realpath`;
- either of the last two reached through `?->`, judged by its value when the receiver is not null;
- a concatenation or an interpolated string that starts with one of these, or a string cast of one;
- a local variable no write in the function gives anything but one of these, and which is either
  named as above or written at least once.

## Why this is a hazard

An absolute path says where a checkout is, not what the project is. `stripos($absPathRoot, 'Magento')` holds for every project checked out anywhere under a directory whose name contains the
word, such as `/home/ci/magento-tools/my-app/`, and it fails for a real Magento project checked out
as `/srv/shop/`. The decision then depends on a name nobody chose for that purpose: a CI
workspace, a developer's home directory, a container mount. The result is wrong in exactly the
environments nobody inspects, and nothing reports it.

## The correct construction

Decide from what the project is. For a platform, read the project's own declaration, such as its
`composer.json`: a `magento/*` package name or requirement says the project is a Magento project
wherever it is checked out.

```php
private function isMagentoProject(): bool
{
    $require = $this->decodedComposerJson['require'] ?? [];
    foreach (array_keys(\is_array($require) ? $require : []) as $package) {
        if (str_starts_with((string)$package, 'magento/')) {
            return true;
        }
    }

    return false;
}
```

Where the question really is about where a file sits, compare a path segment below the project
root rather than searching the whole path:

```php
$relative = substr($fileName, \strlen(rtrim($this->projectRoot, '/')) + 1);
if ('generated' === explode('/', $relative, 2)[0]) {
```

Deciding whether code is a dependency's, or test code, has its own detector:
[`phpqaci.unanchoredVendorSubstringCheck`](forbid-unanchored-vendor-substring-check.md) and
[`phpqaci.unanchoredTestsSubstringCheck`](forbid-unanchored-tests-substring-check.md).

## The rule's bounds

What it reports: the searches above, wherever the haystack is known to be a path as listed.

What it does not report, and why:

- a needle with a separator, a dot or a punctuation mark in it (`'/vendor/'`, `'.php'`, `'%'`). A
  separator-bounded segment is a narrower question, and the `vendor/` and `tests/` forms have
  their own rules. A dotless word is a word whatever it means: `'json'`, `'php'` or `'C'` searched
  for in a path is reported, since a directory name can carry it by chance;
- `str_starts_with` and `str_ends_with`, which are anchored: an absolute path never starts with a
  word, and its end is the file's own name;
- a haystack not known to be a path: text (`$output`, `$fileContents`), a bare `$root`, which this
  codebase uses for file contents as often as for a directory, and a name that does not state an
  absolute or root location. A bare `$path`, `$file`, `$filename`, `$fileName`, `$filePath` or
  `$pathname`, or one qualified only by its use (`$requestPath`, `$templateFile`), holds a URL path,
  a basename or a relative path as often as an absolute one, and a name with the word `relative` in
  it (`$relativePath`, `$relativeDir`) says it is not absolute. The name reading is what makes the
  rule precise enough to keep; a path held under another name is not seen;
- a parameter with a name that does not state a path, since its value comes from the caller, and a
  local variable any write of which is not known to be a path, whatever its name: reassigned,
  written by reference, by `foreach`, by destructuring, by `static` or `global`, or by a compound
  assignment other than `.=`. The writes are read for the whole function, so a variable that holds
  a path only before a later reassignment to text is not reported, and `$cacheDir = trim($input)`
  is not reported either. A local not named as a path is reported only when it is written at
  least once;
- `preg_match()` on a path. A pattern can anchor a segment itself (`~/magento/~`), and a pattern
  that does not is the next wider form of this class; no instance was found when the rule was
  built.

The writes of a closure's own variables are counted with those of the function it is in, as PHPStan
names them by that function.

The name bounds are set to the names that state an absolute or root location, because the bare
names are the commonest in PHP and hold URL paths, basenames and relative paths as often as
absolute ones; probes of consumer-shaped code found every false report on that route. Widening
them back to a bare `$path`, `$file` or `$fileName` is an Owner option, recorded on
[#231](https://github.com/LongTermSupport/php-qa-ci/issues/231).

[`phpqaci.unanchoredVendorSubstringCheck`](forbid-unanchored-vendor-substring-check.md) and
[`phpqaci.unanchoredTestsSubstringCheck`](forbid-unanchored-tests-substring-check.md) are the same
class for the two path fragments a QA tool most often asks about.
