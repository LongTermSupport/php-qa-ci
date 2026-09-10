# Insecure functions are banned

**Identifier**: `phpqaci.insecureFunction` — opt-in, see [Optional Rules](../tools/phpstan.md#optional-rules)

## What it requires

Three families of function, none of which is dangerous in itself, but each of which is the wrong
tool the moment security depends on it:

| Family | Functions | Why |
| --- | --- | --- |
| Broken hashes | `md5`, `sha1`, `md5_file`, `sha1_file`, and `hash`/`hash_file`/`hash_init` called with a literal `md5` or `sha1` algorithm | Both are collision-broken. Neither is a password hash under any circumstances |
| Predictable randomness | `rand`, `mt_rand`, `lcg_value`, `uniqid` | Seeded and reproducible. Anything a token, id or nonce depends on is guessable |
| Unparameterised SQL | `mysql_query`, `mysql_unbuffered_query`, `mysqli_query`, `mysqli_multi_query`, `mysqli_real_query` | They take a finished SQL string, so the only way to include a value is to concatenate it |

## Why it is opt-in

`md5()` on a cache key is not a defect, and a project that hashes for identity rather than for
secrecy is doing nothing wrong. The rule cannot tell the two apart — it sees the call, not the
purpose — so it is offered rather than imposed. Turn it on in a codebase where the answer is
"we never hash for anything but security", which is most application code.

`hash('md5', ...)` with the algorithm in a **variable** is deliberately not reported. The value
is unknown at analysis time and guessing would report code that may well be correct.

## How to fix it

```php
// Hashing for security
$digest = hash('sha256', $data);
$stored = password_hash($plaintext, PASSWORD_DEFAULT);   // never a raw hash for passwords

// Randomness anything depends on
$token = bin2hex(random_bytes(32));
$index = random_int(0, $max);

// SQL
$statement = $pdo->prepare('SELECT * FROM users WHERE email = :email');
$statement->execute(['email' => $email]);
```

If a hash genuinely is not a security boundary — a cache key, an ETag, a shard selector — say so
where the reader can see it, and record the exception in `qaConfig/phpstan.neon` under
`ignoreErrors` with the justification the
[phpstanIgnoreJustification](../tools/phpstan.md#suppressing-errors) lane requires. Do not
disable the rule for the whole project because of one cache key.

## Provenance

The lists are lifted from
[spaze/phpstan-disallowed-calls](https://github.com/spaze/phpstan-disallowed-calls)
(MIT, Copyright (c) 2018 Michal Špaček), specifically its insecure-calls bundle. They are
carried here rather than the extension being imported, so that a single ruleset owns the
convention and two engines cannot drift out of step or contradict each other.

## Implementation

- Rule: [`ForbidInsecureFunctionsRule`](../../src/PHPStan/Rules/ForbidInsecureFunctionsRule.php)
- Related: [forbid-dangerous-functions.md](forbid-dangerous-functions.md) covers the functions
  that execute code rather than merely being weak, and is always on.
