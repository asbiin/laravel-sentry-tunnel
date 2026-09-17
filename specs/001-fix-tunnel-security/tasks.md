---

description: "Task list for Fix Tunnel Security Issues"
---

# Tasks: Fix Tunnel Security Issues

**Input**: Design documents from `/specs/001-fix-tunnel-security/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: Test tasks ARE included. They are not optional here — FR-023 requires every accept and
refuse path to assert its status and whether an outbound call was made, SC-009 requires each
behaviour to be demonstrated by a test that fails before the change, and constitution principle III
requires tests to ship in the same change set.

**Organization**: Tasks are grouped by user story so each can be implemented and delivered
independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1–US5)
- Exact file paths are given in every task

## Path Conventions

Single Composer package at the repository root: `src/`, `config/`, `routes/`, `tests/`.

**A note on parallelism, stated honestly**: almost all production changes land in one file,
`src/Http/Controller/SentryTunnel.php`, and most tests land in `tests/Unit/SentryTunnelTest.php`.
Genuine `[P]` opportunities are therefore few, and they are marked only where the files really do
differ. Inventing more would produce merge conflicts, not speed.

---

## Phase 1: Setup

**Purpose**: Establish the working branch and a verified baseline to measure against

- [X] T001 Create branch `001-fix-tunnel-security` from `main` — constitution "Development Workflow" requires work to reach `main` through a pull request, and none exists yet
- [X] T002 Verify the baseline with `vendor/bin/phpunit` and confirm 10 tests / 33 assertions green, so that every later failure is attributable to this work
- [X] T003 [P] Verify the static analysis baseline with `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/psalm --no-progress` and `vendor/bin/pint --test`, all currently clean

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared test infrastructure that US2, US4 and US5 all depend on. Both tasks touch
`tests/TestCase.php`, so they are done once here rather than three times in three stories.

**⚠️ CRITICAL**: T004 and T005 block the test tasks of US2, US4 and US5

- [X] T004 Add a helper to `tests/TestCase.php` that runs a closure with a temporary `set_error_handler` capturing `E_DEPRECATED`, and returns the captured messages — `--fail-on-deprecation` cannot be used because Laravel's `HandleExceptions` routes deprecations to the deprecation log channel before PHPUnit's error handler sees them (see [research.md](./research.md) R2)
- [X] T005 Add a helper to `tests/TestCase.php` that asserts no unhandled application failure was reported during a request, using `Exceptions::fake()` and `Exceptions::assertNothingReported()` — this is the assertion that proves the amplification loop is closed, and it is needed by US2, US4 and US5

**Checkpoint**: `vendor/bin/phpunit` still green; helpers unused so far

---

## Phase 3: User Story 1 - The caller cannot shape the outbound request (Priority: P1) 🎯 MVP

**Goal**: No caller-supplied character can add, alter or remove a parameter on the outbound request.

**Independent test**: submit a report whose DSN key contains `&` and `=`, assert the outbound URL
carries exactly one percent-encoded `sentry_key` parameter.

### Tests for User Story 1

- [X] T006 [US1] Add a failing test to `tests/Unit/SentryTunnelTest.php` asserting that a DSN of `https://legit&sentry_client=pwn&extra=1@account.sentry.test/123` produces the outbound URL `https://account.sentry.test/api/123/envelope/?sentry_key=legit%26sentry_client%3Dpwn%26extra%3D1` — this test fails today, producing three caller-controlled parameters
- [X] T007 [P] [US1] Add a test to `tests/Unit/SentryTunnelTest.php` asserting a DSN key containing a space and a percent sign is transmitted intact as a single value and still addressed to the allowlisted host and project (spec US1 scenario 2)
- [X] T008 [P] [US1] Add a test to `tests/Unit/SentryTunnelTest.php` asserting an empty DSN key (`https://@account.sentry.test/123`) is refused with 401 and `Http::assertNothingSent()` (FR-003)

### Implementation for User Story 1

- [X] T009 [US1] In `src/Http/Controller/SentryTunnel.php`, replace the interpolated `?sentry_key=$user` URL with `->withQueryParameters(['sentry_key' => $user])` and a URL of `"https://$host/api/$projectId/envelope/"` (FR-001, FR-002; see [research.md](./research.md) R1)
- [X] T010 [US1] In `src/Http/Controller/SentryTunnel.php`, extend the existing key check in `parseDsn()` so an empty-string key is refused alongside a null one, with status 401 (FR-003)

**Checkpoint**: T006–T008 pass; the pre-existing `it_proxies_a_request` and `it_proxies_the_enveloppe` tests still pass unchanged, proving no regression for ordinary keys (spec US1 scenario 3)

---

## Phase 4: User Story 2 - Hostile and malformed input is refused, never crashed on (Priority: P1)

**Goal**: Every malformed input yields a deliberate client-error status; none yields a 500 or a PHP
deprecation.

**Independent test**: send each malformed input, assert the status, assert `Http::assertNothingSent()`,
assert nothing was reported as an unhandled failure.

**Depends on**: T004, T005

### Tests for User Story 2

- [X] T011 [US2] Add a data-provider-driven failing test to `tests/Unit/SentryTunnelTest.php` covering an empty body, a non-JSON envelope header, a `dsn` that is an array, and a `dsn` of `"http:///"` — asserting 422 for each, plus `Http::assertNothingSent()` and the no-unhandled-failure helper from T005. Three of these four return 500 today
- [X] T012 [US2] Add a test to `tests/Unit/SentryTunnelTest.php` using the T004 helper to assert that a DSN with no path (`https://user@account.sentry.test`) produces 422 **and captures zero deprecations** — this currently emits `trim(): Passing null to parameter #1 ($string) of type string is deprecated`

### Implementation for User Story 2

- [X] T013 [US2] In `src/Http/Controller/SentryTunnel.php`, refuse an empty or blank request body with 422 before calling `json_decode` (FR-005)
- [X] T014 [US2] In `src/Http/Controller/SentryTunnel.php`, wrap the envelope-header `Safe\json_decode` so a `JsonException` becomes a 422 rather than propagating (FR-006)
- [X] T015 [US2] In `src/Http/Controller/SentryTunnel.php`, assert in `parseDsn()` that the `dsn` value is a string before parsing it, refusing non-strings with 422 (FR-007)
- [X] T016 [US2] In `src/Http/Controller/SentryTunnel.php`, wrap the `Safe\parse_url` calls so an unparseable address becomes a 422 rather than propagating a `UrlException` (FR-008)
- [X] T017 [US2] In `src/Http/Controller/SentryTunnel.php`, cast the parsed path to string before `trim()` so a pathless DSN no longer triggers the deprecation (FR-010)
- [X] T018 [US2] Review every remaining `abort_if` in `src/Http/Controller/SentryTunnel.php` and confirm each refusal message names only the failure, never the configured allowlists (FR-011)

**Checkpoint**: all five inputs from the quickstart Scenario 2 table return 422 with no outbound call and no deprecation; the endpoint can no longer be made to return 500 by any caller input (FR-009)

---

## Phase 5: User Story 3 - Allowlists behave exactly as documented (Priority: P2)

**Goal**: An allowlist written the way a person naturally writes one works on the first attempt,
without weakening the fail-closed default.

**Independent test**: configure `'a.sentry.test, account.sentry.test'`, tunnel to the second entry,
assert acceptance.

### Tests for User Story 3

- [X] T019 [US3] Add a failing test to `tests/Unit/SentryTunnelTest.php` asserting that an allowlist of `'a.sentry.test, account.sentry.test'` accepts a report for `account.sentry.test` — currently 401 because of the leading space
- [X] T020 [P] [US3] Add a test to `tests/Unit/SentryTunnelTest.php` asserting that an incoming host of `ACCOUNT.SENTRY.TEST` is accepted against an allowlist of `account.sentry.test` (FR-013)
- [X] T021 [P] [US3] Add regression tests to `tests/Unit/SentryTunnelTest.php` asserting that an empty or unset allowlist refuses every report with 401, and that a genuinely absent host is still refused — the fail-closed guarantee these changes must not weaken (FR-015)
- [X] T022 [P] [US3] Add a test to `tests/Unit/ConfigTest.php` asserting that allowlist entries which are empty after trimming are discarded (FR-014)

### Implementation for User Story 3

- [X] T023 [US3] In `src/Http/Controller/SentryTunnel.php`, trim each entry in `allowedHosts()` and `allowedProjects()` and discard entries empty after trimming (FR-012, FR-014)
- [X] T024 [US3] In `src/Http/Controller/SentryTunnel.php`, compare the incoming host against the allowlist case-insensitively while keeping the comparison strict about everything else (FR-013)

**Checkpoint**: all four quickstart Scenario 3 rows behave as tabulated, including the fail-closed row

---

## Phase 6: User Story 4 - Upstream failures relayed honestly, promptly, and without leaking (Priority: P2)

**Goal**: The caller receives Sentry's own status and backoff headers; upstream response text never
leaks; no upstream failure becomes an application error; an unreachable upstream releases its worker
in seconds.

**Independent test**: fake a 429 with `Retry-After: 60`, assert the caller receives 429 with that
header, one log entry at `warning`, and nothing reported as an unhandled failure.

**Depends on**: T005

⚠️ **This phase deliberately breaks an existing test.** `it_rethrow_an_error` asserts a 500 on
upstream failure, which is exactly the behaviour FR-019 removes. T025 rewrites it; this is the one
sanctioned exception to SC-010.

### Tests for User Story 4

- [X] T025 [US4] Rewrite `it_rethrow_an_error` in `tests/Unit/SentryTunnelTest.php` to assert the relayed upstream status instead of 500, and rename it to reflect relaying rather than rethrowing
- [X] T026 [US4] Add a test to `tests/Unit/SentryTunnelTest.php` asserting that an upstream 202 with `Content-Type: application/json` and `X-Sentry-Rate-Limits` reaches the caller as 202 with both headers and the upstream body intact (FR-019, FR-016 success path)
- [X] T027 [US4] Add a test to `tests/Unit/SentryTunnelTest.php` asserting that an upstream 429 with `Retry-After: 60` reaches the caller as 429 carrying `Retry-After` (FR-018)
- [X] T028 [US4] Add a test to `tests/Unit/SentryTunnelTest.php` asserting that an upstream header outside the allowlist — e.g. `X-Internal-Secret` — is **not** relayed (FR-019a)
- [X] T029 [US4] Add a test to `tests/Unit/SentryTunnelTest.php` asserting that an upstream 400 whose body is `"bad envelope"` produces a caller body that does not contain that text, with `APP_DEBUG=true` set for the request (FR-016, SC-006)
- [X] T030 [US4] Add a test to `tests/Unit/SentryTunnelTest.php` using the T005 helper to assert that an upstream error reports no unhandled application failure (FR-017)
- [X] T031 [US4] Add a test to `tests/Unit/SentryTunnelTest.php` with `Log::spy()` asserting one log entry at `warning` carrying the upstream status, host and project, and **not** the upstream response text (FR-017a)
- [X] T032 [P] [US4] Add a test to `tests/Unit/ConfigTest.php` asserting the `log-level` default is `warning`, and a test asserting an operator override is honoured (FR-017b)
- [X] T033 [US4] Add a test to `tests/Unit/SentryTunnelTest.php` faking a `ConnectionException` and asserting the caller receives 504, one log entry is written, and nothing is reported as an unhandled failure (FR-022c)
- [X] T034 [P] [US4] Add tests to `tests/Unit/ConfigTest.php` asserting the `timeout` default is 5 and `connect-timeout` is 2, and that operator overrides are honoured (FR-022b)
- [X] T035 [US4] Add a test to `tests/Unit/SentryTunnelTest.php` asserting the outbound request is issued with the configured timeouts — assert on the configured values, not on wall-clock duration, which is flaky in CI

### Implementation for User Story 4

- [X] T036 [US4] Add `log-level` (default `warning`), `timeout` (default 5) and `connect-timeout` (default 2) to `config/sentry-tunnel.php`, each with the explanatory comment block constitution principle II requires — the `log-level` comment must state that raising it to `error` reopens the amplification loop (FR-017b, see [contracts/configuration.md](./contracts/configuration.md))
- [X] T037 [US4] In `src/Http/Controller/SentryTunnel.php`, apply `->connectTimeout()` and `->timeout()` from config to the outbound request (FR-022a, FR-022b)
- [X] T038 [US4] In `src/Http/Controller/SentryTunnel.php`, remove `->throw()` and branch on the upstream outcome into the three states of the [data-model](./data-model.md) "Upstream outcome" table (FR-017)
- [X] T039 [US4] In `src/Http/Controller/SentryTunnel.php`, build the returned response explicitly from the upstream status plus the `Content-Type` / `Retry-After` / `X-Sentry-Rate-Limits` allowlist, instead of returning the HTTP client's own `Response` object — returning that object is what makes the router substitute 200 and `text/html` today (FR-019, FR-019a; see [research.md](./research.md) R3)
- [X] T040 [US4] In `src/Http/Controller/SentryTunnel.php`, relay the upstream body on success and substitute a package-determined body on the error path (FR-016)
- [X] T041 [US4] In `src/Http/Controller/SentryTunnel.php`, catch `ConnectionException` and answer 504 (FR-022c)
- [X] T042 [US4] In `src/Http/Controller/SentryTunnel.php`, log every upstream error and every connection failure at the configured level with status, host and project — and never the upstream response text or the envelope content (FR-017a)

**Checkpoint**: every row of the quickstart Scenario 4 table behaves as tabulated, including under `APP_DEBUG=true`

---

## Phase 7: User Story 5 - The endpoint resists volume abuse (Priority: P3)

**Goal**: The shipped defaults bound how many reports one caller may relay and how large each may be.

**Independent test**: exceed the ceiling and assert the excess is refused; send an oversized body and
assert 413 with no outbound call.

**Depends on**: T005

### Tests for User Story 5

- [X] T043 [US5] Add a test to `tests/Unit/SentryTunnelTest.php` asserting that a `Content-Length` above the ceiling yields 413 with `Http::assertNothingSent()` (FR-021)
- [X] T044 [P] [US5] Add a test to `tests/Unit/ConfigTest.php` asserting the `max-payload-size` default is 20 MiB, that an operator override is honoured, and that `null` disables the ceiling (FR-022)
- [X] T045 [P] [US5] Add a test to `tests/Unit/ConfigTest.php` asserting the shipped `middleware` default is `['web', 'auth', 'throttle:300,1']`, with `throttle` after `auth` so an unauthenticated probe consumes no rate-limit slot (FR-020)
- [X] T046 [US5] Add a test to `tests/Unit/SentryTunnelTest.php` asserting that an operator who overrides `sentry-tunnel.middleware` governs, and the default throttle is not reimposed (spec US5 scenario 3)

### Implementation for User Story 5

- [X] T047 [US5] Add `throttle:300,1` after `auth` in the `middleware` default in `config/sentry-tunnel.php` (FR-020)
- [X] T048 [US5] Add `max-payload-size` to `config/sentry-tunnel.php` with a default of `20971520` bytes, `null` to disable, and a comment block explaining that the value sits above browser SDK traffic but below Sentry's own 200 MiB envelope ceiling (FR-021, FR-021a; the reasoning and the requirement tension it resolves are in [research.md](./research.md) R7)
- [X] T049 [US5] In `src/Http/Controller/SentryTunnel.php`, refuse with 413 when `Content-Length` exceeds the ceiling, before the body is read, with a second guard on the actual body length — PHP's `post_max_size` is not a substitute, because it is not applied to a body whose content type is not a form type (FR-021)

**Checkpoint**: every row of the quickstart Scenario 5 table behaves as tabulated

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T050 [P] Add a README section for each of the four new keys — `max-payload-size`, `timeout`, `connect-timeout`, `log-level` — in `README.md`; constitution principle II treats a key documented in only the config file or only the README as incomplete work
- [X] T051 [P] Update the "Security" section of `README.md` to describe the shipped rate ceiling and how to change it, replacing the current text that says the middleware list is only `web` and `auth`
- [X] T052 Walk the "Definition of done" checklist in [contracts/configuration.md](./contracts/configuration.md) and confirm every box for all four new keys and the changed `middleware` default
- [X] T053 Walk every refusal row of [contracts/http-endpoint.md](./contracts/http-endpoint.md) against the tests in `tests/Unit/SentryTunnelTest.php`, confirming each row has a test asserting its status and that no caller-supplied input anywhere returns 500 (FR-009)
- [X] T054 Run `vendor/bin/phpunit`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/psalm --no-progress` and `vendor/bin/pint --test`; all four must pass with no threshold lowered and no new suppression (constitution principle IV)
- [X] T055 Confirm no new `@phpstan-ignore` or `@psalm-suppress` was introduced in `src/`, and that the two pre-existing `@phpstan-ignore` comments on `array_filter` are still narrowly scoped
- [X] T056 Write the pull request title and body using `feat!:` or a `BREAKING CHANGE:` footer, listing the five compatibility notes from [contracts/http-endpoint.md](./contracts/http-endpoint.md) — semantic-release reads these as release inputs, so this is what makes the release MAJOR (FR-025, constitution principle V)

---

## Dependencies & Execution Order

### Phase Dependencies

```
Phase 1 (Setup)
   └─> Phase 2 (Foundational: T004, T005)
          ├─> Phase 3 (US1, P1)  ─┐
          ├─> Phase 4 (US2, P1)  ─┤
          ├─> Phase 5 (US3, P2)  ─┼─> Phase 8 (Polish)
          ├─> Phase 6 (US4, P2)  ─┤
          └─> Phase 7 (US5, P3)  ─┘
```

### User Story Dependencies

All five stories are functionally independent — none requires another's behaviour to be correct.
US2, US4 and US5 depend only on the shared test helpers T004 and T005.

**But they are not independent in the working tree**: US1, US2, US3, US4 and US5 all modify
`src/Http/Controller/SentryTunnel.php`, and US4 and US5 both modify `config/sentry-tunnel.php`.
Deliver the phases in order rather than concurrently.

### Within Each User Story

Tests first, then implementation, then the checkpoint. Constitution principle III requires the tests
to ship in the same change set regardless, and writing them first is what makes SC-009 checkable —
each test must be seen failing against the current code before the fix lands.

### Parallel Opportunities

Real ones only:

- T003 runs alongside T002 (different commands, no shared state)
- T007 and T008 alongside T006 (independent test methods, same file — parallel to author, sequential to commit)
- T020, T021, T022 alongside T019 — T022 is in a different file
- T032 and T034 alongside the US4 tests — both are in `tests/Unit/ConfigTest.php`
- T044 and T045 alongside T043 — both are in `tests/Unit/ConfigTest.php`
- T050 and T051 alongside each other in `README.md`

No production task is parallelisable with another: every one of them edits
`src/Http/Controller/SentryTunnel.php` or `config/sentry-tunnel.php`.

---

## Parallel Example: User Story 3

```
Author together (T019 in SentryTunnelTest.php, T022 in ConfigTest.php — different files):
  T019  allowlist with spaces is accepted
  T022  entries empty after trimming are discarded

Then, in SentryTunnelTest.php, independent test methods:
  T020  case-insensitive host comparison
  T021  fail-closed regressions

Then, sequentially, in SentryTunnel.php:
  T023  trim entries
  T024  case-insensitive comparison
```

---

## Implementation Strategy

### MVP scope

**Phases 1–3 (T001–T010).** US1 alone is a shippable increment: it closes the only finding where an
ordinary caller gains control over something the operator is supposed to own exclusively, and it
requires no configuration change, so it could ship as a patch release if the breaking changes were
deferred.

### Recommended increments

1. **T001–T010** — US1. The injection is closed. Patch-releasable in isolation.
2. **T011–T018** — US2. The endpoint can no longer be made to return 500. Still patch-releasable.
3. **T019–T024** — US3. Allowlists work as documented. Still patch-releasable.
4. **T025–T042** — US4. **First breaking change**: the response shape changes.
5. **T043–T049** — US5. **Second breaking change**: shipped defaults change.
6. **T050–T056** — Documentation, gates, and the MAJOR release.

Increments 1–3 carry no compatibility exposure; increments 4 and 5 are what make this a MAJOR
release. If the release needs to be split, the natural seam is after increment 3.

### Out of scope

Packaging hygiene — `.claude/`, `.serena/` and `.specify/` shipping inside the published Composer
archive — plus `phpunit.xml` deprecation settings and static-analysis thresholds. Recorded in the
spec's Out of Scope section and deliberately excluded so this change stays reviewable as a security
fix.
