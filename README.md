# Dynv PHP SDK

A dependency-free PHP 8.1+ client for the local agent started by `dynv run`. It reads resolved variables over loopback and does no cryptography. The process never needs a Dynv token.

```sh
composer require dynv/dynv
```

## Quick start

Start the application under Dynv:

```sh
dynv run -- php app.php
```

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Dynv\Dynv;

$env = Dynv::connect(require: true);
$proxy = $env->get('PROXY_SERVER');
$databaseUrl = $env->require('DATABASE_URL');
```

`connect()` reads `DYNV_AGENT_URL` and `DYNV_AGENT_KEY` by default and fetches `/v1/env` once. With `require: true`, a connection or response error throws `DynvException`. Otherwise the client records the error in `error()` and falls back to env mode. An invalid or non-loopback agent URL always throws.

Without an agent URL, the client is in env mode: `get()` reads `getenv()` and then `$_ENV`. Once Dynv manages a key, a missing or removed value never falls back to an inherited environment value.

## Rollover values

`all($key)` returns the current value followed by live fallback values, newest first (`[]` when unset). Use it to configure libraries that accept older secrets during rotation:

```php
$secrets = $env->all('AUTH_SECRET');
```

## Per-request PHP and long-running workers

For PHP-FPM, Apache, or `php -S`, connect on each request. This is one cheap loopback GET, so each request sees a fresh snapshot.

```php
$env = Dynv::connect(require: true);
```

An existing client can call `refresh()` to fetch `/v1/env` again. It returns `false` on failure and leaves the last snapshot readable.

CLI workers and daemons can block in `watch()` instead:

```php
use Dynv\ChangeEvent;
use Dynv\Dynv;

$env = Dynv::connect(require: true);

$env->on('PROXY_SERVER', function (?string $value): void {
    // Rebuild the proxy dispatcher. $value is null if the key was removed.
});

$stopping = false;
$env->watch(
    function (ChangeEvent $event): void {
        // $event->version, $event->changed, $event->restart
    },
    fn (): bool => $stopping,
);
```

`watch()` long-polls with a 40-second client timeout. A 204 response starts another poll. Request errors use backoff from 0.5 seconds up to 30 seconds. Return `true` from the optional stop callback to end the loop. Without an agent URL, `watch()` returns immediately.

## API

```php
Dynv::connect(
    ?string $agentUrl = null,
    ?string $agentKey = null,
    bool $require = false,
    float $timeout = 5.0,
): Dynv
```

- `get(string $key, ?string $default = null): ?string` — current value, with environment fallback only for unmanaged keys.
- `require(string $key): string` — current value or `DynvException("dynv: KEY is not set")`.
- `source(string $key): ?string` — shared group name for a current group-provided key.
- `isTemplate(string $key): bool` — whether the resolved value came from a template.
- `unresolved(): array` — template keys that currently cannot resolve.
- `version(): int` — current agent revision, or 0 in env mode.
- `mode(): string` — `"agent"` or `"env"`.
- `live(): bool` — whether values come from the agent.
- `all(?string $key = null): array` — with a key, the current value followed by live fallback values, newest first (`[]` when unset); without one, all current agent variables.
- `error(): ?string` — last connection or request error, without values or response bodies.
- `refresh(): bool` — fetch and apply the current snapshot without emitting events.
- `on(string $key, callable $fn): void` — call `$fn(?string $value)` from `watch()` when that key changes or is removed.
- `watch(callable $onChange, ?callable $stop = null): void` — blocking long-poll loop; `$onChange` receives a `ChangeEvent`.

`ChangeEvent` has public readonly properties `int $version`, `array $changed` (sorted key names), and `bool $restart`. A removed key uses its previous apply mode to determine `restart`.

Agent URLs must use HTTP or HTTPS on `localhost`, `127.0.0.0/8`, or `::1`. Redirects are refused. The client uses ext-curl when available and PHP HTTP stream contexts otherwise.

Direct mode is not provided in PHP. Use `dynv run`, or implement the protocol in `docs/PROTOCOL.md`.

## Tests

From the repository root:

```sh
php tests/run.php
```

Or run lint and tests together:

```sh
sh scripts/check.sh
```

The tests need only PHP. They start a fake loopback agent and cover reads, tombstones, environment fallback, authentication, redirect refusal, refresh, and watch events. `e2e/app.php` is a router for `php -S`; the built-in server makes `DYNV_AGENT_URL` available to it through `getenv()`.
