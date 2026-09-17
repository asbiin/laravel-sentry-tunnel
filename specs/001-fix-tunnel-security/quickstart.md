# Quickstart: Validating the Tunnel Security Fixes

**Feature**: [spec.md](./spec.md) | **Contracts**: [http-endpoint.md](./contracts/http-endpoint.md),
[configuration.md](./contracts/configuration.md) | **Date**: 2026-09-17

How to prove this feature works. Every scenario below was executed against the **current** code
during the security review, so each has a known before-state — that is what makes them useful as
acceptance checks rather than as tests that pass vacuously.

---

## Prerequisites

```bash
composer install
```

No network access is needed. Every outbound call is faked with `Http::fake()`, as constitution
principle III requires.

---

## Running the suite

```bash
vendor/bin/phpunit
```

Baseline before any change: **10 tests, 33 assertions, green**. Keeping those green is SC-010,
except where a test asserts behaviour this feature deliberately changes — `it_rethrow_an_error`
asserts a `500` on upstream failure and must be rewritten to assert the relayed upstream status.

---

## Scenario 1 — The outbound query-string injection is closed (US1)

**Before**: a key of `legit&sentry_client=pwn&extra=1` produced
`?sentry_key=legit&sentry_client=pwn&extra=1` — three caller-controlled parameters.

**Validate**: tunnel a report whose DSN is
`https://legit&sentry_client=pwn&extra=1@account.sentry.test/123`, then assert with
`Http::assertSent()` that the outbound URL is
`…/envelope/?sentry_key=legit%26sentry_client%3Dpwn%26extra%3D1` — one parameter, percent-encoded.

**Also assert** the no-regression case: an ordinary key still produces
`?sentry_key=user`, byte-identical to today (US1 scenario 3).

---

## Scenario 2 — Malformed input is refused, not crashed on (US2)

**Before**: three of these five returned `500`.

| Input | Expected after |
|---|---|
| Empty body | `422` |
| Envelope header that is not valid JSON | `422` |
| `dsn` is an array | `422` |
| `dsn` is `"http:///"` | `422` |
| DSN with no path (`https://user@account.sentry.test`) | `422`, **and no deprecation** |

**Assert for every row**: the status, and `Http::assertNothingSent()`.

**The deprecation check needs care.** `--fail-on-deprecation` does *not* catch it: Laravel's
`HandleExceptions` routes PHP deprecations to the deprecation log channel before PHPUnit's error
handler sees them. The test must install its own error handler around the request, or assert on that
log channel. See [research.md](./research.md) R2.

Confirm the deprecation exists today before fixing it:

```bash
php -r 'require "vendor/autoload.php";
set_error_handler(fn($n,$s) => $n === E_DEPRECATED ? print("DEPRECATED: $s\n") : null);
trim(Safe\parse_url("https://user@account.sentry.test", PHP_URL_PATH), "/");'
```

Prints `DEPRECATED: trim(): Passing null to parameter #1 ($string) of type string is deprecated`
before the fix; silent after.

---

## Scenario 3 — Allowlists behave as documented (US3)

**Before**: an allowlist of `'a.sentry.test, account.sentry.test'` refused `account.sentry.test`
with `401`, because of the leading space on the second entry.

**Validate**:

| Allowlist | Incoming host | Expected |
|---|---|---|
| `a.sentry.test, account.sentry.test` | `account.sentry.test` | accepted |
| `account.sentry.test` | `ACCOUNT.SENTRY.TEST` | accepted |
| empty / unset | anything | `401` — fail-closed preserved |
| `account.sentry.test` | `evil.test` | `401` |

The third row is the one to guard hardest: it is the behaviour the other three must not weaken.

---

## Scenario 4 — Upstream failures relayed honestly (US4)

**Before**: a `429` from Sentry carrying `Retry-After: 60` reached the caller as `500` with no
`Retry-After`.

**Validate** by faking each upstream outcome:

| Upstream | Caller receives | Headers | Log | Reported to Sentry |
|---|---|---|---|---|
| `202` + `application/json` + `X-Sentry-Rate-Limits` | `202`, `application/json` | rate-limit header relayed | none | no |
| `429` + `Retry-After: 60` | `429` | `Retry-After: 60` relayed | one entry at `warning` | **no** |
| `400` + body `"bad envelope"` | `400` | — | one entry at `warning` | **no** |
| unreachable / timeout | `504` | — | one entry at `warning` | **no** |

**Assert on every failing row** that the upstream response text does **not** appear in the caller's
body, and that no unhandled application failure was recorded. Add a header the allowlist does not
cover (e.g. `X-Internal-Secret`) to the fake and assert it is **not** relayed — that is FR-019a.

**Assert on the success row** that the upstream body *is* relayed: it carries only the caller's own
event id, which FR-016 permits.

Run one case with `APP_DEBUG=true` to confirm SC-006 holds in debug mode too.

---

## Scenario 5 — Ceilings and timeouts (US5)

| Check | Expected |
|---|---|
| 301 reports in one minute from one account | the 301st is refused `429` |
| `Content-Length` above 20 MiB | `413`, `Http::assertNothingSent()` |
| `max-payload-size` set to `null` | a large body is accepted |
| Operator overrides `sentry-tunnel.middleware` | their list governs; the default throttle is not reimposed |
| Unreachable upstream | `504` within `connect-timeout` + `timeout`, not 30 s |

For the timeout check, assert on the configured values rather than on wall-clock duration — a
timing-based assertion is flaky in CI.

---

## Gates before merge

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/psalm --no-progress
vendor/bin/pint --test
```

All four must pass. Constitution principle IV sets PHPStan level 5 and Psalm `errorLevel="7"` as
floors that may be raised but never lowered, and forbids introducing a suppression to make a change
pass unless the rule is provably wrong about the code.

Note that PHPStan at level 5 did **not** catch the `trim(null)` defect, because the
`thecodingmachine/safe` stub declares `parse_url` with no return type. A green static-analysis run is
therefore not by itself evidence that Scenario 2 is fixed — the runtime check above is.

---

## Manual end-to-end check (optional)

In a host application with the package installed and a browser SDK configured with
`tunnel: '/sentry/tunnel'`:

1. Trigger a browser error; confirm it arrives in Sentry and the tunnel answered with Sentry's own
   status rather than a substituted `200`.
2. Point `allowed-hosts` at a host that refuses the connection; confirm the browser receives `504`
   within a few seconds, the application log carries one `warning`, and the application's own Sentry
   reporting records nothing.
