# purePropertyHook.nonOptionalParameterPassed

A property hook is marked `@pure-unless-parameter-passed $param`, but `$param` is required, so the
hook is never pure. The full explanation is on
[pureFunction.nonOptionalParameterPassed](pureFunction.nonOptionalParameterPassed.md).

## The correct construction

The parameter the tag names must be optional, so a call that omits it is the pure case. A hook
whose parameter is always passed is impure: replace the tag with `@phpstan-impure`.
