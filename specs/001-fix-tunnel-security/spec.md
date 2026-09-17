# Feature Specification: Fix Tunnel Security Issues

**Feature Branch**: `001-fix-tunnel-security` *(not yet created — no `before_specify` branch hook is registered in this project)*

**Created**: 2026-09-17

**Status**: Draft

**Input**: User description: "Fix security issues"

**Origin**: This specification was derived from a security review of the tunnel endpoint conducted on 2026-09-17. Every issue below was reproduced against the current code, not inferred.

## Clarifications

### Session 2026-09-17

- Q: When the upstream answers with an error or a throttling signal, must the tunnel relay the received status and headers to the browser, given that this changes the shape of the endpoint's response and therefore requires a MAJOR release? → A: Option A — full faithful relay of status, content type and throttling headers, on both success and error paths; this work is released as a MAJOR version.
- Q: Should the volume ceiling be added to the shipped default configuration, or offered as a documented opt-in that each operator enables themselves? → A: Option A — in the shipped default, deliberately generous (~300 reports per minute per caller), together with a report size ceiling. The MAJOR release is already committed by the previous answer, so changing the default costs no additional version bump.
- Q: When the upstream answers with an error, what must the application record on the server side, now that FR-017 forbids letting an unhandled failure surface? → A: Option D — a structured log entry carrying the upstream status, host and project but never the upstream response text, at a level the operator can configure, defaulting to warning.
- Q: What must the tunnel do when the upstream does not answer at all — unreachable or too slow — as distinct from answering with an error? → A: Option A — explicit, short connection and overall timeouts, operator-configurable, with a tight shipped default; on expiry a deliberate refusal, no unhandled failure, and a log entry per FR-017a.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The caller cannot shape the outbound request (Priority: P1)

An application operator installs the tunnel so that browser error reports reach their Sentry
organisation. A person who holds a valid session on that application crafts an error report whose
embedded connection string carries extra instructions in its key portion. Today those extra
instructions are copied verbatim into the request the server makes to Sentry, so the caller — not
the operator — decides part of what Sentry receives. After this change, the only thing a caller can
influence is the report payload itself; the destination and every parameter attached to it are
determined solely by the operator's configuration.

**Why this priority**: This is the only finding where an ordinary caller gains control over
something the operator is supposed to own exclusively. The project's first principle requires that
no part of the outbound request be constructed from caller-supplied input beyond the allowlisted
host and project, and today that guarantee does not hold.

**Independent Test**: Submit a report whose connection-string key contains separator and assignment
characters, then inspect the request the server made. The key must arrive as a single opaque value
with no additional parameters, regardless of what the caller wrote.

**Acceptance Scenarios**:

1. **Given** a report whose connection-string key contains parameter separators and assignment
   characters, **When** the report is tunnelled, **Then** the outbound request carries exactly one
   key parameter and no additional parameters.
2. **Given** a report whose connection-string key contains spaces, percent signs, or other
   characters that are meaningful inside a web address, **When** the report is tunnelled, **Then**
   the outbound request is still addressed to the allowlisted host and project and the key is
   transmitted intact as a single value.
3. **Given** a report whose connection string is entirely ordinary, **When** the report is
   tunnelled, **Then** the outbound request is byte-for-byte what it is today (no regression for
   existing installations).

---

### User Story 2 - Hostile and malformed input is refused, never crashed on (Priority: P1)

Someone probes the tunnel endpoint with an empty body, with a report header that is not valid
structured data, with a connection string that is a list instead of text, and with a connection
string that is not a parseable address. Today each of these produces an internal server error.
That means the operator's error log fills with application failures caused by ordinary probing,
and — because most installations of this package also report their own errors to Sentry — each
probe manufactures a new error report. After this change, every one of these inputs is refused
with a deliberate client-error response and no internal failure is recorded.

**Why this priority**: The project's first principle requires refusals to carry explicit status
codes (401 for authorisation failures, 422 for malformed input). An internal server error is
neither. It also converts a cheap probe into an amplified, self-inflicted write to the very
service the tunnel protects.

**Independent Test**: Send each malformed input in turn and assert the response status is a
deliberate client error, that no outbound request was made, and that no unhandled failure was
recorded.

**Acceptance Scenarios**:

1. **Given** a request with an empty body, **When** it reaches the tunnel, **Then** the response is
   a malformed-input refusal and no outbound request is made.
2. **Given** a request whose report header is not valid structured data, **When** it reaches the
   tunnel, **Then** the response is a malformed-input refusal and no outbound request is made.
3. **Given** a report header whose connection string is a list, a number, or a boolean rather than
   text, **When** it reaches the tunnel, **Then** the response is a malformed-input refusal and no
   outbound request is made.
4. **Given** a connection string that cannot be parsed as an address, **When** it reaches the
   tunnel, **Then** the response is a malformed-input refusal and no outbound request is made.
5. **Given** a connection string that names an allowlisted host but carries no project, **When** it
   reaches the tunnel, **Then** the response is a malformed-input refusal, no outbound request is
   made, and no language-level deprecation is triggered.

---

### User Story 3 - Allowlists behave exactly as documented (Priority: P2)

An operator follows the documentation and lists several permitted Sentry hosts, separated by
commas, writing them the way a person naturally would — with a space after each comma. Today every
entry after the first is silently unusable, and every report destined for those hosts is refused as
unauthorised. The operator has no way to tell a configuration mistake from a genuine refusal.
After this change, the allowlist is interpreted the way it reads, and host comparison ignores
differences that carry no meaning in a host name.

**Why this priority**: This fails closed, so it is not exploitable — but it is a silent
misconfiguration that pushes operators toward the dangerous workaround of emptying the allowlist
altogether. Making the safe configuration easy to get right is what keeps the first principle
effective in practice.

**Independent Test**: Configure an allowlist written with spaces after the separators and in mixed
letter case, then tunnel a report to each listed host and confirm each is accepted.

**Acceptance Scenarios**:

1. **Given** an allowlist written with spaces around its separators, **When** a report targets any
   listed host, **Then** the report is accepted.
2. **Given** an allowlist entry and an incoming host that differ only in letter case, **When** the
   report is tunnelled, **Then** the report is accepted.
3. **Given** an allowlist that is empty or unset, **When** any report arrives, **Then** it is
   refused as unauthorised (the fail-closed behaviour is preserved).
4. **Given** a report targeting a host that is genuinely absent from a correctly-written allowlist,
   **When** it is tunnelled, **Then** it is refused as unauthorised.

---

### User Story 4 - Upstream failures are relayed honestly, promptly, and without leaking (Priority: P2)

Sentry starts throttling the operator's organisation and answers the tunnel with a throttling
response that says how long to wait. Today the caller's browser is told only that the application
failed, with none of the wait instruction, so the reporting library keeps retrying at full rate —
and each application failure is itself reported to Sentry, which is already throttling. The
operator sees a traffic spike precisely when they can least afford one. Separately, the text of
the upstream response is carried inside the recorded failure, where it is written to logs and, on
an installation left in debug mode, shown to the caller.

**Why this priority**: The first principle forbids leaking upstream response bodies to the caller.
The amplification loop turns a normal upstream condition into an outage-shaped event. It is P2
rather than P1 only because it requires the upstream to be failing before it bites.

**Independent Test**: Make the upstream answer with a throttling response carrying a wait
instruction, then inspect what the caller receives and what gets recorded. The caller must receive
the throttling signal; the log must show the failure without the upstream's response text; nothing
may be recorded as an unhandled application failure.

**Acceptance Scenarios**:

1. **Given** the upstream answers with a throttling response carrying a wait instruction, **When**
   the tunnel relays it, **Then** the caller receives a response that preserves the throttling
   signal and its wait instruction.
2. **Given** the upstream answers with any error, **When** the tunnel relays it, **Then** the
   upstream's response text is not disclosed to the caller.
3. **Given** the upstream answers with an error, **When** the tunnel relays it, **Then** no
   unhandled application failure is recorded, so no new report is manufactured toward the upstream.
3a. **Given** the upstream answers with an error, **When** the tunnel relays it, **Then** a log entry
   is recorded at the configured level carrying the upstream status, host and project, and not the
   upstream response text.
3b. **Given** an operator who has raised the configured log level, **When** the upstream answers with
   an error, **Then** the entry is recorded at the level the operator chose.
3c. **Given** an upstream that is unreachable, **When** a report is tunnelled, **Then** the caller
   receives a deliberate gateway-failure status within the configured timeout, no unhandled failure
   is recorded, and the worker is released.
3d. **Given** an upstream that accepts the connection but never answers, **When** a report is
   tunnelled, **Then** the overall timeout ends the attempt rather than the framework default.
4. **Given** the upstream answers successfully, **When** the tunnel relays it, **Then** the caller
   receives the upstream's own success status and content type rather than a substituted one.

---

### User Story 5 - The endpoint resists volume abuse (Priority: P3)

A single authenticated account — or a compromised one — submits reports in a tight loop. Today
nothing in the shipped configuration limits how many reports one caller may relay, or how large
each may be, so one account can exhaust the operator's Sentry quota and occupy server workers for
as long as it likes. After this change the shipped configuration places a documented ceiling on
both.

**Why this priority**: The documentation already warns that the endpoint is a relay, and the
authentication requirement keeps it away from anonymous callers, so this is hardening rather than a
repair of a broken guarantee. It ships in the default configuration (decided 2026-09-17) and is
calibrated generously so that no legitimate installation notices it.

**Independent Test**: Submit more reports than the configured ceiling allows within the window and
confirm the excess is refused; submit a report larger than the configured size ceiling and confirm
it is refused before any outbound request is made.

**Acceptance Scenarios**:

1. **Given** the shipped configuration, **When** one caller submits more than 300 reports within a
   minute, **Then** further reports are refused until the window resets and no outbound request is
   made for them.
2. **Given** the shipped configuration, **When** a report exceeds the permitted size, **Then** it is
   refused and no outbound request is made.
3. **Given** an operator who has overridden the ceiling in their own configuration, **When**
   reports arrive, **Then** the operator's value governs and the shipped default is not reimposed.

---

### Edge Cases

- A connection-string key that is empty after parsing — must be refused rather than producing an
  outbound request with a blank key.
- A connection string containing more than one `@` separator, where the parsed key legitimately
  contains an `@`.
- A report header that parses successfully but contains no connection string at all — already
  refused today; must stay refused.
- A project identifier written with leading text or trailing text around the digits — the current
  behaviour extracts the leading digits; this must not become a way to reach an unlisted project.
- A report body containing several concatenated envelopes — only the first header governs routing,
  and the remainder is relayed untouched; this is inherent to the tunnel contract and is not
  changed here.
- An allowlist entry that is blank after trimming — must be discarded rather than matching a blank
  host.
- The upstream being unreachable, or accepting the connection and then never answering, as distinct
  from answering with an error — covered by FR-022a through FR-022d.
- An operator who has deliberately removed the authentication requirement from the middleware list
  — the ceiling from User Story 5 becomes their only remaining protection.

## Requirements *(mandatory)*

### Functional Requirements

**Outbound request integrity**

- **FR-001**: The system MUST ensure that no character supplied by the caller can introduce an
  additional parameter, alter an existing parameter, or change the path of the outbound request.
- **FR-002**: The system MUST transmit the caller's connection-string key as a single opaque value,
  preserving its content exactly as parsed.
- **FR-003**: The system MUST refuse a report whose connection-string key is absent or empty, using
  an authorisation-failure status, before any outbound request is made.
- **FR-004**: The system MUST continue to address every outbound request to an allowlisted host and
  an allowlisted project, and MUST NOT construct any other host, scheme, or path from caller input.

**Refusal behaviour**

- **FR-005**: The system MUST refuse a request whose body is empty, using a malformed-input status.
- **FR-006**: The system MUST refuse a request whose report header is not valid structured data,
  using a malformed-input status.
- **FR-007**: The system MUST refuse a report whose connection string is not text, using a
  malformed-input status.
- **FR-008**: The system MUST refuse a report whose connection string cannot be parsed as an
  address, using a malformed-input status.
- **FR-009**: The system MUST NOT produce an internal-failure response for any caller-supplied
  input; every rejection MUST be a deliberate client-error response.
- **FR-010**: The system MUST NOT trigger any language-level deprecation while parsing
  caller-supplied input, including when the connection string carries no path component.
- **FR-011**: Refusal responses MUST NOT disclose the configured allowlists.

**Allowlist interpretation**

- **FR-012**: The system MUST ignore whitespace surrounding each entry when reading the host and
  project allowlists.
- **FR-013**: The system MUST compare hosts without regard to letter case.
- **FR-014**: The system MUST discard allowlist entries that are empty after whitespace is removed.
- **FR-015**: The system MUST refuse every report when the host allowlist is empty or unset.

**Upstream relay**

- **FR-016**: On the error path, the system MUST NOT disclose the upstream's response text to the
  caller; the relayed error carries the upstream's status but a body determined by this package.
  On the success path the upstream's body MAY be relayed unchanged, because it contains only the
  identifier of the caller's own report.
- **FR-017**: The system MUST NOT record an unhandled application failure when the upstream answers
  with an error status.
- **FR-017a**: The system MUST instead record a structured log entry for every upstream error,
  carrying the upstream status, the target host and the target project, so that an operator can tell
  from their logs alone that the tunnel has stopped reaching Sentry. The entry MUST NOT carry the
  upstream response text.
- **FR-017b**: The severity level of that log entry MUST be operator-configurable through a
  documented configuration key, defaulting to warning. The shipped default MUST be a level that the
  host application's own error reporting does not capture as a new event, so that logging the
  failure cannot reopen the amplification loop FR-017 closes. If an operator raises the level to one
  that their error reporting does capture, that is their decision to make and MUST be noted in the
  configuration key's documentation.
- **FR-018**: The system MUST relay the upstream's throttling signal, including any wait
  instruction, so that the caller's reporting library can apply its own backoff.
- **FR-019**: The system MUST relay the upstream's own status and content type rather than
  substituting its own, on both the success and the error path.
- **FR-019a**: The set of upstream headers relayed to the caller MUST be an explicit allowlist, so
  that relaying never becomes a channel for disclosing upstream detail the caller has no need for.
  At minimum it MUST carry the content type and the throttling signals; it MUST NOT carry upstream
  identifiers or diagnostic headers.

**Volume ceiling**

- **FR-020**: The shipped default configuration MUST limit one caller to 300 relayed reports per
  minute. Reports beyond the ceiling MUST be refused without an outbound request being made.
- **FR-021**: The shipped default configuration MUST place a ceiling on the size of a single report,
  refused before any outbound request is made and before the whole body is held in memory.
- **FR-021a**: The size ceiling MUST sit above the largest report the Sentry client libraries
  produce in normal operation, including session replay and profiling payloads, so that the ceiling
  never rejects a report the upstream would itself have accepted.
- **FR-022**: Both ceilings MUST be overridable by the operator through documented configuration
  keys, and an operator MUST be able to disable either one.

**Upstream availability**

- **FR-022a**: The system MUST apply an explicit connection timeout and an explicit overall timeout
  to the outbound request rather than inheriting the framework's default. The shipped defaults MUST
  be short enough that an entirely unreachable upstream cannot hold a server worker for more than a
  few seconds.
- **FR-022b**: Both timeouts MUST be operator-configurable through documented configuration keys.
- **FR-022c**: When the upstream cannot be reached, or a timeout expires, the system MUST answer the
  caller with a deliberate gateway-failure status, MUST NOT record an unhandled application failure,
  and MUST record a log entry as required by FR-017a.
- **FR-022d**: The timeouts MUST be enforced independently of the volume ceiling, since the ceiling
  bounds how many requests are admitted but not how long each one occupies a worker.

**Project obligations**

- **FR-023**: Every accepting path and every refusing path introduced or changed by this work MUST
  be covered by a test asserting its status code and whether an outbound request was made.
- **FR-024**: Every configuration key introduced by this work MUST be documented both in the
  configuration file's comment blocks and in the project README.
- **FR-025**: This work changes the shape of the endpoint's response (FR-018, FR-019) and changes
  shipped default configuration values (FR-020, FR-021). It MUST therefore be released as a MAJOR
  version under the project's versioning policy, with both changes called out in the release notes
  so operators can anticipate them.

### Key Entities

- **Report envelope**: the caller-supplied payload. Its first line is a header carrying the
  connection string; the remainder is opaque and relayed untouched.
- **Connection string**: the public Sentry address embedded in the report header. Contributes three
  values — a key, a host, and a project identifier — all of which are attacker-controlled and none
  of which may be trusted before validation.
- **Host allowlist**: the operator's list of permitted destinations. Empty means nothing is
  permitted.
- **Project allowlist**: the operator's list of permitted projects. Empty means every project on an
  allowlisted host is permitted.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Zero of the crafted connection strings in the review's reproduction set produce an
  outbound request carrying any parameter the operator did not configure (today: one of them does).
- **SC-002**: Zero of the malformed inputs in the review's reproduction set produce an internal
  server error (today: three of five do).
- **SC-003**: 100% of refusals carry an authorisation-failure or malformed-input status, and none
  carries an internal-failure status.
- **SC-004**: An operator can write the allowlist in any reasonable form — with or without spaces
  after separators, in any letter case — and every listed host is accepted on the first attempt.
- **SC-005**: When the upstream throttles, the caller receives the throttling signal and its wait
  instruction, and the number of reports the application itself sends upstream in response is zero
  (today: one per throttled report).
- **SC-006**: No upstream response text is observable by the caller on any upstream error status,
  in either normal or debug operating mode. On upstream success the caller receives the upstream's
  own status, content type and body.
- **SC-007**: On a default installation, one caller cannot relay more than 300 reports per minute,
  and both ceilings are discoverable from the README without reading source code.
- **SC-008**: With the upstream entirely unreachable, a tunnelled report releases its server worker
  within the configured timeout rather than after the framework's 30-second default, and the caller
  receives a deliberate gateway-failure status rather than an internal failure.
- **SC-008a**: An operator whose Sentry credentials have been revoked can identify the cause from
  their application logs alone, from the first refused report onward, without the upstream response
  text appearing anywhere in those logs.
- **SC-009**: Every behaviour above is demonstrated by an automated test that fails against the
  current code and passes after the change.
- **SC-010**: The existing behaviour for well-formed reports is unchanged — every test that passes
  today still passes, except where a test asserts behaviour this spec deliberately changes.

## Assumptions

- **Scope is the security review, not the whole review.** The same review also raised packaging and
  tooling findings: internal tooling directories being shipped inside the published package, test
  configuration that does not fail on deprecations, and static-analysis thresholds with headroom.
  Those are excluded here — they are hygiene, not security — and are better handled as a separate
  change so that this one stays reviewable as a security fix.
- **The key is preserved, not restricted.** The fix for User Story 1 makes the key safe to transmit
  rather than rejecting keys that look unusual. Rejecting on a character allowlist would also close
  the hole, but it risks refusing a legitimate key format that Sentry accepts today, and the project
  cannot verify the full set of key formats Sentry has ever issued. An empty key is still refused
  (FR-003).
- **The relay contract is unchanged.** Only the first envelope header is parsed for routing; the
  body is relayed untouched. Inspecting or filtering the payload is out of scope.
- **Authentication stays in the shipped default.** Removing it would be a breaking security change
  under the project's first principle, and nothing in this work proposes it.
- **The existing fail-closed behaviour is a floor.** No change here may make the endpoint accept
  something it refuses today, except where a refusal is itself identified as a defect (User Story 3).
- **Operators may be running in debug mode.** The leak requirement (FR-016) is specified to hold in
  both operating modes rather than relying on production configuration.
- **The rate ceiling is settled; the size ceiling value is not.** 300 reports per minute per caller
  is a decided default (FR-020). The concrete byte value behind FR-021 is deliberately left to
  planning, where it will be set against Sentry's published envelope limits so that FR-021a holds;
  choosing it here would be guesswork.
- **The reproduction set is the review's.** "The review's reproduction set" means the crafted inputs
  executed during the 2026-09-17 review: the parameter-injection connection string, an empty body, a
  list-valued connection string, an unparseable address, a connection string with no path, and an
  allowlist written with spaces.

## Out of Scope

- Packaging hygiene (excluding internal tooling directories from the published package).
- Test-runner and static-analysis threshold changes.
- Any filtering, inspection, or rewriting of the report payload itself.
- Support for tunnelling to hosts or projects outside the operator's allowlists.
