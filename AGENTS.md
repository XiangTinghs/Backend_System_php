# Project Development Guidelines

## Scope and Instruction Files
- This file applies to the entire repository. `src/AGENTS.md` supplements it for `src/` and its descendants without weakening safety rules.
- Keep both files consistent. Explicit user instructions define the authorized scope; clarify unresolved safety conflicts before taking the affected action.
- Analysis-only work is read-only: do not install dependencies, generate files, or write application state.

## Technology Stack
- PHP 8.3
- Laravel 13
- MySQL 8.4
- Nginx
- Docker / Docker Compose
- PHPUnit / Laravel Feature Tests

## Project Structure
- `src/` contains the Laravel application.
- `docker/` contains Docker configurations.
- `docker-compose.yml` defines the development services.

## Coding Standards
- Follow PSR-12 coding standards.
- Follow SOLID principles where appropriate.
- Use Laravel conventions and best practices.
- Keep controllers lightweight.
- Use Form Requests for input validation.
- Prefer clear, maintainable code over unnecessary abstractions.
- Use Laravel Pint in check-only mode for reviews; use its PSR-12 preset when checking this standard. Limit formatting changes to the authorized task.

## API Development

- Keep API routes in chronological implementation order (oldest first). Append new routes below existing routes within the appropriate middleware/prefix group; preserve required static-before-parameter route precedence when paths could conflict.
- Follow RESTful API design principles.
- Use consistent JSON response structures.
- Implement proper HTTP status codes.
- Validate incoming request data.
- Do not expose sensitive information in API responses.
- Treat response fields, nesting, messages, and HTTP status codes as contracts. Response format or behavior changes must update all affected tests in the same change, including exact JSON assertions.
- Existing formats differ: health uses `success` and `data`, login/profile and administrator detail/create/update use `data`, administrator lists use `data`, `links`, and `meta`, and logout uses `message`. Uniform responses are future work; preserve existing contracts during unrelated tasks.

## Database
- Use Laravel migrations for schema changes.
- Use Eloquent ORM where appropriate.
- Avoid unnecessary database queries.
- Preserve existing data when modifying database structures.
- Do not modify already-executed migrations without explicit authorization; use separately authorized migrations for later schema changes.
- Before executing any operation that may clear, delete, rebuild, or irreversibly alter data, explain the target environment/database, affected data, and recovery approach, then obtain explicit user confirmation for that concrete operation. General implementation permission does not authorize data resets.
- This includes destructive pending migrations executed through `migrate`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, destructive rollbacks, `db:wipe`, database/table drops, bulk deletion, truncation, destructive seeders, and Docker database volume deletion such as `docker compose down -v`.
- Review pending migrations before execution. Never use `--force` to bypass confirmation or safety checks, or reset development data to make tests pass.
- Authorized tests may reset only the verified dedicated testing database through existing lifecycle behavior such as `RefreshDatabase`. Manual destructive commands still require explicit confirmation.

## Testing
- Write Feature Tests for new API endpoints.
- Include both successful and failure scenarios.
- Use the dedicated testing database.
- Do not modify or reset the development database during tests.
- Preserve `src/phpunit.xml` isolation: the testing environment, forced MySQL connection, host `mysql`, port `3306`, database `laravel_testing`, and empty forced `DB_URL`.
- Do not remove, weaken, bypass, or relocate `src/tests/TestCase.php` checks requiring the `testing` environment, MySQL, and `laravel_testing`. Do not bypass them by changing test inheritance.
- Verify effective configuration before database-writing tests, including cached configuration. Do not assume post-bootstrap checks alone prevent all lifecycle writes.
- The testing database and permissions must exist before tests run. Compose initializes only `laravel`; report missing test prerequisites and obtain setup authorization instead of substituting the development database or SQLite.
- Only functionality or behavior changes require new or updated tests. Documentation-only changes do not require new/updated tests or execution of the application test suite.

## Docker Development Environment
- Use the existing Docker PHP 8.3 service for PHP, Composer, Artisan, and PHPUnit. Missing host tools do not justify installing host PHP/Composer or switching versions.
- Run commands from the repository root, for example `docker compose exec php php artisan test` or `docker compose exec php vendor/bin/pint --test --preset=psr12`.
- If containers or dependencies are unavailable, report the limitation. Do not automatically install Boost, change dependencies, or regenerate instruction files as a prerequisite to ordinary work.
- Environment setup and dependency installation must be within the authorized task. Review Composer scripts before execution: `composer setup` includes migrations and other writes.

## Development Workflow
1. Analyze existing code before making changes.
2. Explain the proposed implementation.
3. Make small, focused changes.
4. Add or update automated tests for functionality or behavior changes; documentation-only work is exempt.
5. Run relevant tests for functionality or behavior changes when the environment is available and isolation is verified. Report checks not run and why.
6. Review changes before Git Commit.
7. Do not automatically commit or push changes without approval.

## Security
- Never commit `.env` files or real credentials. Existing local example credentials must not be reused in deployed environments; inject real credentials through environment configuration.
- Deliver plaintext Sanctum Tokens only in the intended successful login response. Never expose live tokens in logs, Git, errors, unrelated responses, or documentation examples.
- Apply authentication and authorization checks to protected endpoints.
- Follow secure password storage practices.

## Sanctum Authentication and Logout

### Implemented Behavior
- `POST /api/v1/admin/login` validates credentials and active status, then creates a token named `admin-api`. Invalid credentials and inactive accounts return 401.
- Profile and logout use `auth:sanctum` followed by `admin.token`, which requires an Admin identity and a persisted Sanctum Bearer Token and rejects session/TransientToken authentication (401). Profile additionally uses `admin.active` (403 for disabled accounts).
- Logout deletes only `currentAccessToken()`, preserving other devices' tokens. Existing tests cover logout, revoked-token rejection, and preservation of another device's token.
- Sanctum expiration is currently `null`, and login sets no explicit expiry. Expiration and refresh tokens are not implemented features.
- Logout shares administrator token validation but does not use `admin.active` or permission checks. Disabled accounts may revoke their current token; preserve this exception.

### Rules for Authentication Changes
- Use Sanctum validation for protected token endpoints; do not introduce custom token-string comparisons or bypass production authentication.
- When changing token authentication, cover missing, invalid, and revoked bearer tokens with 401 tests. Define authorization and Admin/active-account policy separately for each affected endpoint.
- Preserve current-token-only logout unless an authorized feature explicitly introduces all-device revocation. Relevant changes must verify persisted token deletion, rejection of revoked tokens, and continued access with another device's token.
- Use real persisted tokens for revocation/logout tests; `Sanctum::actingAs()` alone does not verify deletion. Clear cached guards between requests when verifying revocation, as existing tests do.
- Distinguish session authentication from bearer-token logout: not every Sanctum-authenticated identity has a deletable personal access token.

### Future Work, Not Implemented Guarantees
- Administrator deletion, role/permission definition management APIs, complete system-wide last-administrator protection, Sanctum token ability enforcement, token expiry/rotation, refresh tokens, and all-device logout are not implemented. Real two-connection concurrency testing is not complete. Do not describe these as supported or verified guarantees.
- Update this status when code and tests change. Do not implement planned features merely to make code match this document.

## Implemented Administrator Management and MVP RBAC
- Administrator List, Detail, Create, and Update are implemented under `/api/v1/admin/admins`. DELETE is not implemented.
- Management routes use `auth:sanctum` → `admin.token` → `admin.active` → `admin.permission`. List/detail require `admins.read`, create requires `admins.create`, and update requires `admins.update`.
- Reuse `AdminResource`: expose only `id`, `username`, `name`, `is_active`, `created_at`, and `updated_at`. Create returns 201 with Location; list/detail/update return 200. Invalid/missing target IDs return a safe 404.
- List pagination defaults to 20 with a maximum of 100. Preserve sort allowlists, deterministic ordering, grouped username/name search, literal LIKE escaping, and active-status filtering.
- Create/update accept only their explicit field allowlists. PUT requires username, name, and active status; omitted passwords remain unchanged. Reject role/permission injection and self-update (403). Use the existing password hashed cast; only username unique-key conflicts become 422, and unrelated database exceptions must propagate.
- Creating an administrator grants neither roles nor tokens. Treat password/status update authority as sensitive; it must not imply role-assignment authority.
- RBAC uses only `admins`, `admin_roles`, and `roles_permissions` as its core tables. Do not introduce standalone role/permission definition tables or third-party permission packages within the MVP scope.
- `admin_roles` has unique `(admin_id, role)` and an `admins.id` foreign key with delete cascade. `roles_permissions` has unique `(role, permission)`. Both mapping tables omit IDs/timestamps and share role type/collation. Shared role-permission definitions must not be deleted with an individual administrator.
- `Admin::hasPermission()` uses a Query Builder JOIN and `exists()` for multi-role union semantics without permission caches or token snapshots. `admin.permission` accepts a single valid lowercase code and rejects missing/invalid/ungranted permissions with 403.
- Role listing, assignment, and revocation APIs are implemented; role/permission definition management APIs remain unimplemented. String code consistency requires application validation; do not assume a database definition registry exists.

## Implemented Role Assignment Safety and Contracts
- GET `/api/v1/admin/admins/{id}/roles` requires `admins.roles.read`, including for yourself; returns 200 with `data.admin_id` and ascending `data.roles` (empty array when unassigned).
- POST on the same path requires `admins.roles.assign`; accepts only `role` and returns 201 for a new assignment or 200 for an existing assignment, with `data.admin_id` and `data.role`.
- DELETE `/api/v1/admin/admins/{id}/roles/{role}` requires `admins.roles.revoke`; accepts no input fields/query parameters (absent or empty JSON body allowed) and returns 204 without a body. It deletes only the exact assignment and preserves shared permissions and tokens.
- Preserve the middleware order `auth:sanctum` → `admin.token` → `admin.active` → `admin.permission`. Authentication/endpoint authorization precedes target lookup; invalid or missing targets then return safe 404 before body/path-role validation. POST/DELETE resolve Form Requests inside the transaction after the locked target lookup; do not introduce earlier validation or duplicate target queries incidentally.
- POST role validation uses lowercase ASCII codes starting with a letter, containing letters/digits/underscores, at most 50 characters; rejects extra fields and unknown roles with 422. DELETE unknown/invalid roles or missing assignments return 404; invalid body returns 422. Policy failures return 403; missing POST role permission configuration returns 422, while DELETE conservatively returns 403.
- `config/rbac.php` currently knows `viewer`, `manager`, and protected `security_admin`; only `viewer`/`manager` are assignable and revocable. Configuration does not populate database records. Protected roles take precedence over candidate lists, and self-assignment/revocation is forbidden.
- Both mutations require explicit, non-empty role permission configuration. All permissions must be in `delegable_permissions` (currently only `admins.read`) and held by the operator. Reject sensitive or unreviewed capabilities; do not relax policy or create missing configuration to make a request succeed.
- Preserve ordered operator/target administrator locks, transaction-local authorization checks, shared role-permission locks, exact unique-key competition handling, and propagation of unrelated SQL errors. DELETE additionally locks the assignment and requires exactly one deleted row.
- Role changes use live queries and apply on the next request without replacing or revoking tokens. Role assignment is not definition CRUD. Future configuration writers must use compatible synchronization and authorization policies; current locks do not guarantee safety for arbitrary external writes or later permission changes.
- Existing single-connection race simulation is not a real concurrent transaction test. Do not claim full TOCTOU coverage, complete last-administrator protection, or successful two-connection testing. Add those protections and tests in separately scoped work.

## Implemented Audit Logging and Query Contracts
- Administrator create/update and role assignment/revocation write successful-change audit events: `admin.created`, `admin.updated`, `admin.enabled`, `admin.disabled`, `admin.password_changed`, `admin.role_assigned`, and `admin.role_revoked`. Target type is `admin`; capture the operator, target ID, and Request IP.
- Creation records username/name/active status with null old values. Updates record only changed username/name fields, separate active-status transitions, and a password-change event with both snapshots null. Role events record actual before/after persisted role-code lists in ascending order. No-op updates, duplicate assignments, failed operations, and GET queries must not produce success events.
- Keep business writes and all corresponding audit writes synchronous on the same Connection inside the controller's transaction. Preserve target/role locks and transaction-local snapshots. Pass the connection name to `AuditLogService::record()`; the service must not start/commit transactions, queue writes, use afterCommit, or swallow errors. Audit failures must roll back business data and earlier events. Do not classify audit SQL failures as business username/assignment conflicts.
- Service and Resource snapshot allowlists must remain consistent: `username`, `name`, `role`, `code` accept strings; `is_active` accepts booleans; `roles` accepts lists of strings; each allows null. Reject other fields and unexpected nested values. Never serialize complete Requests/Models or record passwords, hashes, password metadata, tokens, secrets, or authorization data. The allowlist does not detect secrets embedded inside otherwise allowed strings; callers must supply only intended business values.
- GET `/api/v1/admin/audit-logs` uses `auth:sanctum` → `admin.token` → `admin.active` → `admin.permission:audit-logs.view`. Do not invent an `admin.permission.view` alias. No role is automatically granted `audit-logs.view`; do not populate production permissions or add automatic grants incidentally. Preserve authentication/permission checks before query validation.
- Query filters are AND-combined: positive unsigned BIGINT `admin_id`/`target_id` (maximum `18446744073709551615`, no existence requirement), exact nonempty `action`/`target_type` strings (50/100 characters), UTC `date_from`/`date_to`, and pagination. Reject unknown parameters including sort/direction with 422.
- Validate strict real YYYY-MM-DD dates with calendar checks and safe fixed-width comparisons, bounded inclusively by `1000-01-01` and `9999-12-30`. Compare ordering only after both dates are valid. Support either date alone; include start midnight and exclude midnight after date_to. Preserve UTC conversion and avoid next-day overflow. Query bounds do not expand MySQL TIMESTAMP storage limits.
- Page is 1–10000 (default 1); per_page is 1–100 (default 20). Preserve fixed `created_at DESC, id DESC` sorting and Resource `data`, `links`, `meta` responses. Output only id/operator/action/target IDs and type, filtered snapshots, IP, and UTC created_at; do not expose related administrator/token models.
- Laravel timezone is UTC and the MySQL connection explicitly uses `+00:00`. Verify the effective Session timezone and Connection, not only CLI settings. Do not change Global timezone or container timezone as a substitute.
- Preserve audit history: no cascading deletion foreign keys, no updated_at, and no audit mutation API/business methods. This is not database-enforced immutability. Retention/archival, broad API/authentication logging, export, real dual-connection tests, and full last-administrator protection are not implemented guarantees.
- Audit tests must retain the existing phpunit.xml/TestCase protections and use only verified testing/MySQL/mysql:3306/laravel_testing. Verify effective uncached configuration and required schema before writes. A task prohibiting migrations also prohibits implicit RefreshDatabase schema rebuilds; report the existing-schema execution approach without weakening isolation checks. Never reset development data or modify executed migrations to make tests pass.

## Implemented Platform Management Contracts

- Platform endpoints under `/api/v1/admin/platforms` implement GET list/detail, POST create, PUT name-only update, and PATCH `{id}/status`; no DELETE API. Middleware order is `auth:sanctum` → `admin.token` → `admin.active` → `admin.permission`.
- GET requires `platforms.view`; all writes require `platforms.manage`. Neither implies the other. Do not automatically grant permissions or expand role delegation policy.
- Resource fields are only id, code, name, is_active, created_at, updated_at. List returns data/links/meta; single objects return data. POST returns 201 with Location; others return 200. Invalid/missing IDs return safe 404 after auth/permission checks; PUT/PATCH target lookup precedes body validation.
- Code is immutable through APIs and Platform Model instance saves after creation. POST accepts ASCII letters/digits/dot/underscore/hyphen, 1–50 characters, preserves case, and uses the existing `utf8mb4_0900_as_cs` unique column. Translate only the expected `platforms_code_unique` business INSERT conflict (1062) to 422; audit/unrelated SQL failures propagate.
- POST requires code/name, optionally is_active (default true). PUT accepts only required trimmed nonblank name (max 100), rejecting code even when unchanged and rejecting is_active. PATCH accepts only required boolean is_active. Reject unknown write input and unknown list query parameters; never mass-assign update payloads. Platform::performUpdate() rejects dirty code with LogicException, including case-only changes and quiet saves/updates, before SQL writes. Keep code fillable for creation. Identical Model code reassignment is allowed, but update APIs reject any code field. Query Builder/bulk Eloquent builder updates and direct SQL are outside this protection; do not claim database-enforced immutability.
- List page is 1–10000, per_page 1–100 (default 20); search groups code/name with literal LIKE escaping; status combines with AND. Sort allowlist: id/code/name/created_at/updated_at; default id desc, with ID tie-breakers for non-unique fields.
- Preserve players, their status/relationships and administrator tokens when changing platform status. External login/wallet status enforcement is future work.
- Audit events: platform.created (null → code/name/is_active), platform.updated (changed name only), platform.enabled/platform.disabled (is_active only), target_type platform. Record actor/target/IP synchronously on the same Connection and transaction. Updates lock the target before snapshots; failures roll back business data. GET/rejected/no-op operations create no success audit.
- Audit service and Resource accept code as string/null in addition to existing snapshot fields; preserve type filtering and sensitive-data exclusions. No migrations are needed for these APIs. Real two-connection concurrency verification and database-enforced code immutability remain unimplemented.
- Verification on 2026-11-04: Platform Model selection 18 tests/64 assertions; Feature/Audit selection 189 tests/944 assertions; regression selection 487 tests/1965 assertions, zero failures. Selections overlap. The direct Migration-invoking UserModelTest case was excluded by explicit user instruction; do not describe it as executed. Existing-schema regression used a temporary runner with preflight and RefreshDatabaseState::$migrated set, preserving transaction isolation without schema rebuilds. See README for scope and limits.

## Implemented Player Management (Issue #8)

- GET list/detail and status-only PATCH under `/api/v1/admin/players` use `auth:sanctum` → `admin.token` → `admin.active` → `admin.permission`. GET requires `players.view`; PATCH requires independent `players.manage`. No automatic grants or delegation expansion.
- Resources expose only id/platform_id/external_user_id/name/status/last_login_at/created_at/updated_at, with UTC timestamps. Preserve unsigned BIGINT IDs without integer truncation; player IDs above PHP_INT_MAX serialize as decimal strings. Audit target IDs additionally accept validated decimal strings; other identity typing remains unchanged.
- List uses exact case-sensitive external ID filtering, grouped literal substring search, AND filters, fixed sort allowlist and ID tie-breakers. Pagination limits match Platform. Unknown query parameters are rejected. Global trimming/empty-to-null remains part of the contract; external IDs are not lowercased.
- PATCH requires only JSON integer status 0/1 and no query parameters. Auth/permission precedes target existence, then body validation, then same-connection transaction/locked reread. Missing targets return safe 404. Existing status 2–255 returns 409 unchanged; reads preserve those values. No-op returns 200 without save, timestamp change or audit.
- Status writes synchronously audit player.enabled/player.disabled, target_type player, integer 0/1 snapshots, operator/target/IP on the business connection and transaction. Service and Resource status allowlists exclude null, booleans, floats, strings, out-of-range integers and nested values. Audit failure rolls back the business write.
- No migrations, automatic permission data, player creation/deletion, identity/name/login edits, session, wallet or external platform integration. Disabled-platform players remain manageable. Row locks are not proof of full concurrency protection.
- Phase two wrote tests without execution. Phase-four verification on 2026-11-09 passed the dedicated selection (64 tests/339 assertions) and safe regression selection (543 tests/2263 assertions), zero failures/errors/risky tests; selections overlap. The direct Migration-invoking UserModelTest case remained excluded. Temporary runner preflight, DDL blocking and transaction rollback preserved schema/migration/persistent-row fingerprints; AUTO_INCREMENT counters were excluded. Preserve these safety checks for future runs. Large-ID tests cover serialization/bindings/audit persistence, not a full status mutation on a stored player above PHP_INT_MAX.

## Implemented Platform JWT Authentication (Issue #9, Phase One)

- Contract v1.1 uses external RS256 issuance and reusable JWTs, maximum `exp - iat` 300 seconds; strict `exp > now`, at most 30 seconds future skew for both iat/nbf. Required exact iss/sub/string audience and integer time claims are checked independently. Only alg/typ/kid headers are accepted; no remote/embedded key discovery or single-use replay storage.
- `platform.jwt` independently validates each request through `PlatformJwtVerifier` and firebase/php-jwt. It queries live platform/issuer/key state and sets immutable `PlatformContext` only after verification. Context platformId is authoritative, never request body identity. Admin Sanctum/RBAC remains separate. Phase two now adds the protected Player Resolve route; tests also retain a temporary route for isolated JWT checks.
- New additive migration creates platform_jwt_issuers (platform PK, globally unique case-sensitive issuer) and platform_jwt_keys (unique platform/kid, public PEM, fingerprint, active/validity/revocation metadata). First registration binds the issuer permanently; rotation adds a genuinely new RSA key pair and different kid, and API revocation is permanent. No private keys, issuer/key deletion or key material edits. Only the JWT migrations were applied to verified laravel_testing; no development/production migration was run.
- Nested Admin key list/detail/register/status/revoke APIs require independent platforms.keys.view/register/manage/revoke permissions with the existing middleware order. No automatic grants or delegation expansion. Same-connection transactions and ordered platform/key locks preserve audit rollback. Success events are platform.key_registered/enabled/disabled/revoked, target_type platform_key. Audit Service/Resource both accept issuer/kid/fingerprint/valid_from/valid_until/revoked_at/permission/ticket as string/null, excluding PEM/JWT/private key material.
- Preserve tests' isolation, existing-schema preflight, DDL blocking, transaction rollback and persistent row/schema fingerprints; exclude the direct Migration-invoking UserModelTest case. Player Resolve is implemented in phase two below; sessions, wallets and ingress rate limiting remain unimplemented. Targeted real two-process registration races are tested; general revocation/permission concurrency remains unverified. See docs/platform-jwt-authentication.md and docs/testing.md for complete API contracts, verification and limits.
- Verification on 2026-11-09: dedicated selection 88 tests/389 assertions; safe regression 643 tests/2684 assertions, zero failures/errors/risky tests. Selections overlap; direct Migration-invoking UserModelTest was excluded. PSR-12 check-only passed for 19 PHP files; schema/migration/persistent-row fingerprints matched after tests (AUTO_INCREMENT excluded).

- Security hardening: RsaPublicKey normalizes OpenSSL SPKI DER before SHA-256 hashing. New migration 2026_11_09_000002 adds global platform_jwt_keys_fingerprint_unique without changing the already-executed first JWT migration. Existing noncanonical/duplicate fingerprints cause migration refusal without row changes. Fingerprints remain unique across all platforms/kids and revoked/disabled keys; exact business INSERT conflict returns generic public_key 422, never another platform's details. Rotate with a new key pair; revoked key restoration is unsupported.
- Controlled platform-keys:permission command requires explicit grant/revoke, protected role, one of four platform key permissions, active security_admin actor, change ticket, --apply, hidden password and exact interactive confirmation; default is read-only preview. It creates no role memberships and does not relax normal delegation. Existing protected memberships require separately approved initial infrastructure provisioning, documented in docs/platform-key-permission-operations.md; no live administrator was granted permissions in this task. Mapping and success audit changes are synchronous on one transaction/connection; no-ops and failed operations have no success event. Revoke removes only the specified role mapping, preserving multi-role union semantics.
- Hardening verification on 2026-11-09: dedicated 125 tests/737 assertions; safe regression 680 tests/3032 assertions, zero failures/errors/risky tests. Selections overlap. Real same-platform and cross-platform registration races use independent PHP/HTTP/MySQL processes with deliberate transaction barriers; only synthetic testing fixtures are committed then precisely removed in finally. Pre/postflight persistent rows/schema/migrations matched (AUTO_INCREMENT excluded). Preserve those fixture cleanup/isolation safeguards; no direct Migration up/down/rollback case was run. PSR-12 check-only passed for 27 PHP files. Symfony advisories remain a formal pre-production task; no dependency upgrade was made during hardening.

## Implemented Platform Player Resolve (Issue #9, Phase Two)

- POST /api/v1/platform/players/resolve uses platform.jwt only, never Admin Sanctum. The user-approved updated JWT contract verifies signature/claims/time/key before returning 403 for a disabled platform; invalid/expired credentials or unusable keys stay 401. Context includes verified expiresAt; Resolve rereads platform/key under locks and rechecks JWT/key expiry after database waits before completing/replaying results. Same JWT reuse remains allowed.
- Resolve accepts only JSON external_user_id (required string 1–100, case-sensitive, rejects boundary whitespace) and optional name (string/null, max 100). TrimStrings/ConvertEmptyStringsToNull skip only this POST path; all Admin contracts retain their prior normalization. Reject unknown fields/query parameters and missing/invalid UUID Idempotency-Key. Platform identity comes only from verified Context.
- Create users with status 1/null last_login_at (201); existing active users are read-only (200). Resolve never changes existing name/status/login/timestamps. Any player status other than 1 returns 403. Platform Resource exposes only id/external_user_id/name/status with safe unsigned ID serialization. No session, wallet, tokens, automatic roles/permissions or extra success audit events are created.
- New additive 000003 migration creates platform_api_idempotencies, unique (platform_id, endpoint, idempotency_key), with request hash, processing/completed state, player FK, exact response status/JSON and UTC expiry. Both player and result commit together on the User connection. Fixed-order hashing preserves name presence/null/empty distinctions and exact strings; field order/JSON encoding formatting and UUID case are normalized. Replay retains original 200/201/body only after current JWT/platform/key/player checks. Different retained content returns 409. Errors are not cached as successes.
- Lock order is platform -> key -> idempotency -> player; resolves deliberately serialize per platform. Only exact users_platform_id_external_user_id_unique 1062 INSERT conflicts are reread; unrelated SQL/persistence errors propagate and roll back. Retention is 48 hours; replay does not extend it. Expired completed rows are reclaimed in place transactionally, not automatically deleted. A committed processing row is an invariant violation requiring investigation, not automatic takeover. Capacity/pruning needs separately authorized operations; no destructive cleanup was run.
- Only the new migration was applied to verified laravel_testing, preserving existing rows/schema; no executed migration was edited. Keep TestCase/phpunit.xml guards, existing-schema preflight, DDL blocking, transaction rollback, concurrency fixture cleanup and schema/migration/persistent-row fingerprints (AUTO_INCREMENT excluded). Never execute the direct UserModelTest Migration case without separate authorization.
- Phase-two verification on 2026-11-09: combined first/second-phase dedicated selection 182 tests/1056 assertions; safe regression 737 tests/3351 assertions; zero failures/errors/risky tests. Selections overlap. Four real independent HTTP/MySQL Resolve races cover distinct keys, exact replay, conflicting body and discarded committed response; the two phase-one key-registration races remain covered. PSR-12 check-only passed for all 38 PHP files. Large-ID coverage is serialization only, not a full stored large-ID Resolve flow. General configuration/permission races, automatic pruning, ingress controls and Symfony advisory remediation remain limits. See docs/player-resolve-api.md and docs/testing.md.


## Platform Resolve Operations (Issue #9 final hardening)

- Resolve middleware order is platform.jwt → platform.resolve.limit → validation/business logic. The limiter uses verified Context platformId only, defaults to 120 admissions per fixed 60 seconds, and permits explicit deployment overrides. Invalid JWTs fail before admission; replays/validation failures consume admitted quota. 429 and limiter-unavailable 503 do not execute player/idempotency writes. Preserve Admin/default limiter behavior.
- Use the dedicated platform_api_limiter Laravel DatabaseStore on the shared default MySQL writer and platform_api_rate_limits table. Framework RateLimiter check/hit is serialized by a platform row lock on the same transaction/connection, without a leased cache mutex. Reject unsupported/local stores and storage failures; never fall back to unlimited admission. All replicas require the same primary/database/prefix/configuration; production topology and ingress abuse controls still require deployment verification.
- Only new additive 000004 creates the limiter table; it was applied only to verified laravel_testing. Do not modify any executed migration. Pruning preserves the 48-hour API guarantee, deleting only completed records expired at a fixed database UTC cutoff, with exact bounded platform-first SKIP LOCKED batches. platform:prune-idempotencies defaults to dry run; manual --apply requires concrete environment/data/recovery approval and exact interactive database confirmation. Hourly scheduling is disabled unless PLATFORM_IDEMPOTENCY_PRUNE_ENABLED explicitly approves the documented recurring policy. No live cleanup/scheduler activation is authorized by implementation alone.
- Security-admin bootstrap immediately inherits ALL existing role permissions. Review the complete mappings, current members, target effective permission union and actual affected people before explicit approval; do not describe membership bootstrap as a zero-permission operation or relax delegation. No automatic grants or privileged administrator creation.
- See docs/platform-api-operations.md and docs/testing.md for current behavior/results; this section supersedes earlier historical statements that rate limiting or physical pruning is unimplemented. Preserve TestCase/phpunit.xml, existing-schema preflight/DDL guards, precise fixture/counter cleanup and postflight fingerprints. Symfony advisory remediation remains separate pre-production work.
- Final operations verification: dedicated 203 tests/1200 assertions; safe regression 758 tests/3495 assertions (overlapping); zero failures/errors/risky tests. PSR-12 check-only passed for 49 PHP files. Two new independent-process scenarios verify a shared one-request limit and pruning that skips an active Resolve while preserving its refreshed replay. No live cleanup, scheduler activation or actual RBAC grant was performed.
