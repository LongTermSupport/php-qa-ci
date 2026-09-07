# `phpqaci.rawSql` — no concatenation in a DBAL SQL argument

**Rule**: `ForbidRawSqlRule`
**Bundle**: [`rules-optional-symfony.neon`](../../rules-optional-symfony.neon) (opt in)

## What fires

String concatenation anywhere inside an argument to `executeQuery()`, `executeStatement()` or
`prepare()`. The rule walks the whole argument expression, so concatenation nested inside a ternary,
a function call or a further concatenation is found too.

```php
$conn->executeQuery('SELECT * FROM orders WHERE customer_id = ' . $customerId);
$conn->prepare('SELECT * FROM ' . $table . ' WHERE status = ?');
```

## Why this is a hazard

SQL injection (OWASP A03). Any concatenated value becomes SQL syntax rather than data, so a value
containing a quote, a semicolon or a comment marker changes what the statement does.

The rule flags concatenation regardless of what is being concatenated, including values that look
obviously safe at the point you write them. That is deliberate. `$customerId` is an `int` today and
becomes a `string` when the column changes; the constant you interpolated becomes a config value;
the private method gains a public caller. The pattern is what makes each of those a vulnerability
later, so the pattern is what gets caught, rather than an attempt to judge each value's provenance.

## The correct construction

**Use placeholders and pass the values separately:**

```php
$conn->executeQuery(
    'SELECT * FROM orders WHERE customer_id = :customerId AND status = :status',
    ['customerId' => $customerId, 'status' => $status],
);
```

Positional placeholders work equally well:

```php
$stmt = $conn->prepare('SELECT * FROM orders WHERE customer_id = ?');
$stmt->bindValue(1, $customerId, ParameterType::INTEGER);
```

**Use the query builder** where the query is assembled conditionally, which is where concatenation
is most tempting:

```php
$qb = $conn->createQueryBuilder()
    ->select('*')
    ->from('orders')
    ->where('customer_id = :customerId')
    ->setParameter('customerId', $customerId);

if (null !== $status) {
    $qb->andWhere('status = :status')->setParameter('status', $status);
}
```

**Use DQL or the ORM** where the operation is over entities rather than rows. Doctrine parameterises
throughout, so the hazard does not arise.

## Identifiers cannot be parameterised

A placeholder binds a **value**. Table names, column names and sort directions cannot be bound, and
that is the one case where the rule is pointing at something a placeholder will not fix.

Resolve the identifier against a fixed allow-list rather than interpolating it:

```php
$sortable = ['created_at' => 'created_at', 'total' => 'total_amount'];
$column   = $sortable[$requestedSort] ?? throw new \InvalidArgumentException('unsortable column');

$qb->orderBy($column, 'ASC' === $direction ? 'ASC' : 'DESC');
```

The value reaching the query comes from the map, not from the request. The request only chooses
which entry. That is the whole technique, and it holds for any identifier a user can influence.

## If you believe an instance is legitimate

Put it in `ignoreErrors` in the project's `phpstan.neon` with a path and this identifier, where it
is visible and can be reviewed. Inline `@phpstan-ignore` is banned by `phpqaci.inlinePhpstanIgnore`.

Before doing that, check the allow-list technique above, which resolves most of the cases that
appear irreducible at first look.
