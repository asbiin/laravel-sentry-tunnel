<!--
SYNC IMPACT REPORT
==================
Version change: none (unfilled template) → 1.0.0
Bump rationale: MAJOR — initial ratification. The previous file was the unmodified
scaffold with every placeholder intact, so this is the first governing version.

Modified principles:
  - [PRINCIPLE_1_NAME] → I. Security-First Tunneling (NON-NEGOTIABLE)
  - [PRINCIPLE_2_NAME] → II. Minimal Surface, Configuration Over Code
  - [PRINCIPLE_3_NAME] → III. Test-Backed Behavior
  - [PRINCIPLE_4_NAME] → IV. Static Analysis and Style Gates
  - [PRINCIPLE_5_NAME] → V. Compatibility and Semantic Versioning

Added sections:
  - Security & Compatibility Constraints (was [SECTION_2_NAME])
  - Development Workflow & Quality Gates (was [SECTION_3_NAME])

Removed sections: none

Deferred items / follow-up TODOs: none. RATIFICATION_DATE is set to the date of this
first adoption (2026-09-17), not the repository's first commit (2022-04-07), because no
prior version of this constitution was ever in force.
-->

# Laravel Sentry Tunnel Constitution

## Core Principles

### I. Security-First Tunneling (NON-NEGOTIABLE)

This package is a reverse proxy to a third-party host, and every change MUST be evaluated
as such. The tunnel endpoint MUST fail closed: a request whose DSN is absent, unparseable,
or whose host is not in `sentry-tunnel.allowed-hosts` MUST be rejected before any outbound
request is made. Project filtering MUST reject any project id outside
`sentry-tunnel.allowed-projects` when that list is non-empty. Rejections MUST use explicit
HTTP status codes (401 for authorization failures, 422 for malformed input) and MUST NOT
leak the configured allowlists or upstream response bodies to the caller. The shipped
default middleware stack MUST keep the endpoint authenticated; removing `auth` from the
default configuration is a breaking security change requiring a MAJOR release.

**Rationale**: Sentry DSNs are public by design. Without allowlisting and authentication,
this endpoint lets any caller send arbitrary traffic that appears to originate from the
host application's server.

### II. Minimal Surface, Configuration Over Code

The library MUST remain limited to what the Sentry `tunnel` option requires: one route, one
controller action, one service provider, one config file. New behavior MUST be exposed as a
documented configuration key with a safe default rather than as new public API. Every config
key MUST carry an explanatory comment block in `config/sentry-tunnel.php` AND a corresponding
section in `README.md`; a key documented in only one of the two is incomplete. Public classes
and methods are a compatibility commitment — adding one MUST be a deliberate decision, not a
side effect of refactoring.

**Rationale**: A small, auditable surface is what makes Principle I verifiable by reading the
code, and it keeps the support matrix of Principle V affordable to maintain.

### III. Test-Backed Behavior

Every behavioral change MUST ship with PHPUnit tests in the same change set, written against
Orchestra Testbench through `SentryTunnel\Tests\TestCase`. Both the accepting path and each
rejecting path of a security check MUST be covered — a new `abort_if` without a test asserting
its status code is incomplete work. Outbound calls MUST be asserted with `Http::fake()` and
`Http::assertSent()` rather than reaching the network. Coverage is reported to SonarCloud on
every run; a change MUST NOT reduce coverage of `src/` without a stated justification in the
pull request.

**Rationale**: The code paths that matter most here are the refusals, and refusals are exactly
the paths that silently stop working when untested.

### IV. Static Analysis and Style Gates

PHPStan MUST pass at level 5 over `src/` with the larastan, strict-rules, deprecation-rules,
and safe-rule extensions enabled. Psalm MUST pass at `errorLevel="7"`. Laravel Pint MUST report
no diff. These thresholds are floors: they MAY be raised, and MUST NOT be lowered, to make a
change pass. Suppressions (`@phpstan-ignore`, `@psalm-suppress`, `ignoreErrors`) MUST be
narrowly scoped to the specific line and rule, and MUST be introduced only when the rule is
provably wrong about the code — never to defer a real fix.

**Rationale**: The analysis configuration is the project's memory of what "correct" means here;
a lowered threshold silently discards findings across the whole codebase, not just the new line.

### V. Compatibility and Semantic Versioning

The supported matrix is declared in `composer.json` and enforced in CI, and the two MUST stay
in agreement. Dropping a PHP or Laravel version, changing a default configuration value, or
altering the shape of a request or response is a breaking change requiring a MAJOR release.
Releases are produced by semantic-release from Conventional Commits, so commit types and pull
request titles are release inputs, not documentation: `feat!:` or a `BREAKING CHANGE:` footer
MUST accompany any change that violates backward compatibility.

**Rationale**: Downstream applications install this package by constraint. Version numbers that
do not predict breakage push that cost onto every consumer.

## Security & Compatibility Constraints

- **Runtime**: PHP `^8.2`. Laravel/Illuminate `^11.0 || ^12.0 || ^13.0`.
- **Tested matrix**: PHP 8.2, 8.3, 8.4 against Laravel `^11.0`, `^12.0`, `^13.0`, excluding
  Laravel `^13.0` on PHP 8.2. Defaults for single-job checks are PHP 8.4 and Laravel `^13.0`.
- **Outbound traffic**: the only permitted destination is
  `https://{allowed-host}/api/{allowed-project}/envelope/`. No other host, scheme, or path MAY
  be constructed from caller-supplied input.
- **Untrusted input**: the request body and its DSN header are attacker-controlled. They MUST be
  parsed with the `thecodingmachine/safe` wrappers (`Safe\json_decode`, `Safe\parse_url`) so that
  parse failures raise rather than degrade to a falsy value.
- **Secrets**: the package MUST NOT read, log, or forward credentials beyond the public DSN key
  that Sentry itself expects in the query string.
- **Dependencies**: runtime requirements stay limited to `illuminate/*` and
  `thecodingmachine/safe`. Adding a runtime dependency requires justification against
  Principle II. Dependabot proposes weekly updates for Composer and GitHub Actions; security
  updates take priority over feature work.

## Development Workflow & Quality Gates

- **Branching**: `main` is the release branch; `next`, `next-major`, `beta`, and `alpha` are
  recognized by semantic-release. Work reaches `main` through pull requests.
- **Pull request titles** MUST follow Conventional Commits and are validated in CI by
  `amannn/action-semantic-pull-request`.
- **Required checks** on every pull request: Pint lint, static analysis (PHPStan and Psalm),
  and the full PHP × Laravel test matrix. The SonarCloud quality gate MUST pass.
- **Merging**: a pull request MUST NOT merge with a failing or skipped required check. Disabling
  or narrowing a check to obtain a green run is itself a constitution violation.
- **Releasing** is automated. Tags follow the bare `${version}` format and `CHANGELOG.md` is
  generated; neither is edited by hand.
- **Spec-driven changes** use the templates under `.specify/templates/`. Plans MUST include a
  Constitution Check, and any deviation MUST be recorded there with its justification rather
  than left implicit in the diff.

## Governance

This constitution supersedes other conventions for this repository. Where a template, a habit,
or an external style guide conflicts with it, this document wins.

**Amendments**: changes to this file are proposed in a pull request that states the new version,
the bump rationale, and the migration impact on in-flight work. The amendment and the version
line MUST land in the same commit.

**Versioning policy**: this constitution is versioned independently of the package release and
follows semantic versioning. MAJOR — a principle is removed, or redefined in a way that
invalidates previously compliant work. MINOR — a principle or section is added, or existing
guidance is materially expanded. PATCH — clarification, wording, or typo fixes that leave the
obligations unchanged.

**Compliance review**: reviewers verify compliance on every pull request. Principles I, III, and
IV are mechanically checkable in CI and MUST be green before merge. Principles II and V require
human judgment and MUST be addressed in review when a change touches the public surface, the
configuration defaults, or the supported version matrix. Complexity beyond what a principle
allows MUST be justified in the pull request description, not silently introduced. For runtime
development guidance, use `README.md` for the package's documented behavior and
`.specify/templates/plan-template.md` for the planning workflow.

**Version**: 1.0.0 | **Ratified**: 2026-09-17 | **Last Amended**: 2026-09-17
