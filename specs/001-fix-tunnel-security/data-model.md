# Phase 1 Data Model: Fix Tunnel Security Issues

**Feature**: [spec.md](./spec.md) | **Research**: [research.md](./research.md) | **Date**: 2026-09-17

This package holds no persistent state. The "data model" here is the shape of the values that flow
through one request, and the validation rules each must satisfy before the next stage may run. Every
rule below traces to a requirement in the spec.

---

## Entity: Envelope

The caller-supplied request body. Attacker-controlled in full.

| Field | Type | Source | Notes |
|---|---|---|---|
| `raw` | string | Request body | Relayed to the upstream byte-for-byte, unmodified |
| `header` | string | Text before the first `\n` | Parsed as structured data; governs routing |
| `remainder` | string | Everything after the first `\n` | Never parsed, never inspected |

**Validation rules**

| Rule | Requirement | On failure |
|---|---|---|
| `Content-Length` must not exceed the configured size ceiling | FR-021 | 413, before the body is read |
| `raw` must not be empty or blank | FR-005 | 422 |
| `header` must parse as structured data | FR-006 | 422 |

**Invariant**: only `header` influences routing. `remainder` is opaque — the tunnel never inspects,
filters, or rewrites it. Multiple concatenated envelopes are relayed as one body under the first
header's routing (spec Edge Cases).

---

## Entity: Connection string (DSN)

Extracted from `Envelope.header` at key `dsn`. Attacker-controlled in full — none of its three
derived values may be trusted before validation.

| Derived value | Type | Extracted from | Used for |
|---|---|---|---|
| `key` | string | userinfo | The `sentry_key` query parameter |
| `host` | string | host | The outbound host |
| `projectId` | int | path | The outbound path segment |

**Validation rules, in order.** Each stage runs only if every prior stage passed; no outbound request
is made unless all pass.

| # | Rule | Requirement | On failure |
|---|---|---|---|
| 1 | `dsn` must be present | existing behaviour | 422 `no dsn` |
| 2 | `dsn` must be a string | FR-007 | 422 |
| 3 | `dsn` must parse as an address | FR-008 | 422 |
| 4 | `key` must be present and non-empty | FR-003 | 401 `no user` |
| 5 | `host` must be present | existing behaviour | 401 `no host` |
| 6 | `host` must match the host allowlist, case-insensitively | FR-013, FR-015 | 401 `invalid host` |
| 7 | `projectId` must be a non-zero integer | existing behaviour | 422 `no project` |
| 8 | `projectId` must match the project allowlist when that list is non-empty | existing behaviour | 401 `invalid project` |

**Transformation rules**

| Value | Rule | Requirement |
|---|---|---|
| `key` | Transmitted as a single opaque query parameter value, content preserved, never interpolated into the URL string | FR-001, FR-002 |
| `host` | Compared case-insensitively; the allowlisted spelling is used for the outbound request | FR-013 |
| `projectId` | Integer, so it cannot carry path or query syntax | FR-004 |

**Note on path parsing**: the path component is absent for a DSN such as `https://key@host`. The
parser returns null in that case and the value must be cast to a string before trimming (FR-010,
research R2).

---

## Entity: Allowlist

Two of them — hosts and projects — read from configuration.

| Field | Type | Empty means |
|---|---|---|
| `hosts` | list of strings | Nothing is permitted — every report is refused (FR-015) |
| `projects` | list of integers | Every project on an allowlisted host is permitted (existing, documented) |

**Parsing rules**

| Rule | Requirement |
|---|---|
| Split on `,` | existing |
| Trim whitespace around each entry | FR-012 |
| Discard entries empty after trimming | FR-014 |
| Compare hosts ignoring letter case | FR-013 |

**Invariant**: the asymmetry between the two empty cases is deliberate and pre-existing. An empty
host allowlist fails closed; an empty project allowlist permits all projects on an already-allowlisted
host. This feature does not change it.

---

## Entity: Upstream outcome

The result of the outbound request. Three mutually exclusive states, each with its own caller
response and its own logging obligation.

| State | Condition | Caller receives | Body | Logged |
|---|---|---|---|---|
| **Success** | Upstream answered with a non-failure status | Upstream status | Upstream body, relayed (FR-016) | No |
| **Upstream error** | Upstream answered with a failure status | Upstream status (FR-019) | Determined by this package (FR-016) | Yes (FR-017a) |
| **Unreachable** | Connection refused, or a timeout expired | 504 (FR-022c) | Determined by this package | Yes (FR-017a) |

**Header relay allowlist** — applies to the Success and Upstream-error states (FR-019a):

| Header | Why |
|---|---|
| `Content-Type` | The caller's SDK needs the response's type |
| `Retry-After` | The SDK's backoff clock on a 429 |
| `X-Sentry-Rate-Limits` | Per-category limits the SDK applies |

Every other upstream header is dropped. The list is an allowlist, not a denylist, so a header the
upstream adds in future is dropped by default rather than relayed by accident.

**Log record shape** (FR-017a) — the same shape for both failing states:

| Field | Included | Notes |
|---|---|---|
| Upstream status | Yes | Absent for the Unreachable state; the failure reason takes its place |
| Target host | Yes | |
| Target project | Yes | |
| Upstream response text | **Never** | FR-016, FR-017a |
| Envelope content | **Never** | May contain end-user data |

**Invariant**: neither failing state may surface as an unhandled application failure (FR-017,
FR-022c), because the host application would report it to the very upstream that is failing.

---

## Entity: Configuration

Existing keys, unchanged in shape:

| Key | Type | Default |
|---|---|---|
| `allowed-hosts` | list | Derived from `SENTRY_LARAVEL_DSN` |
| `allowed-projects` | list | Derived from `SENTRY_LARAVEL_DSN` |
| `domain` | string, nullable | `null` |
| `tunnel-url` | string | `/sentry/tunnel` |
| `middleware` | list | **Changes** — gains `throttle:300,1` (FR-020) |

New keys, each requiring a comment block in the config file and a README section (FR-024):

| Key | Type | Default | Requirement |
|---|---|---|---|
| `max-payload-size` | int (bytes), nullable | 20 MiB; `null` disables | FR-021, FR-022 |
| `timeout` | int/float (seconds) | 5 | FR-022a, FR-022b |
| `connect-timeout` | int/float (seconds) | 2 | FR-022a, FR-022b |
| `log-level` | string | `warning` | FR-017b |

**Constraint**: the shipped default for `log-level` must be a level the host application's own error
reporting does not capture as a new event (FR-017b). The documentation for the key must state that
raising it to `error` re-exposes the amplification loop.

---

## Request lifecycle

```
Request
  │
  ├─ middleware: web → auth → throttle:300,1 ───────────── refused → 401 / 429
  │
  ├─ size ceiling (Content-Length) ─────────────────────── exceeded → 413
  │
  ├─ envelope: non-empty, header parses ────────────────── invalid → 422
  │
  ├─ connection string: string, parseable ──────────────── invalid → 422
  │
  ├─ key present ───────────────────────────────────────── absent → 401
  ├─ host allowlisted (case-insensitive) ───────────────── refused → 401
  ├─ project present and allowlisted ───────────────────── refused → 422 / 401
  │
  └─ outbound request (connectTimeout 2s, timeout 5s)
        ├─ success ──────────── relay status + allowlisted headers + upstream body
        ├─ upstream error ───── relay status + allowlisted headers + own body, log
        └─ unreachable ──────── 504 + own body, log
```

**Invariant across the whole pipeline**: no stage may produce an internal-failure response for
caller-supplied input (FR-009), and no stage past the middleware may make an outbound request unless
every validation above it passed.
