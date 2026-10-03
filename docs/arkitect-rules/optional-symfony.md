# PHPArkitect rules: the optional Symfony tier

Opt-in Symfony naming conventions, from
[`phparkitect-rules-optional-symfony.php`](../../configDefaults/generic/phparkitect-rules-optional-symfony.php).
A Symfony project enables them by composing `PHPQACI_ARKITECT_RULES_OPTIONAL_SYMFONY` into its
`qaConfig/phparkitect.php` (see the [template](../../templates/qaConfig-phparkitect.php)). Each
rule ends its `because` clause with its identifier, so every violation PHPArkitect prints names
the rule that produced it:

| Identifier                 | Requires                                                        |
| -------------------------- | --------------------------------------------------------------- |
| `phpqaci.commandSuffix`    | a console `Command` subclass has a name ending in `Command`     |
| `phpqaci.subscriberSuffix` | an `EventSubscriberInterface` has a name ending in `Subscriber` |

## Why

Commands and subscribers are invoked by the framework, never called by name from the
project's own code, so the name is the only thing telling a reader what a class is for.
Both rules key off the framework's base types, resolving each class's ancestry: they need a
complete autoloader, and with an incomplete one they match nothing and pass.

## The correct construction

Suffix the class with the framework role it plays, whatever namespace it sits in:

```php
namespace App\Billing\Console;

use Symfony\Component\Console\Command\Command;

final class ReconcileInvoicesCommand extends Command {}
```

```php
namespace App\Billing\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class InvoicePaidSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return []; }
}
```

## Proving a rule fires

```bash
vendor/bin/arkitect-rule phpqaci.commandSuffix src/Billing/Console/Reconcile.php
```

The probe reads the project's entry config, so the tier must be composed into it first. See
[the lane's page](../tools/phpArkitect.md).
