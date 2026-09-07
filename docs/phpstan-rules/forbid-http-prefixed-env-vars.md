# `phpqaci.httpPrefixedEnvVars` — no Symfony env var named `HTTP_*`

**Rule**: `ForbidHttpPrefixedEnvVarsRule`
**Bundle**: [`rules-default.neon`](../../rules-default.neon) (always on, Symfony projects only)

## What fires

Any env var consumed by Symfony whose name begins with `HTTP_`, found in `config/` YAML or in a
`.env` file at the project root.

```yaml
# config/services.yaml
parameters:
    api_key: '%env(HTTP_API_KEY)%'
```

The rule skips itself entirely on a project with neither `symfony/dependency-injection` nor
`symfony/dotenv` installed, so it costs a non-Symfony project nothing and cannot be forgotten on a
project that later adds Symfony.

## Why this is a hazard

Symfony deliberately refuses to read an `HTTP_`-prefixed name from `$_SERVER`, because in a web
request `$_SERVER['HTTP_*']` holds **client-supplied request headers**. Treating those as
configuration would let a caller set your config by sending a header, so the carve-out is correct
and it is not going to change.

`EnvVarProcessor::getEnv()` resolves a reference as, in effect:

```php
$_ENV[$name] ?? (str_starts_with($name, 'HTTP_') ? null : ($_SERVER[$name] ?? null))
```

`Dotenv::populate()` applies the same carve-out.

The consequence lands on **CLI processes**. PHP's default `variables_order` has no `E`, so the OS
environment appears in `$_SERVER` and not in `$_ENV`. A worker, console command or cron job
therefore resolves an `HTTP_`-prefixed var to empty no matter how correctly your deploy config,
compose file or systemd unit sets it. The web request works, because there the value arrives another
way, so the failure is invisible in exactly the environment people test in.

There is no exception, no log line and no warning. The variable resolves to the committed default,
which is usually an empty string, and the first symptom is an integration failing in production
against what looks like correct configuration.

## The correct construction

**Rename the variable off the prefix.** That is the entire fix:

```diff
-HTTP_API_KEY=secret
+APP_API_KEY=secret
```

```diff
-    api_key: '%env(HTTP_API_KEY)%'
+    api_key: '%env(APP_API_KEY)%'
```

Rename it everywhere it is set as well as everywhere it is read: `.env` files, deployment
configuration, CI secrets, container definitions and any orchestration manifests. The rule finds the
declarations in this repository; it cannot see your deployment environment.

`APP_` is the Symfony convention. Any prefix other than `HTTP_` works.

## Why this is a rule and not a note in the README

The pattern is undetectable by reading and produces no error, so nothing brings it to anyone's
attention. It is also the kind of name that looks deliberate: `HTTP_API_KEY` reads like a
well-organised variable for an HTTP API's key, which is precisely why it gets written.

The rule reaches YAML and `.env` files itself, with plain file reads rather than by shelling out,
because PHPStan only ever parses PHP and the violation does not live in PHP. It binds to `FileNode`,
which fires at least once for any project with any analysed PHP file, so the check cannot silently
go quiet the way a rule bound to a rarer node type could.

## If you believe an instance is legitimate

There is no legitimate case. An `HTTP_`-prefixed Symfony env var does not work in CLI, and that is a
property of the framework rather than a policy choice this rule is making.

If you are genuinely reading a request header, read it from the Request object, which is where
request headers belong:

```php
$value = $request->headers->get('X-Api-Key');
```
