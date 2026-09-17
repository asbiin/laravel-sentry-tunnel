# Phase 0 Research: Fix Tunnel Security Issues

**Feature**: [spec.md](./spec.md) | **Date**: 2026-09-17

All findings below were verified either against the vendored framework source in this repository or
against upstream documentation and source. Verified facts are marked **[verified]**; the one
remaining judgement call is marked **[judgement]**.

---

## R1 — Closing the outbound query-string injection (FR-001, FR-002)

**Decision**: build the query with `PendingRequest::withQueryParameters(['sentry_key' => $user])`
instead of interpolating `$user` into the URL string.

**Rationale**: verified by spike. Given a key of `legit&sentry_client=pwn`, the current code produces
`?sentry_key=legit&sentry_client=pwn` (two parameters, caller-controlled). `withQueryParameters`
produces `?sentry_key=legit%26sentry_client%3Dpwn` — one parameter, content preserved intact, which
is exactly what FR-002 requires. `urlencode($user)` in the interpolated string yields byte-identical
output; `withQueryParameters` is preferred because it removes the interpolation entirely, so no
future edit can reintroduce the class of bug. **[verified]**

**Availability**: `withQueryParameters` exists at `PendingRequest.php:358` in the vendored Laravel
12.1.0, and was added in Laravel 10.16, so it is safe across the declared `^11.0 || ^12.0 || ^13.0`
support matrix. **[verified]**

**Alternatives considered**:
- *Reject keys that are not alphanumeric*: also closes the hole, but risks refusing a key format
  Sentry issues that this project has not seen. Rejected in the spec's Assumptions; an empty key is
  still refused per FR-003.
- *`urlencode()` in place*: equivalent output, keeps the fragile interpolation pattern.

---

## R2 — Why malformed input currently produces 500s (FR-005 – FR-010)

**Decision**: validate before parsing, and narrow each parse to a typed, guarded call.

**Rationale**: three distinct causes were reproduced, each needing a different guard. **[verified]**

| Input | Current cause | Status today | Guard |
|---|---|---|---|
| Empty body | `Safe\json_decode('')` throws `JsonException` | 500 | Refuse empty/blank body before decoding |
| `dsn` is an array | `Safe\parse_url(array)` throws `TypeError` | 500 | Assert the value is a string before parsing |
| `dsn` is `http:///` | `Safe\parse_url` throws `UrlException` | 500 | Catch the Safe exception and refuse |
| DSN with no path | `Safe\parse_url` returns `NULL`, then `trim(NULL, '/')` | 422 + deprecation | Cast to string before trimming |

**The deprecation is real and invisible to the current tooling** — this is the most important finding
in this section:

- `Safe\parse_url($dsn, PHP_URL_PATH)` returns `NULL` for a DSN with no path. **[verified]**
- `trim(NULL, '/')` emits `trim(): Passing null to parameter #1 ($string) of type string is
  deprecated` on PHP 8.4, and becomes a `TypeError` in PHP 9. **[verified]**
- PHPStan at level 5 does not catch it because the Safe stub is declared
  `function parse_url(string $url, int $component = -1)` with **no return type**, so the analyser
  infers `mixed` and raises nothing. **[verified]**
- The existing test `it_fails_if_no_project` walks this exact path and passes, because Laravel's
  `HandleExceptions` bootstrapper routes deprecations to the deprecation log channel before PHPUnit's
  error handler sees them. Running the suite with `--fail-on-deprecation` does not surface it either.
  **[verified]**

**Consequence for the task list**: a test asserting "no deprecation" cannot rely on PHPUnit's
`--fail-on-deprecation`. It must install its own error handler around the request, or assert on the
deprecation log channel.

---

## R3 — Relaying the upstream response faithfully (FR-016, FR-018, FR-019, FR-019a)

**Decision**: stop returning `Illuminate\Http\Client\Response` from the controller. Build an
`Illuminate\Http\Response` explicitly from the upstream status, an allowlist of upstream headers, and
a body chosen per path.

**Rationale**: the controller currently returns the HTTP client's own response object, which is not a
`Responsable` and not a PSR response, so `Router::toResponse` falls through to its final branch and
does `new Response($response, 200, ['Content-Type' => 'text/html'])`
(`Routing/Router.php:901-922`). The object is stringified into the body; the status and every
upstream header are discarded. Reproduced: an upstream `202` with
`Content-Type: application/json` and `X-Sentry-Rate-Limits` reached the caller as `200`,
`text/html`, no rate-limit header. **[verified]**

**Spike result** — the replacement works and satisfies FR-019a in the same step: **[verified]**

```
status=202  ct=application/json  ra=60  rl=60::organization  leaked=NULL  body={"id":"abc"}
```

An `X-Internal-Secret` header on the upstream response was *not* relayed, because the allowlist
governs. `$response->toPsrResponse()` was rejected as an alternative precisely because it would
relay every upstream header, violating FR-019a.

**Header allowlist**: `Content-Type`, `Retry-After`, `X-Sentry-Rate-Limits`.

**Why these three, and why this matters** — confirmed against Sentry's SDK specification:
- Sentry emits `429` with `Retry-After`, plus `X-Sentry-Rate-Limits` carrying per-category limits.
- SDKs MUST honour `429` and stop sending until `Retry-After` elapses.
- On a `429` with no headers, SDKs assume a 60-second limit for all categories.

The current behaviour is worse than the no-header fallback: because `->throw()` converts the
upstream `429` into an application `500`, the SDK never sees a `429` at all, so it does not even get
the 60-second default. It keeps sending at full rate. **[verified by reproduction + upstream spec]**

**Body handling** differs by path, per FR-016: relay the upstream body on success (it carries only
the caller's own event id); replace it with a body determined by this package on error (upstream
error text must not reach the caller).

---

## R4 — Upstream errors must be logged, not thrown (FR-017, FR-017a, FR-017b)

**Decision**: drop `->throw()`. Branch on `$response->failed()`, log, and relay the status.

**Rationale**: `->throw()` raises `RequestException`, whose message embeds a truncated copy of the
upstream response body. That exception is unhandled, so it becomes a 500, is written to the log with
the upstream body inside it, and is rendered to the caller when `APP_DEBUG=true`. It also means a
host application running the Sentry Laravel SDK reports the 500 to Sentry — the amplification loop
FR-017 closes.

**Log level default**: `warning`. The Sentry Laravel SDK captures unhandled exceptions as events and
treats ordinary log records at `warning` as breadcrumbs rather than standalone events, so a warning
records the failure without manufacturing a new report. Raising the level to `error` is the
operator's decision and FR-017b requires that trade-off to be stated in the key's documentation.

**Timeout failures** are a different exception type: `ConnectionException`, not `RequestException`.
Verified catchable in the spike. It must be handled separately from a failed HTTP status, because
there is no upstream status to relay (see R5).

---

## R5 — Outbound timeouts (FR-022a – FR-022d)

**Decision**: `->connectTimeout(2)->timeout(5)` as shipped defaults, both operator-configurable.
Catch `ConnectionException` and answer `504 Gateway Timeout`.

**Rationale**: the vendored `PendingRequest` defaults are `'connect_timeout' => 10` and
`'timeout' => 30` (`PendingRequest.php:233,236`). **[verified]** Thirty seconds of worker occupancy
per request is the cheapest denial of service the endpoint offers, and — as FR-022d notes — the
volume ceiling does not mitigate it, because the ceiling bounds admissions, not holding time.

Two seconds to connect and five seconds overall is generous for a healthy Sentry ingest endpoint
while bounding a wholly unreachable upstream to five seconds per worker. Both are configurable per
FR-022b for operators behind slow egress proxies.

**Alternatives considered**:
- *Keep the framework defaults and only handle the failure*: leaves the worker-exhaustion vector open.
- *Queue the relay asynchronously*: rejected at clarification time — it adds a runtime queue
  dependency and changes the response contract wholesale, against constitution principle II.

---

## R6 — Volume ceiling (FR-020, FR-022)

**Decision**: add `'throttle:300,1'` to the shipped default middleware list, after `auth`.

**Rationale**: Laravel's `ThrottleRequests` keys by authenticated user when one is present, which is
the correct granularity here — the default stack already requires `auth`, so the limit is per
account rather than per IP. Ordering after `auth` matters: it means an unauthenticated probe is
rejected by `auth` without consuming a rate-limit slot.

The value 300/minute is deliberately generous per the clarification decision. A browser sending more
than five error reports per second is already pathological, so no legitimate installation should
notice the ceiling, while a looping or compromised account is bounded.

**Operator override** is already possible today by replacing `sentry-tunnel.middleware` wholesale
(FR-022 satisfied by the existing key). No new key is required for the rate ceiling.

---

## R7 — Report size ceiling (FR-021, FR-021a) — **the one judgement call**

**Decision**: default the ceiling to **20 MiB**, enforced from `Content-Length` before the body is
read, with a second guard on the actual body length. New configuration key, operator-overridable and
disableable per FR-022.

**Upstream limits, from Relay's own source** (`relay-config/src/config.rs`, `Limits::default`):
**[verified]**

| Relay limit | Default |
|---|---|
| `max_event_size` | 1 MiB |
| `max_attachment_size` | 200 MiB |
| `max_attachments_size` | 200 MiB |
| `max_envelope_size` | **200 MiB** |
| `max_api_payload_size` | 20 MiB |

Sentry's published data-management limits add: events over 200 KB compressed or 1 MB decompressed are
rejected; minidumps over 40 MB compressed or 200 MB decompressed are rejected; anything over a limit
is dropped with `413 Payload Too Large`. Relay's operating guidelines recommend configuring reverse
proxies in front of Relay for a 200 MB client body size. **[verified]**

**The tension, stated plainly**: FR-021a says the ceiling "MUST sit above the largest report the
Sentry client libraries produce in normal operation ... so that the ceiling never rejects a report
the upstream would itself have accepted." Read literally against the table above, that means
**200 MiB**, which would make FR-021 useless as a memory bound — the two requirements pull in
opposite directions at their literal extremes.

**Resolution [judgement]**: 200 MiB is driven by native crash minidumps and large attachments. The
`tunnel` option is a browser-SDK feature; native SDKs do not route through it. Bounding by what
browser SDKs actually emit — errors (≤1 MiB event ceiling), session replay segments, and profiles —
20 MiB sits far above real browser traffic while remaining a genuine per-request memory bound. It
also coincides with Relay's own `max_api_payload_size`.

This is the one place in the plan where a requirement is satisfied for the browser-tunnel use case
rather than literally for every Sentry client. It is recorded in the plan's Complexity Tracking, and
an operator who deliberately routes native attachments through the tunnel must raise or disable the
ceiling via the new key.

**Enforcement point**: check `Content-Length` before touching the body. PHP's `post_max_size` is not
a substitute — it is not applied to a body whose content type is not a form type, which is exactly
the case here (`application/x-sentry-envelope`).

---

## R8 — Allowlist parsing (FR-012 – FR-015)

**Decision**: trim each entry, drop entries empty after trimming, and compare hosts case-insensitively.

**Rationale**: reproduced — an allowlist of `'a.sentry.test, account.sentry.test'` refuses
`account.sentry.test` with 401, because `explode(',', …)` leaves the leading space and the comparison
is `in_array(…, strict: true)`. **[verified]** Host names are case-insensitive by definition, while
`parse_url` preserves the case it was given, so `SENTRY.IO` is refused against an allowlist of
`sentry.io` today. **[verified]**

Both failures are fail-closed, so neither is exploitable — they are configuration footguns that push
operators toward emptying the allowlist, which is the genuinely dangerous state. The fail-closed
behaviour on an empty allowlist (FR-015) is preserved unchanged: `array_filter` already drops the
empty string that `explode` produces from an unset variable, and an empty allowlist matches nothing.

**Note on project ids**: the project allowlist needs no trimming fix, because `intval(' 456')`
already handles surrounding whitespace. The trim is applied anyway for symmetry and to keep the two
code paths reading alike.

---

## Summary of decisions

| # | Decision | Requirements | Confidence |
|---|---|---|---|
| R1 | `withQueryParameters` for the key | FR-001, FR-002 | Verified by spike |
| R2 | Guard each parse; cast before `trim` | FR-005 – FR-010 | Verified by reproduction |
| R3 | Build the response explicitly, 3-header allowlist | FR-016, FR-018, FR-019, FR-019a | Verified by spike |
| R4 | Replace `->throw()` with branch + log at `warning` | FR-017, FR-017a, FR-017b | Verified |
| R5 | `connectTimeout(2)`, `timeout(5)`, catch `ConnectionException` → 504 | FR-022a – FR-022d | Verified |
| R6 | `throttle:300,1` after `auth` in the default stack | FR-020, FR-022 | Verified |
| R7 | 20 MiB size ceiling from `Content-Length` | FR-021, FR-021a | **Judgement — see tension above** |
| R8 | Trim entries, case-insensitive host compare | FR-012 – FR-015 | Verified by reproduction |

No `NEEDS CLARIFICATION` items remain.

## Sources

- [Sentry — Size Limits](https://docs.sentry.io/concepts/data-management/size-limits)
- [Relay — `relay-config/src/config.rs`](https://github.com/getsentry/relay/blob/master/relay-config/src/config.rs)
- [Relay — Operating Guidelines](https://docs.sentry.io/product/relay/operating-guidelines/)
- [Sentry SDK spec — Rate Limiting](https://github.com/getsentry/develop/blob/master/src/docs/sdk/rate-limiting.mdx)
- [Sentry — Transport](https://develop.sentry.dev/sdk/foundations/transport/)
- [Sentry — Using the tunnel option](https://docs.sentry.io/platforms/javascript/troubleshooting/#using-the-tunnel-option)
