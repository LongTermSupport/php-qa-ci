# `phpqaci.headerInjection` — no raw `header()`, `setcookie()` or `setrawcookie()`

**Rule**: `ForbidHeaderInjectionRule`
**Bundle**: [`rules-optional-symfony.neon`](../../rules-optional-symfony.neon) (opt in)

## What fires

A call to `header()`, `setcookie()` or `setrawcookie()`.

```php
header('Location: ' . $request->get('next'));
setcookie('session', $token);
```

## Why this is a hazard

Two distinct problems, which is why the whole family is banned rather than only the interpolating
case.

**HTTP response splitting** (OWASP A03). A header value containing a newline ends the header and
begins another, or ends the header block and begins a response body. An interpolated redirect target
is the classic route in, and the payload is smaller than most people expect.

**Bypassing the response layer.** These functions write to PHP's output directly, so Symfony does
not know they happened. The framework's own response is still constructed and sent, headers can be
set twice with different values, and the kernel's response event never sees the ones written behind
its back. Behaviour then depends on ordering that no part of the code states, and it changes when
something unrelated moves.

The second problem is the one that bites in practice. It has nothing to do with untrusted input, so
it affects the calls that look completely safe.

## The correct construction

**Set headers on the Response:**

```php
$response = new Response($content);
$response->headers->set('X-Frame-Options', 'DENY');

return $response;
```

**Redirect with the framework's response**, and validate the target:

```php
use Symfony\Component\HttpFoundation\RedirectResponse;

$next = $request->query->get('next');
if (!\is_string($next) || !$this->isAllowedRedirect($next)) {
    $next = '/';
}

return new RedirectResponse($next);
```

The validation is not optional. An open redirect is a real vulnerability in its own right, and
`RedirectResponse` does not prevent one, it only prevents the header being split whilst you create
it.

**Set cookies through the Response's cookie bag:**

```php
use Symfony\Component\HttpFoundation\Cookie;

$response->headers->setCookie(
    Cookie::create('session', $token)
        ->withHttpOnly(true)
        ->withSecure(true)
        ->withSameSite(Cookie::SAMESITE_LAX),
);
```

`Cookie::create()` is worth the change on its own: the security attributes are named methods rather
than positional arguments, so `httponly` cannot be silently omitted the way it can in a
`setcookie()` call with seven arguments.

## Streamed and non-Symfony responses

For a streamed download, use `StreamedResponse` with `BinaryFileResponse` or a callback, and set the
headers on the response object as above. Reaching for `header()` to send `Content-Disposition` is
the most common legitimate-looking case, and it is fully covered by the response layer.

## If you believe an instance is legitimate

A front controller or a bootstrap running before the kernel exists is the one place with a genuine
argument, since there is no Response object yet.

Put it in `ignoreErrors` in the project's `phpstan.neon` with a path and this identifier, scoped to
that file. Inline `@phpstan-ignore` is banned by `phpqaci.inlinePhpstanIgnore`.
