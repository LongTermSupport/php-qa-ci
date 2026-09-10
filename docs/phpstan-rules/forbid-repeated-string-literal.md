# `phpqaci.repeatedStringLiteral` — a repeated string literal is an undeclared constant

**Rule**: `ForbidRepeatedStringLiteralRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

The same string literal, three or more characters long, written three or more times inside
one class.

```php
final class ComposerChecksToolTest extends TestCase
{
    public function diagnoses(): void
    {
        $this->processes->willSucceed('8.5.10')->willSucceed('diagnose ok');
    }

    public function normalises(): void
    {
        $this->processes->willSucceed('8.5.10')->willSucceed('normalized');
    }

    public function dumps(): void
    {
        $this->processes->willSucceed('8.5.10')->willSucceed();
    }
}
```

The message names the value, the count and every line it appears on.

## Why this is a hazard

A value written three times has no single definition. When it has to change, one occurrence
is edited and the others stay, and nothing reports it until the stale one matters. And the
reader is left to guess what `'8.5.10'` is and whether the three occurrences are meant to be
equal or merely happen to be. A constant answers both: it is defined once, and its name says
what it is.

## The correct construction

Declare it once, named for what it means, and use the name.

```php
final class ComposerChecksToolTest extends TestCase
{
    private const string PHP_VERSION = '8.5.10';

    public function diagnoses(): void
    {
        $this->processes->willSucceed(self::PHP_VERSION)->willSucceed('diagnose ok');
    }
}
```

In production code the same applies to a separator, a git ref, a tool name or a banner
rule: `private const string RULE = '---------------------';` and use `self::RULE`.

Where the value belongs to another class (a tool's canonical name, an identifier), reference
that class's constant rather than declaring a second one.

## What is not counted

- **Anything inside a class-constant declaration.** A constant is already the single named
  definition this rule asks for, so its own value can never be the defect. This is what
  keeps a golden data table readable:

  ```php
  private const array GOLDEN_ALIAS_MAP = [
      'psr'  => 'psr4Validate',
      'psr4' => 'psr4Validate',
      'com'  => 'composerChecks',
  ];
  ```

- **Array keys and array-dimension indexes** (`['name' => ...]`, `$row['name']`): the
  repeated key is how an array shape is spelled, and PHPStan's array-shape typing already
  reports a misspelt key where the shape is typed.
- **Attribute arguments** (`#[Group('slow')]`): declarative metadata read by tooling.
- **Literals shorter than three characters, or whitespace only** (`', '`, `'/'`, `"\n"`).
- **Fewer than three occurrences**: two is as often coincidence as identity.
- **A nested class-like** is counted by its own class, not by the one enclosing it.

## If you believe an instance is legitimate

Declare the constant; it is a one-line change. Inline `@phpstan-ignore` is banned by
`phpqaci.inlinePhpstanIgnore`; an irreducible case belongs in `ignoreErrors` in the project's
`phpstan.neon`, with a path and this identifier.
