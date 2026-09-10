# `phpqaci.consistentMemberDocs` — declarations of one kind are documented all or none

**Rule**: `RequireConsistentMemberDocsRule`
**Bundle**: [`rules-optional.neon`](../../rules-optional.neon) (opt in)

## What fires

Within one class, the constants (class constants and enum cases) are documented
some-but-not-all, or the properties (declared and constructor-promoted) are. Each kind is
judged on its own. "Documented" means a docblock with free text; a docblock holding only
tags (`@var`, `@param`, `@see`, ...) is type metadata and does not count.

```php
public function __construct(
    /** Seconds; null for no limit. */
    public ?float $timeout,
    public string $cwd,
    public bool $streamOutput,
) {
}
```

Methods are not checked. A method's docblock explains a body the reader can see; a
declaration has only its name and type, so a comment there is the only place meaning beyond
the name can live, and that is where mixed coverage misleads.

## Why this is a hazard

A mixed block hides two problems at once. An undocumented member next to a documented one
cannot be told apart from a forgotten one, so the reviewer stops asking. And a member that
gets a comment because its neighbour has one tends to get a comment that restates its name
(`/** Whether output is streamed. */ public bool $streamOutput`), which teaches nothing and
trains the reader to skip every comment in the block.

## The correct construction

**Reach for deletion first.** This rule exists to remove comment bloat, not to create it. A
comment that says what the name already says is the defect; delete it and the block is
consistent.

```php
public function __construct(
    public ?float $timeoutSeconds,
    public string $cwd,
    public bool $streamOutput,
) {
}
```

Where the deleted comment carried something the name could not, put it in the name
(`$timeout` became `$timeoutSeconds` above) or in the class docblock.

**Document all of them only when every member has something to say** that its name and type
cannot carry, and then say only that:

```php
/** Kept for one release after the rename. */
public const string OLD_NAME = 'old';

/** The name every new caller uses. */
public const string NEW_NAME = 'new';
```

A `@var list<string>` or `@param` tag is never the problem and never the fix; the rule looks
past tags.

## What is still allowed

A kind with a single member is never mixed. Methods are not checked. Free-text docs on some
methods and not others are fine.

## If you believe an instance is legitimate

Move the one comment to the class docblock or into the name. Inline `@phpstan-ignore` is
banned by `phpqaci.inlinePhpstanIgnore`; an irreducible case belongs in `ignoreErrors` in
the project's `phpstan.neon`, with a path and this identifier.
