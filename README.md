# apigopro/slim-cors-middleware

A PSR-15 CORS middleware for **Slim Framework 4**, requiring **PHP 8.5**.

Spiritual successor to [`tuupola/cors-middleware`](https://github.com/tuupola/cors-middleware)
(unmaintained) — same array-based options and behavior, rebuilt as a small, dependency-free PSR-15
middleware with no legacy baggage.

## Install

Not published on Packagist — install as a VCS repository pointing at wherever you host it:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/your-org/cors-middleware.git"
        }
    ],
    "require": {
        "apigopro/slim-cors-middleware": "^1.0",
        "slim/psr7": "^1.7"
    }
}
```

```bash
composer update apigopro/slim-cors-middleware
```

## Basic usage

```php
use SlimCors\CorsMiddleware;

$app->add(new CorsMiddleware([
    'origin'         => ['https://app.example.com', 'https://*.example.com'],
    'methods'        => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'headers.allow'  => ['Authorization', 'Content-Type'],
    'headers.expose' => ['X-Request-Id'],
    'credentials'    => true,
    'cache'          => 86400,
]));
```

Add this **before** (outer to) your auth middleware, so preflight `OPTIONS` requests get answered
without ever reaching auth checks or route handlers — browsers send preflight requests without
credentials or custom auth headers, so they'd otherwise fail auth for no reason.

```php
$app->add(new CorsMiddleware([/* ... */]));   // outermost
$app->add(new JwtAuthMiddleware([/* ... */])); // runs after CORS
```

## How it works

- **No `Origin` header** → not a cross-origin request, passed through untouched.
- **`Origin` present, not preflight** → the wrapped handler runs as normal, then
  `Access-Control-Allow-Origin` (and friends) get added to the response.
- **Preflight** (`OPTIONS` + `Access-Control-Request-Method` header present) → answered directly
  with a `204`, without calling the rest of the middleware stack or your route handler at all.
- **Preflight requesting a method not in `methods`** → rejected with `405` and an `Allow` header
  listing what *is* allowed (or your custom `error` response).
- **Origin not in the allow-list** → rejected with `401` (or your custom `error` response).

## Options

| Option              | Default                                      | Notes |
|----------------------|------------------------------------------------|-------|
| `origin`             | `['*']`                                          | Allowed origins. Exact strings, or patterns with a `*` wildcard, e.g. `'https://*.example.com'`. |
| `methods`            | `['GET', 'POST', 'PUT', 'PATCH', 'DELETE']`        | Sent as `Access-Control-Allow-Methods` on preflight responses. A preflight requesting a method outside this list gets rejected with `405` instead. Use `['*']` to allow any method. |
| `headers.allow`      | `[]`                                              | Sent as `Access-Control-Allow-Headers` on preflight responses. If empty, whatever the browser asked for via `Access-Control-Request-Headers` is reflected back. |
| `headers.expose`     | `[]`                                              | Sent as `Access-Control-Expose-Headers` on actual (non-preflight) responses. |
| `credentials`        | `false`                                           | Sends `Access-Control-Allow-Credentials: true` when enabled. Also makes a configured `origin => ['*']` reflect the actual request origin instead of a literal `*`, since browsers reject the literal wildcard combined with credentials. |
| `cache`              | `0`                                               | Seconds for `Access-Control-Max-Age` on preflight responses. `0` omits the header. |
| `error`              | `null`                                            | `function($request, $response, array $arguments): ?ResponseInterface`. Called when the origin isn't allowed (`$arguments` has `message`, `origin`) or when a preflight's requested method isn't allowed (`$arguments` has `message`, `method`, `allowed_methods`). Return a response to override the default `401`/`405`. |
| `response_factory`   | *(auto-detects `slim/psr7`)*                       | Any PSR-17 `ResponseFactoryInterface`. |

## Wildcard origin patterns

```php
new CorsMiddleware([
    'origin' => [
        'https://app.example.com',
        'https://*.staging.example.com', // any staging subdomain
    ],
]);
```

`*` matches any sequence of characters within a single pattern; it isn't a full glob/regex
language, just a simple prefix/suffix/subdomain wildcard.

## Migrating from tuupola/cors-middleware

- **Namespace**: `Tuupola\Middleware\CorsMiddleware` → `SlimCors\CorsMiddleware`.
- **`logger` option removed.** Wire up your own logging in the `error` callback if you need it.
- **`error` callback signature is unchanged**: `($request, $response, array $arguments)`.
- Everything else — `origin`, `methods`, `headers.allow`, `headers.expose`, `credentials`, `cache`
  — behaves the same way.

## Testing

```bash
composer install
composer test
```

Covers: pass-through for non-CORS requests, allowed/disallowed origins, wildcard origin patterns
(including the credentials + `*` interaction), preflight short-circuiting, method validation
(`405` for disallowed methods, wildcard `*` methods, case-insensitive matching), configured vs.
reflected `Access-Control-Allow-Headers`, `Access-Control-Expose-Headers`, and custom `error`
callbacks for both origin and method rejections.

## License

MIT.
