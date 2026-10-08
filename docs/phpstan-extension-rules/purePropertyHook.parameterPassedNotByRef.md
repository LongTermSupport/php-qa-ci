# purePropertyHook.parameterPassedNotByRef

A property hook is marked `@pure-unless-parameter-passed $param`, but `$param` is passed by value,
which cannot make the call impure. The full explanation is on
[pureFunction.parameterPassedNotByRef](pureFunction.parameterPassedNotByRef.md).

## The correct construction

Name a by-reference out parameter in the tag, or drop the tag: mark the hook `@phpstan-pure` when
it has no side effects, or `@phpstan-impure` when it has.
