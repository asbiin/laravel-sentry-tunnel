# Contract: Tunnel HTTP Endpoint

**Feature**: [../spec.md](../spec.md) | **Date**: 2026-09-17

This is the package's externally observable interface. Changes here are breaking changes under
constitution principle V; this feature makes several, and is released as a MAJOR version.

---

## Request

```
POST {sentry-tunnel.tunnel-url}          default: /sentry/tunnel
Content-Type: <any; not inspected>
Body: a Sentry envelope — a JSON header line, "\n", then opaque remainder
```

**Middleware** (shipped default): `web` → `auth` → `throttle:300,1`

`throttle` is placed after `auth` so an unauthenticated probe is refused without consuming a
rate-limit slot.

---

## Responses

### Success — upstream accepted the envelope

| | Before this feature | After |
|---|---|---|
| Status | Always `200` | **The upstream's own status** (e.g. `200`, `202`) |
| `Content-Type` | Always `text/html; charset=UTF-8` | **The upstream's own** |
| `Retry-After` | Dropped | **Relayed when present** |
| `X-Sentry-Rate-Limits` | Dropped | **Relayed when present** |
| Other upstream headers | Dropped | Dropped (allowlist, FR-019a) |
| Body | Upstream body | Upstream body (unchanged) |

### Upstream error — Sentry answered with a failure status

| | Before | After |
|---|---|---|
| Status | Always `500` | **The upstream's own status** (e.g. `429`, `400`, `503`) |
| `Retry-After`, `X-Sentry-Rate-Limits` | Dropped | **Relayed when present** |
| `Content-Type` | Always `text/html; charset=UTF-8` | **Not relayed** — see the deviation note below |
| Body | Laravel's error page; upstream response text embedded in the logged exception, and rendered to the caller when `APP_DEBUG=true` | **Determined by this package.** Upstream response text never disclosed (FR-016) |

> **Deviation from FR-019, recorded during implementation.** FR-019 reads "relay the upstream's own
> status and content type ... on both the success and the error path". The content type is relayed
> on the success path only. On the error path the body is this package's, per FR-016, so relaying
> Sentry's `Content-Type` would describe a body that is not there — a response claiming
> `application/json` while carrying this package's text. The status and the two rate-limit headers,
> which are what the Sentry SDK actually reads on a failure, are relayed as specified. Amending
> FR-019 to say "status on both paths, content type alongside the upstream body" would make the
> spec and the code agree; that wording change has not been made.
| Application log | Unhandled `RequestException` at error level | Structured entry at the configured level, default `warning` (FR-017a, FR-017b) |
| Reported to Sentry by the host app | Yes — the amplification loop | **No** (FR-017) |

**This is the change that matters most to callers.** A `429` from Sentry now reaches the browser as a
`429` carrying `Retry-After`, so the Sentry SDK applies its own backoff as its specification
requires. Previously it saw a `500`, which does not trigger backoff at all — not even the
60-second default that the SDK spec prescribes for a `429` with no headers.

### Unreachable — connection refused or timeout expired

| | Before | After |
|---|---|---|
| Status | `500`, after up to 30 s | **`504`**, after at most `connect-timeout` + `timeout` (default 2 s + 5 s) |
| Body | Laravel's error page | Determined by this package |
| Application log | Unhandled `ConnectionException` | Structured entry at the configured level (FR-017a) |

### Refusals — the request never reaches the upstream

| Condition | Status | Requirement |
|---|---|---|
| Body exceeds the size ceiling | `413` | FR-021 |
| Body empty or blank | `422` | FR-005 |
| Envelope header does not parse | `422` | FR-006 |
| `dsn` absent | `422` | existing |
| `dsn` not a string | `422` | FR-007 |
| `dsn` not a parseable address | `422` | FR-008 |
| Key absent or empty | `401` | FR-003 |
| Host absent | `401` | existing |
| Host not allowlisted | `401` | FR-012 – FR-015 |
| Project absent or zero | `422` | existing |
| Project not allowlisted | `401` | existing |
| Rate ceiling exceeded | `429` | FR-020 |

**Guarantees over the whole refusal set:**

- No caller-supplied input produces a `500` (FR-009). Three inputs do today: an empty body, a
  non-string `dsn`, and an unparseable address.
- No refusal discloses the configured allowlists (FR-011).
- No refusal makes an outbound request.

---

## Outbound request to Sentry

```
POST https://{allowlisted-host}/api/{allowlisted-project}/envelope/?sentry_key={key}
Content-Type: application/x-sentry-envelope
Body: the caller's envelope, byte-for-byte
Connect timeout: 2 s (configurable)   Overall timeout: 5 s (configurable)
```

**The one guarantee this contract exists to state**: `{key}` is transmitted as a single
percent-encoded query parameter value. No caller-supplied character can introduce another parameter,
alter this one, or change the host, scheme or path (FR-001, FR-004).

| Key the caller supplies | Outbound query — before | Outbound query — after |
|---|---|---|
| `legit` | `?sentry_key=legit` | `?sentry_key=legit` |
| `legit&sentry_client=pwn&x=1` | `?sentry_key=legit&sentry_client=pwn&x=1` | `?sentry_key=legit%26sentry_client%3Dpwn%26x%3D1` |

Both rows are verified; see [research.md](../research.md) R1.

---

## Compatibility notes for the release

Operators upgrading should be told, in the release notes:

1. The endpoint no longer always answers `200`. Anything asserting on a `200` from the tunnel —
   an uptime check, a synthetic monitor — must be updated to accept the upstream's status.
2. The response `Content-Type` is now the upstream's, not `text/html`.
3. Upstream failures no longer surface as application errors, so they stop appearing in the host
   application's error reporting. They appear in the log at `warning` instead.
4. The default middleware stack gained `throttle:300,1`. Operators who set
   `sentry-tunnel.middleware` explicitly are unaffected; operators on the default who legitimately
   exceed 300 reports per minute per user must raise it.
5. A new size ceiling refuses bodies over 20 MiB with `413`.
