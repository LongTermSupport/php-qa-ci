# PHPArkitect rules: the optional tier

Opt-in naming conventions, from
[`phparkitect-rules-optional.php`](../../configDefaults/generic/phparkitect-rules-optional.php).
A project enables them by composing `PHPQACI_ARKITECT_RULES_OPTIONAL` into its
`qaConfig/phparkitect.php` (see the
[template](../../templates/qaConfig-phparkitect.php)). Each rule ends its `because` clause with
its identifier, so every violation PHPArkitect prints names the rule that produced it:

| Identifier                | Requires                                                         |
| ------------------------- | ---------------------------------------------------------------- |
| `phpqaci.exceptionSuffix` | every class that is a `\Throwable` has a name ending `Exception` |
| `phpqaci.abstractPrefix`  | an abstract class's name starts with `Abstract`                  |

## Why

These are opinions rather than safe-everywhere conventions, which is why they are opt-in.
`phpqaci.exceptionSuffix` resolves each class's ancestry, so it needs a complete autoloader:
with an incomplete one it matches nothing and passes, rather than failing. Run
`composer dump-autoload` if it finds suspiciously little.

## The correct construction

Name a throwable for what it is, and mark a base class that cannot be instantiated by its
prefix, so both facts are visible at every `catch`, `throw`, `extends` and type hint:

```php
namespace App\Payment;

final class CardDeclinedException extends \RuntimeException {}

abstract class AbstractPaymentGateway implements PaymentGatewayInterface {}
```

## Proving a rule fires

```bash
vendor/bin/arkitect-rule phpqaci.abstractPrefix src/Payment/BaseGateway.php
```

The probe reads the project's entry config, so the tier must be composed into it first. See
[the lane's page](../tools/phpArkitect.md).
