# Contract: Configuration Surface

**Feature**: [../spec.md](../spec.md) | **Date**: 2026-09-17

Constitution principle II requires every key to carry an explanatory comment block in
`config/sentry-tunnel.php` **and** a corresponding section in `README.md`. A key documented in only
one of the two is incomplete work. This contract is the checklist for that.

---

## Existing keys

| Key | Env | Type | Default | Changed? |
|---|---|---|---|---|
| `allowed-hosts` | `SENTRY_TUNNEL_ALLOWED_HOSTS` | list | from `SENTRY_LARAVEL_DSN` | Parsing only — entries trimmed, compared case-insensitively (FR-012 – FR-014). Shape unchanged. |
| `allowed-projects` | `SENTRY_TUNNEL_ALLOWED_PROJECTS` | list | from `SENTRY_LARAVEL_DSN` | Parsing only — entries trimmed. Shape unchanged. |
| `domain` | — | string, nullable | `null` | No |
| `tunnel-url` | `SENTRY_TUNNEL_URL` | string | `/sentry/tunnel` | No |
| `middleware` | — | list | `['web', 'auth']` | **Yes — becomes `['web', 'auth', 'throttle:300,1']`** (FR-020). Breaking. |

---

## New keys

### `max-payload-size`

| | |
|---|---|
| Env | `SENTRY_TUNNEL_MAX_PAYLOAD_SIZE` |
| Type | integer (bytes), nullable |
| Default | `20971520` (20 MiB) |
| `null` | Disables the ceiling |
| Requirement | FR-021, FR-021a, FR-022 |

Enforced from `Content-Length` before the body is read, with a second guard on the actual body
length. Exceeding it yields `413`.

**Documentation must state**: the default sits far above what browser SDKs emit, including session
replay and profiling payloads, but below Sentry's own 200 MiB envelope ceiling. An operator routing
large attachments through the tunnel must raise or disable it. See [research.md](../research.md) R7
for the full reasoning and the tension it resolves.

### `timeout` and `connect-timeout`

| | `timeout` | `connect-timeout` |
|---|---|---|
| Env | `SENTRY_TUNNEL_TIMEOUT` | `SENTRY_TUNNEL_CONNECT_TIMEOUT` |
| Type | integer or float (seconds) | integer or float (seconds) |
| Default | `5` | `2` |
| Requirement | FR-022a, FR-022b | FR-022a, FR-022b |

Replaces the framework defaults of 30 s and 10 s.

**Documentation must state**: these bound how long a server worker is held when Sentry is slow or
unreachable. The volume ceiling does not bound this — it limits how many requests are admitted, not
how long each is held (FR-022d). Operators behind a slow egress proxy may need to raise them.

### `log-level`

| | |
|---|---|
| Env | `SENTRY_TUNNEL_LOG_LEVEL` |
| Type | string (a PSR-3 level) |
| Default | `warning` |
| Requirement | FR-017a, FR-017b |

The level at which upstream failures are recorded.

**Documentation must state**, explicitly: `warning` is the default *because* the Sentry Laravel SDK
treats ordinary log records at that level as breadcrumbs rather than standalone events. Raising this
to `error` makes upstream failures visible as events — and reopens the amplification loop this
release closes, because each failed relay then manufactures a new report toward the upstream that is
already failing. FR-017b requires this trade-off to appear in the key's documentation, not only here.

---

## Definition of done for this contract

For each of the four new keys and the changed `middleware` default:

- [ ] Comment block in `config/sentry-tunnel.php` explaining what it does and what the default means
- [ ] Section in `README.md`
- [ ] Env variable honoured, where the table above names one
- [ ] Test covering the default value
- [ ] Test covering an operator override
- [ ] For `max-payload-size`: a test covering `null` (disabled)
- [ ] Release notes entry for the `middleware` default change (breaking)
