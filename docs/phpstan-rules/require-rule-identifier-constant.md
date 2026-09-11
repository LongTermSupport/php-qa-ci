# `phpqaci.ruleIdentifierMustBeConstant` — a rule's identifier must be a class constant

**Rule**: `RequireRuleIdentifierConstantRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on)

## What fires

Inside a class implementing `PHPStan\Rules\Rule`, a call to `RuleErrorBuilder::identifier()`
passing a string literal.

```php
RuleErrorBuilder::message('...')
    ->identifier('phpqaci.myCheck')      // magic string
    ->build();
```

## Why this is a hazard

The identifier is the rule's public name. It is what PHPStan prints on failure, what
`bin/rule-doc` resolves, what an `ignoreErrors` entry matches on, and what the documentation
index is keyed by. It is an API, and a string literal is an API declared in a place nothing can
check.

Concretely, as a literal it can be misspelt with no consequence at author time — the rule still
runs, still reports, and simply becomes unresolvable and un-suppressible under a name nobody
knows. It can also be copy-pasted from another project with that project's prefix intact; this
is not hypothetical, a `counselbook.*` identifier reached php-qa-ci's own rules exactly that
way. And because the same identifier is often needed in the rule's tests and its neon
registration, a literal invites three copies that can disagree.

A constant fixes all three: a typo is a fatal error at the point of use, the prefix is
inherited from one place, and there is a single symbol to find references to.

## The correct construction

**Declare it once, composed from the shared prefix, and reference the constant**:

```php
final readonly class MyRule implements Rule
{
    public const string IDENTIFIER = RuleIdentifierInterface::PREFIX . '.myCheck';

    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message('...')
                ->identifier(self::IDENTIFIER)
                ->build(),
        ];
    }
}
```

The constant is public deliberately — the test asserts against `MyRule::IDENTIFIER` rather than
re-typing the string, so the test cannot drift from the rule.

Having done that, the identifier also has to *resolve*: add a row to
[the rule index](README.md) naming the class and linking its remediation page. A rule that names
itself and then resolves to nothing leaves the reader exactly where they started, which is what
[method specification](https://defence-before-fix.github.io/SPEC.html) clause 3.6 is about.

## What is still allowed

Only `identifier()` calls inside a `Rule` implementation are checked. Identifiers appearing in
neon configuration, in tests, or in `ignoreErrors` entries are data rather than declarations and
are untouched.

A pipeline lane's identifier is governed by the same convention but expressed differently — see
[`ToolInterface`](../../src/Pipeline/Tool/ToolInterface.php) and
[CLAUDE/tool-boundaries.md](../../CLAUDE/tool-boundaries.md) for whether a check should be a
lane or a rule in the first place.

## If you believe an instance is legitimate

There is no case for a literal here: the constant costs one line and is strictly more checkable.
If the identifier is genuinely dynamic, the rule is reporting more than one class of defect and
should be split, because a reader holding one identifier must reach one explanation.
