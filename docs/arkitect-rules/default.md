# PHPArkitect rules: the default tier

Applied to every project by the `phpArkitect` lane, from
[`phparkitect-rules-default.php`](../../configDefaults/generic/phparkitect-rules-default.php).
Each rule ends its `because` clause with its identifier, so every violation PHPArkitect prints
names the rule that produced it:

| Identifier                      | Requires                                                    |
| ------------------------------- | ----------------------------------------------------------- |
| `phpqaci.interfaceSuffix`       | an interface's name ends in `Interface`                     |
| `phpqaci.enumSuffix`            | an enum's name ends in `Enum`                               |
| `phpqaci.traitSuffix`           | a trait's name ends in `Trait`                              |
| `phpqaci.dtoNamespaceHoldsDtos` | a class in a `Dto` namespace has a name ending in `Dto`     |
| `phpqaci.dtoInDtoNamespace`     | a class whose name ends in `Dto` lives in a `Dto` namespace |
| `phpqaci.dtoFinal`              | a `*Dto` class is `final`                                   |
| `phpqaci.dtoReadonly`           | a `*Dto` class is `readonly`                                |

The four `Dto` rules skip interfaces, enums and traits, which carry their own suffix: a
`ShippingDtoInterface` is correctly named.

## Why

A suffix puts the kind of a symbol in every place it is used: a type hint, an `implements`
list, an import. A reader does not have to open the file to learn that `PaymentGateway` is
an interface they can substitute, or that `Status` is a closed set of cases.

The `Dto` rules are one convention read from three sides. The namespace implies the suffix,
the suffix implies the namespace, and the suffix implies an immutable final class. Together
they stop a data carrier being mistaken for a service, and stop a service hiding among the
data carriers.

## The correct construction

Name the type for its kind, and put a data transfer object where the rest of them live, as a
final readonly class:

```php
namespace App\Payment;

interface PaymentGatewayInterface {}

enum CardBrandEnum: string { case Visa = 'visa'; }

trait RetriesRequestsTrait {}
```

```php
namespace App\Payment\Dto;

final readonly class ChargeDto
{
    public function __construct(public int $amountInPence, public string $currency) {}
}
```

Generated code, which cannot be renamed, is excluded rather than fixed: declare its directory
with `->withArkitectExcludedPaths('Quote/API')` in `qaConfig/qa.php`.

## Proving a rule fires

```bash
vendor/bin/arkitect-rule phpqaci.interfaceSuffix src/Payment/PaymentGateway.php
```

Exit `1` means the rule fired on that file; see [the lane's page](../tools/phpArkitect.md).
