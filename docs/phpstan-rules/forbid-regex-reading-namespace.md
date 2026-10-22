# A file's namespace is read by the parser, not by a regular expression

**Identifier**: `phpqaci.regexReadsNamespace` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What it requires

Code that needs the namespace a PHP file declares reads it with `nikic/php-parser` (the `Namespace_`
node) or `PhpToken::tokenize()`, never with a regular expression over the file's text. Reported: a
call to `preg_match`, `preg_match_all`, `preg_replace`, `preg_replace_callback`, `preg_split`,
`preg_grep` or `preg_filter` (PHP's or `Safe\`'s) whose pattern PHPStan knows as a constant string
in which the word `namespace`, case-insensitive and not the tail of a longer word, is followed by
whitespace: `\s`, `\h`, `[[:space:]]` or a space. The pattern may be written inline, held in a
constant, or passed by name (`pattern: '...'`).

## Why

A pattern that finds `namespace Foo;` in a PHP file finds every other text of the same shape too:

- a docblock or `//` comment line that starts with the word (`* namespace handling is ...`);
- a line inside a heredoc, a nowdoc or a string;
- a braced `namespace Foo { ... }` that a pattern written for `namespace Foo;` does not anticipate,
  or the other way round;
- a declaration that follows a comment that mentions a different one, so the first match is the
  wrong one.

The file is then attributed to a namespace it does not declare. The parser and the tokenizer tell a
declaration from the same words in a comment or a string; a regular expression over the text
cannot.

## What is not reported

- **A pattern PHPStan cannot read** (a parameter, a value built at run time): nothing is known of
  it.
- **`class`, `interface`, `trait`, `enum` and `use` declarations.** The same hazard applies to a
  pattern that reads them, but each word is ordinary in the text other patterns read (an HTML
  `class="..."` attribute, `use` in prose), so no pattern shape separates a declaration read from
  an unrelated one without false reports.
- **`namespace` without whitespace after it** (`\bnamespaces?\b`) or inside a longer word.
- **A search for the word by other means**, such as `str_contains($contents, 'namespace ')`. The
  hazard is the same; the shape is a different rule's.
- **A function of that name in another namespace**, such as `Helper\preg_match()`.

## How to fix it

```php
// WRONG — a comment or a string that starts a line with "namespace" is read as the declaration
if (1 === \Safe\preg_match('/^\s*namespace\s+([^\s;{]+)\s*[;{]/m', $contents, $matches)) {
    $namespace = $matches[1];
}

// RIGHT — the parser reads the declaration
$namespace = $this->declaredNamespace->of($path);
```

php-qa-ci's own readers share `LTS\PHPQA\PhpFile\DeclaredNamespaceReader`, which parses the file
once and returns the name of its first `Namespace_` node, or the empty string for the global
namespace.

## The class, the search and the bounds

Recorded as Defence Before Fix requires.

- **Class**: a regular expression applied to a PHP file's contents to read what the file declares.
- **Hazard**: the pattern cannot tell a declaration from comment or string text of the same shape,
  or from a form it was not written for (braced namespaces), so a file is attributed to the wrong
  namespace.
- **Search, by two techniques**: a text search of every PHP file for `preg_*` calls and
  `RegexPattern` uses naming `namespace`, `class`, `use`, `interface`, `trait` or `enum` found two
  sites, `ConfigTemplateIgnoreListAuditor::hasNamespace()` and `ClassFileLocator::namespaceOf()`;
  a reading of the code that consumes file contents (`strpos`, `str_contains`, `explode`,
  tokenizer and parser uses) found `PhpStrictTypesTool` testing for `<?php` with `strpos()`, which
  is not a declaration, and every other reader already parsing or tokenizing.
- **Next wider rule, not built**: the same for `class`, `use`, `interface`, `trait` and `enum`
  declarations, because those words occur in the text other patterns legitimately read; and a rule
  for `str_contains()` and `strpos()` over a file's text for a declaration, which has no known
  instance in this repository.
- **Pinned behaviour**: the readers' tests name a namespace before the real one in a comment, a
  braced namespace, and the word in a string.

## Why it is opt-in

A project whose code never reads a PHP file has nothing to fix. php-qa-ci runs it on itself.

## Implementation

- Rule: [`ForbidRegexReadingNamespaceRule`](../../src/PHPStan/Rules/ForbidRegexReadingNamespaceRule.php)
