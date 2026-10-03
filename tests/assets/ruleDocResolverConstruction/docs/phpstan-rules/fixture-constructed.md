# Fixture: a stated construction

## How to fix a failure

Declare the value as a typed class constant, reference it by name at every call site, and
let the compiler reject a misspelt reference instead of a reviewer having to notice it.
