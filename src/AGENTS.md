# Laravel Application Guidelines

## Scope
- This file applies to `src/` and all its descendants, including application code, configuration, migrations, and tests.
- Follow `../AGENTS.md` for repository-wide rules: Laravel 13, PHP 8.3, PSR-12, SOLID, RESTful APIs, database confirmation, test isolation, Sanctum behavior, and Git approval. This file supplements those rules without weakening them.

## Application Conventions

- Keep API routes in chronological implementation order (oldest first). Append new routes below existing routes within the appropriate middleware/prefix group; preserve required static-before-parameter route precedence when paths could conflict.
- Follow the existing Laravel 13 structure: route and exception setup in `bootstrap/app.php`, API routes in `routes/api.php`, and versioned controllers under `app/Http/Controllers/Api/V1/`.
- Use Form Requests for validation and Eloquent for persistence. Keep controllers focused and avoid unnecessary abstractions.
- Preserve API contracts during unrelated work. Response format or behavior changes must update affected tests, including `HealthCheckTest` exact JSON assertions.
- Consult the root Sanctum section for implemented behavior and future work. An `admin` session guard configuration does not itself enforce administrator identity on `auth:sanctum` routes.

## Environment and Commands
- Use the Docker PHP 8.3 service. From the repository root, use `docker compose exec php php artisan ...` or `docker compose exec php composer ...` only when appropriate for the authorized task.
- Do not automatically install host PHP/Composer, install Boost, change dependencies, or regenerate instructions. These are not prerequisites for code review or document analysis.
- Analysis-only work is read-only. Documentation-only changes require neither new/updated tests nor execution of the application test suite.

## Database and Test Safety
- Before manual data-clearing migrations, database rebuilds, deletion, or volume removal, follow the root rule requiring explicit confirmation of the concrete operation and target.
- Preserve `phpunit.xml` isolation and `tests/TestCase.php` safety checks. Never weaken or bypass them to run tests in another environment or database.
- Database-writing tests must use the verified `testing` environment and dedicated MySQL `laravel_testing` database on `mysql:3306`. Existing `RefreshDatabase` behavior is permitted only within this verified scope when test execution is authorized.
- Resolve uncertainty about configuration, database availability, or permissions before running database-writing tests. Never substitute the development database.
- Functionality or behavior changes require appropriate test updates and relevant test execution when available; report verification limits. Preserve real-token revocation coverage for authentication/logout changes.

## Platform Management

- Follow the root platform contracts: creation-only code, name-only PUT, status-only PATCH, explicit field allowlists, and existing case-sensitive unique index behavior. Do not add platform deletion or implicit permission grants.
- Preserve the Platform Model update persistence guard: code changes (including case-only and quiet saves) throw LogicException; creation and normal name/status updates remain supported. Query Builder/bulk updates and direct SQL are outside its protection.
- Preserve same-connection transactional platform audit writes and matching service/Resource code snapshot filtering. Future external authentication and wallet behavior are outside this scope.

## Player Management

- Follow the root Issue #8 player contracts: independent view/manage permissions, safe unsigned BIGINT handling, explicit Resource fields, query allowlists, case-sensitive external IDs and documented global trimming.
- PATCH accepts only JSON integer status 0/1 with no query parameters. Preserve auth/permission → target lookup → validation → transaction/locked reread ordering. Unknown existing states return 409; no-ops do not save or audit.
- Preserve synchronous same-connection player audit and matching Service/Resource status filters. Do not add migrations, permission grants, identity/login/name edits, wallet/session/integration features or concurrency guarantees.
- Player tests were unexecuted in phase two; authorized phase-four dedicated and safe regression selections passed (64/339 and 543/2263 tests/assertions). Keep root verification limits, testing safety rules, existing-schema preflight and direct Migration/DDL exclusions for later execution.

## Platform JWT Authentication

- Follow root Issue #9 phase-one contracts and docs/platform-jwt-authentication.md: separate platform.jwt middleware/context, external RS256 issuance, reusable 300-second JWTs, strict expiration and independent 30-second iat/nbf checks. Preserve Admin Sanctum/RBAC isolation and trusted-context-only platform identity.
- Issuer bindings are permanent through APIs; rotate by registering a new RSA key pair and kid, and never reactivate revoked keys. Key management uses independent platforms.keys permissions, explicit allowlists, same-connection transactional audit and platform/key locking. Never audit PEM, JWT or private key material.
- New additive JWT schema was applied only to verified laravel_testing. Keep existing-schema regression protections, schema/data fingerprint checks and direct Migration case exclusion; do not infer permission to migrate development/production. Player Resolve is now implemented in its separate phase-two scope below; session, wallet and ingress rate limiter remain unimplemented. Targeted real registration concurrency is tested; general permission/revocation races are not fully verified.

- Enforce globally unique canonical SPKI DER SHA-256 fingerprints through the separately added unique-index migration. Do not change either executed JWT migration. Reject duplicate public keys across platforms/kids even after disable/revocation, with a generic public_key 422; retain exact SQL error discrimination and transactional audit rollback.
- The narrow platform-keys:permission Artisan command is the controlled protected-role permission pathway. Require explicit action/role/permission/actor/ticket, preview by default, active security_admin password authentication, --apply and exact interactive confirmation. Do not create automatic memberships/grants or widen AdminRoleController delegation. See docs/platform-key-permission-operations.md for separately approved initial bootstrap and revoke semantics. Audit permission/ticket as string/null, never credentials.
- Hardening selections passed 125/737 and 680/3032 tests/assertions (overlapping); PSR-12 passed for 27 PHP files. The dual-process tests commit only synthetic laravel_testing fixtures to make them visible across connections, then precisely clean their IDs in finally. Preserve worker pre-bootstrap connection guards, DDL blocking, parent pre/postflight and direct Migration case exclusion. No actual administrator was granted permissions.

## Platform Player Resolve

- This is separately authorized Platform integration scope; preserve all existing Admin Player Management contracts. POST /api/v1/platform/players/resolve uses platform.jwt and trusted Context only. Disabled platforms return the user-approved 403 only after valid signature/claims/time/key; other authentication failures stay 401. Context expiresAt and locked live key/platform reads protect transaction waits/replays.
- Use Form Request/Platform Resource allowlists: only required exact case-sensitive external_user_id without boundary whitespace and optional name, plus UUID Idempotency-Key header. The global TrimStrings/ConvertEmptyStringsToNull exceptions apply only to this POST path. Create active users or read existing active users without updating them; no wallet/session/last-login or Admin auth changes.
- Preserve same-connection atomic player/idempotency completion, unique scoped UUIDs, exact success response bytes/status, 48-hour retention, presence-sensitive canonical hash, current-player checks on replay, platform-first locks and exact expected username-independent player unique-index discrimination. Expired completed records are reclaimed in place; no implicit bulk cleanup or incomplete-record takeover.
- New 000003 migration was applied only to preflight-verified laravel_testing. Keep all executed migrations unchanged, TestCase/phpunit.xml isolation, existing-schema runners, DDL blocking and direct Migration case exclusion. The four new real HTTP-process races commit only synthetic fixtures and precisely remove them in finally; preserve worker guards and postflight checks. No development/production data or real administrator grants were changed.
- Combined dedicated 182/1056 and safe regression 737/3351 tests/assertions passed (overlapping), plus PSR-12 check-only for 38 PHP files. See docs/player-resolve-api.md and docs/testing.md for contracts, limits and the remaining pre-production Symfony/ingress/capacity work.


## Platform Resolve Operations (Issue #9 final hardening)

- Resolve middleware order is platform.jwt → platform.resolve.limit → validation/business logic. The limiter uses verified Context platformId only, defaults to 120 admissions per fixed 60 seconds, and permits explicit deployment overrides. Invalid JWTs fail before admission; replays/validation failures consume admitted quota. 429 and limiter-unavailable 503 do not execute player/idempotency writes. Preserve Admin/default limiter behavior.
- Use the dedicated platform_api_limiter Laravel DatabaseStore on the shared default MySQL writer and platform_api_rate_limits table. Framework RateLimiter check/hit is serialized by a platform row lock on the same transaction/connection, without a leased cache mutex. Reject unsupported/local stores and storage failures; never fall back to unlimited admission. All replicas require the same primary/database/prefix/configuration; production topology and ingress abuse controls still require deployment verification.
- Only new additive 000004 creates the limiter table; it was applied only to verified laravel_testing. Do not modify any executed migration. Pruning preserves the 48-hour API guarantee, deleting only completed records expired at a fixed database UTC cutoff, with exact bounded platform-first SKIP LOCKED batches. platform:prune-idempotencies defaults to dry run; manual --apply requires concrete environment/data/recovery approval and exact interactive database confirmation. Hourly scheduling is disabled unless PLATFORM_IDEMPOTENCY_PRUNE_ENABLED explicitly approves the documented recurring policy. No live cleanup/scheduler activation is authorized by implementation alone.
- Security-admin bootstrap immediately inherits ALL existing role permissions. Review the complete mappings, current members, target effective permission union and actual affected people before explicit approval; do not describe membership bootstrap as a zero-permission operation or relax delegation. No automatic grants or privileged administrator creation.
- See docs/platform-api-operations.md and docs/testing.md for current behavior/results; this section supersedes earlier historical statements that rate limiting or physical pruning is unimplemented. Preserve TestCase/phpunit.xml, existing-schema preflight/DDL guards, precise fixture/counter cleanup and postflight fingerprints. Symfony advisory remediation remains separate pre-production work.
- Final operations verification: dedicated 203 tests/1200 assertions; safe regression 758 tests/3495 assertions (overlapping); zero failures/errors/risky tests. PSR-12 check-only passed for 49 PHP files. Two new independent-process scenarios verify a shared one-request limit and pruning that skips an active Resolve while preserving its refreshed replay. No live cleanup, scheduler activation or actual RBAC grant was performed.
