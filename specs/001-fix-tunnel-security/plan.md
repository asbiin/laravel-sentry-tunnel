# Implementation Plan: Fix Tunnel Security Issues

**Branch**: `001-fix-tunnel-security` *(spec directory; no git branch created — see Notes)* | **Date**: 2026-09-17 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-fix-tunnel-security/spec.md`

## Summary

Close the findings of the 2026-09-17 security review of the tunnel endpoint. One caller-exploitable
injection, three inputs that produce internal server errors, a lossy response relay that breaks the
Sentry SDK's backoff and feeds an amplification loop, two configuration footguns, and two missing
abuse bounds.

The technical approach is small and contained: the whole behavioural change lives in one controller
action and one config file. Nothing new is added to the public API surface; every new behaviour is a
documented configuration key with a safe default. The verified mechanics are in
[research.md](./research.md) — query building via `withQueryParameters`, an explicit response built
from an upstream-header allowlist, `->throw()` replaced by a logged branch, explicit timeouts, and
allowlist parsing that trims and compares case-insensitively.

This release is a **MAJOR** version. Two decisions taken during clarification make it one: the
endpoint's response shape changes (it now carries the upstream's status and selected headers instead
of always `200 text/html`), and a shipped default changes (`middleware` gains `throttle:300,1`).

## Technical Context

**Language/Version**: PHP `^8.2`; verified against 8.4.25 locally, CI matrix 8.2 / 8.3 / 8.4

**Primary Dependencies**: `illuminate/http`, `illuminate/routing`, `illuminate/support`
(`^11.0 || ^12.0 || ^13.0`; 12.1.0 vendored locally), `thecodingmachine/safe` (`^2.5 || ^3.0`).
No new runtime dependency — `withQueryParameters`, `timeout`, `connectTimeout` and the logger are all
already available in the declared range.

**Storage**: N/A — the package holds no persistent state

**Testing**: PHPUnit 11 via Orchestra Testbench, through `SentryTunnel\Tests\TestCase`. All outbound
calls faked with `Http::fake()` / `Http::assertSent()`. Baseline: 10 tests, 33 assertions, green.

**Target Platform**: Linux server, running as a Laravel package inside a host application

**Project Type**: Library (single Composer package)

**Performance Goals**: Not throughput-driven. The binding constraint is worker occupancy: a wholly
unreachable upstream must release its worker within `connect-timeout` + `timeout` (2 s + 5 s default)
rather than the framework's 30 s.

**Constraints**:
- The outbound destination is `https://{allowed-host}/api/{allowed-project}/envelope/` and nothing
  else. No other host, scheme or path may be constructed from caller input.
- The request body and its DSN header are attacker-controlled and must be parsed through the
  `thecodingmachine/safe` wrappers.
- No caller-supplied input may produce a `500`.
- PHPStan level 5, Psalm `errorLevel="7"`, Pint clean — floors, not targets.

**Scale/Scope**: ~80 lines of production code across two files (`src/Http/Controller/SentryTunnel.php`,
`config/sentry-tunnel.php`), plus `README.md`. Roughly 25–30 new tests covering every accept and
refuse path.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Pre-Phase 0 | Post-Phase 1 | Notes |
|---|---|---|---|
| **I. Security-First Tunneling** | PASS | PASS | The feature exists to restore this principle. Fail-closed behaviour on an empty allowlist (FR-015) is explicitly preserved, and the refusal-status discipline the principle demands (401 / 422, never 500) becomes enforceable for the first time. |
| **II. Minimal Surface, Configuration Over Code** | PASS with note | PASS with note | No new public class or method; the route, controller action, provider and config file stay one each. Four new configuration keys — the principle's own prescribed mechanism — but they grow the config surface from 5 keys to 9. Recorded in Complexity Tracking. |
| **III. Test-Backed Behavior** | PASS | PASS | FR-023 requires every accept and refuse path to assert its status and whether an outbound call was made. One nuance found in research: the deprecation assertion cannot use `--fail-on-deprecation`, because Laravel intercepts deprecations first. Recorded in quickstart. |
| **IV. Static Analysis and Style Gates** | PASS | PASS with note | No threshold is lowered and no suppression is added. Note: level 5 did not catch the `trim(null)` defect, because the Safe stub declares `parse_url` without a return type — a green run is not evidence the defect is fixed. Raising the level is out of scope here. |
| **V. Compatibility and Semantic Versioning** | PASS | PASS | Two breaking changes, both deliberate and recorded in the spec's Clarifications: response shape (FR-018, FR-019) and the `middleware` default (FR-020). Requires `feat!:` or a `BREAKING CHANGE:` footer, and release notes per [contracts/http-endpoint.md](./contracts/http-endpoint.md). |

**Gate result**: PASS. No unjustified violations. Two items carried to Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/001-fix-tunnel-security/
├── plan.md                      # This file
├── spec.md                      # Feature specification (4 clarifications integrated)
├── research.md                  # Phase 0 output
├── data-model.md                # Phase 1 output
├── quickstart.md                # Phase 1 output
├── contracts/                   # Phase 1 output
│   ├── http-endpoint.md         # Request/response contract, incl. breaking changes
│   └── configuration.md         # Config key contract + documentation checklist
├── checklists/
│   └── requirements.md          # Spec quality checklist — 16/16
└── tasks.md                     # Phase 2 output (/speckit-tasks — NOT created here)
```

### Source Code (repository root)

```text
src/
├── Http/
│   └── Controller/
│       └── SentryTunnel.php     # All behavioural change lives here
└── Provider.php                 # Unchanged

config/
└── sentry-tunnel.php            # 4 new keys; `middleware` default changes

routes/
└── web.php                      # Unchanged

tests/
├── TestCase.php                 # Unchanged
└── Unit/
    ├── ConfigTest.php           # Extended: trimming, case, new key defaults
    └── SentryTunnelTest.php     # Extended; `it_rethrow_an_error` rewritten

README.md                        # Sections for each new key (constitution II)
```

**Structure Decision**: the existing layout is kept exactly as it is. Constitution principle II caps
this package at one route, one controller action, one service provider and one config file, and
nothing in this feature justifies exceeding that. In particular, the size ceiling and the timeouts
are applied inside the existing controller action rather than as new middleware classes — a new
middleware class would be new public surface that operators could reference, and therefore a
compatibility commitment, for behaviour that a few lines in the existing action already express. The
rate ceiling is the exception and reuses Laravel's own `throttle` middleware rather than anything
this package defines.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Config surface grows from 5 keys to 9 (`max-payload-size`, `timeout`, `connect-timeout`, `log-level`) — tension with principle II's "minimal surface" | Each new behaviour has a legitimate operator-specific value: egress proxies need longer timeouts, installations routing attachments need a larger ceiling, and FR-017b explicitly requires the log level to be operator-chosen. Principle II's own remedy is "a documented configuration key with a safe default" rather than new public API, which is what each of these is. | *Hardcoding the values* was rejected because FR-022 and FR-022b require overridability, and a hardcoded timeout would strand operators behind slow egress. *Folding all four into one array key* was rejected because it obscures each default and makes the per-key documentation that principle II requires harder to write, not easier. |
| `max-payload-size` default of 20 MiB satisfies FR-021a for the browser-tunnel use case, not literally for every Sentry client | FR-021a ("never reject what the upstream would accept") read literally against Relay's `max_envelope_size` of 200 MiB would force a 200 MiB default, which makes FR-021 useless as a memory bound — the two requirements pull opposite at their extremes. 200 MiB is driven by native crash minidumps and large attachments; the `tunnel` option is a browser-SDK feature and native SDKs do not route through it. | *Defaulting to 200 MiB* was rejected because it satisfies FR-021a literally while defeating FR-021 entirely. *Defaulting to 1 MiB* (Relay's `max_event_size`) was rejected because it would reject session replay and profiling envelopes that Sentry accepts — a real FR-021a violation. 20 MiB sits far above browser traffic, matches Relay's own `max_api_payload_size`, and remains a genuine per-request bound. Operators routing attachments must raise or disable it (FR-022). Full reasoning: [research.md](./research.md) R7. |

## Notes

**No git branch exists.** This project registers no `before_specify` hook, so no branch was created
by the spec-kit workflow; work so far sits on `main`. Constitution "Development Workflow" requires
work to reach `main` through a pull request, so a branch must be created before implementation
begins.

**Out of scope, deliberately.** The same review raised packaging and tooling findings — internal
tooling directories (`.claude/`, `.serena/`, `.specify/`) shipping inside the published Composer
archive, `phpunit.xml` not failing on deprecations, and static-analysis thresholds with headroom.
They are hygiene rather than security and are recorded in the spec's Out of Scope section so this
change stays reviewable as a security fix.

**One item deferred from clarification and now resolved**: the byte value behind FR-021, settled in
[research.md](./research.md) R7 against Relay's published limits, with the judgement call it required
recorded above rather than buried.
