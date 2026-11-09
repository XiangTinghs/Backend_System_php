# Backend System PHP

A backend management system built with **Laravel 13, PHP, MySQL, and Docker**, designed to simulate a real-world internal enterprise management platform.

This project focuses on RESTful API development, database operations, authentication, authorization, and automated testing, with an emphasis on maintainability, scalability, and clean application architecture.

## Tech Stack

| Category | Technology |
|---|---|
| Backend | PHP 8.3 / Laravel 13 |
| Database | MySQL 8.4 |
| Web Server | Nginx |
| Containerization | Docker / Docker Compose |
| API | RESTful API |
| Authentication | Token-based Authentication |
| Testing | Laravel Feature Tests |
| Version Control | Git / GitHub |

## Project Architecture

The project uses Docker Compose to manage the local development environment, separating the Laravel application source code from the Docker infrastructure configuration.

```text
Backend_System_php/
├── docker/
│   ├── nginx/
│   │   └── default.conf       # Nginx Configuration
│   └── php/
│       └── Dockerfile        # PHP Container Configuration
├── src/                      # Laravel Application
│   ├── app/                  # Application Logic
│   ├── bootstrap/            # Application Bootstrap
│   ├── config/               # Application Configuration
│   ├── database/             # Migrations / Seeders
│   ├── routes/               # Route Definitions
│   └── tests/                # Automated Tests
├── .gitignore
├── docker-compose.yml        # Docker Compose Services
└── README.md                 # Project Documentation
```

### Application Design

The Laravel application follows a separation-of-concerns approach, with responsibilities organized across framework components.

- **Controller**: Handles incoming HTTP requests and returns API responses.
- **Form Request**: Validates incoming API request data.
- **Middleware**: Handles authentication and access control.
- **Model**: Manages database interactions using Eloquent ORM.
- **Migration**: Defines and maintains database schemas.
- **Feature Tests**: Verify API behavior, validation rules, and expected responses.

## Features

### Implemented

- [x] Laravel project initialization
- [x] Docker-based development environment
- [x] MySQL database integration
- [x] Administrator account database structure
- [x] Administrator login API
- [x] Token-based authentication
- [x] Request validation
- [x] Automated feature tests
- [x] Administrator List, Detail, Create, and Update APIs
- [x] Shared administrator token and active-account middleware
- [x] MVP RBAC schema, permission queries, and permission middleware
- [x] Administrator role listing, assignment, and revocation APIs
- [x] Transactional administrator/role audit logging and permission-protected Audit Log List API
- [x] Administrator platform list/detail/create/rename/status APIs with RBAC and transactional audit logging

### Planned

- [ ] Delete Administrator API
- [ ] Role definition management API
- [ ] Role permission management API
- [ ] Broader API activity and authentication-event logging
- [ ] Redis caching integration
- [ ] OpenAPI specification and interactive API documentation
- [ ] CI/CD pipeline

## Administrator APIs

The following endpoints are implemented. Administrator management endpoints require middleware in this order: `auth:sanctum`, `admin.token`, `admin.active`, and `admin.permission:<permission>`.

| Method | Endpoint | Permission |
|---|---|---|
| POST | `/api/v1/admin/login` | Public; credentials and active status required |
| GET | `/api/v1/admin/me` | Administrator Bearer Token and active account; no RBAC permission |
| POST | `/api/v1/admin/logout` | Administrator Bearer Token; disabled accounts may log out |
| GET | `/api/v1/admin/admins` | `admins.read` |
| GET | `/api/v1/admin/admins/{id}` | `admins.read` |
| POST | `/api/v1/admin/admins` | `admins.create` |
| PUT | `/api/v1/admin/admins/{id}` | `admins.update` |
| GET | `/api/v1/admin/admins/{id}/roles` | `admins.roles.read` |
| POST | `/api/v1/admin/admins/{id}/roles` | `admins.roles.assign` |
| DELETE | `/api/v1/admin/admins/{id}/roles/{role}` | `admins.roles.revoke` |
| GET | `/api/v1/admin/audit-logs` | `audit-logs.view` |

`DELETE /api/v1/admin/admins/{id}` is **not implemented**. Role and permission definition management APIs are also **not implemented**. Revoking a role assignment does not delete the administrator or the role's permission configuration.

### List and Detail

The list supports `page`, `per_page` (default 20, maximum 100), `search` across username/name, and `is_active` filtering. Sorting accepts `id`, `username`, `name`, `created_at`, or `updated_at`, with `asc` or `desc`; the default is `id desc`. Non-unique sort fields use `id` as a tie-breaker. Search treats `%` and `_` as literal characters.

Lists return `data`, `links`, and `meta`. Detail responses return `data`. Both return HTTP 200 and expose only `id`, `username`, `name`, `is_active`, `created_at`, and `updated_at`. Invalid or missing detail IDs return HTTP 404 without internal model names.

### Create and Update

Create requires `username`, `name`, `password`, `password_confirmation`, and `is_active`, and returns HTTP 201 with `data` and a `Location` header. PUT requires all three basic fields (`username`, `name`, `is_active`); password is optional and remains unchanged when omitted. Updates return HTTP 200 with `data`.

Usernames must be unique, at most 50 characters, and contain only ASCII letters, digits, `.`, `_`, or `-`. Names are trimmed, must not be blank, and are limited to 100 characters. Supplied passwords require confirmation, at least 12 characters, no null bytes, and no more than 72 bytes. Password hashing uses the existing Model cast. `is_active` is required and validated as boolean.

Create/update reject fields outside their input allowlist, including role and permission fields. New administrators receive no roles or tokens automatically. Updating your own account through the management API returns HTTP 403. Validation failures, including username conflicts, return HTTP 422. Authentication failures return HTTP 401; disabled accounts or missing permissions return HTTP 403. Error responses use `message`, with `errors` for validation failures. Passwords and tokens are not included in management responses.

Disabling an account preserves its existing tokens but blocks protected management access. Logout revokes only the current token and remains available to disabled administrators.

## Platform Management APIs

Platforms are managed by internal administrators. All five routes use `auth:sanctum` → `admin.token` → `admin.active` → `admin.permission:<permission>`.

| Method | Endpoint | Permission | Success |
|---|---|---|---|
| GET | `/api/v1/admin/platforms` | `platforms.view` | 200, `data`, `links`, `meta` |
| GET | `/api/v1/admin/platforms/{id}` | `platforms.view` | 200, `data` |
| POST | `/api/v1/admin/platforms` | `platforms.manage` | 201, `data`, `Location` |
| PUT | `/api/v1/admin/platforms/{id}` | `platforms.manage` | 200, `data` |
| PATCH | `/api/v1/admin/platforms/{id}/status` | `platforms.manage` | 200, `data` |

`platforms.manage` does not imply `platforms.view`. No role receives either permission automatically; the existing role-delegation allowlist is unchanged. There is no platform DELETE API.

Resources expose only `id`, `code`, `name`, boolean `is_active`, and UTC ISO 8601 `created_at` / `updated_at`. Player data is excluded. Missing, invalid, or unsigned-BIGINT-overflow IDs return `404` with `{"message":"Platform not found."}`. Authentication and permission checks precede target lookup and validation; PUT/PATCH target lookup precedes body validation. Authentication failures return 401, disabled administrators or missing permissions return 403, and invalid input returns 422 with `message` and `errors`.

### Create, Rename, and Status

POST accepts only `code`, `name`, and optional `is_active` (default true):

```json
{"code":"PlatformA","name":"Platform A","is_active":true}
```

`code` is a permanent identifier set only at creation. It accepts 1–50 ASCII letters, digits, `.`, `_`, and `-`, preserving case. Uniqueness uses the existing MySQL `utf8mb4_0900_as_cs` column collation: `PlatformA` and `platforma` can coexist, while an exact duplicate returns 422. Only MySQL 1062 for `platforms_code_unique` from the business INSERT is translated to a code validation error; unrelated SQL and audit errors propagate. Existing Laravel string-trimming middleware also applies to incoming strings; code is never lowercased.

PUT accepts only `{"name":"New platform name"}`. Name is required, trimmed, nonblank, and at most 100 characters. `code` is rejected even when equal to the current value, and `is_active` is rejected. The controller assigns only the validated name, so request fields cannot mass-assign code or status.

PATCH accepts only `{"is_active":false}` (or true). It uses Laravel's boolean validation, accepting JSON booleans and 0/1 forms; strings such as `"false"` are invalid. Unknown fields/query parameters are rejected on writes. Repeating the existing name or status succeeds without a database update or success audit event.

Disabling a platform preserves players, their statuses and relationships, and administrator tokens. Blocking future external login or wallet operations is outside this feature. The existing player FK uses DELETE RESTRICT; no hard-delete endpoint is provided.

### List Parameters

| Parameter | Contract |
|---|---|
| `page` | Integer 1–10000; default 1 |
| `per_page` | Integer 1–100; default 20 |
| `search` | Optional nullable string, max 100; grouped code/name substring search |
| `is_active` | Boolean filter, e.g. `0` or `1` |
| `sort` | `id`, `code`, `name`, `created_at`, `updated_at`; default `id` |
| `direction` | `asc` or `desc`; default `desc` |

Unknown query parameters return 422. `%` and `_` are literal search characters, and `!` is escaped explicitly. Search and status filters combine with AND. Non-unique sort fields use ID as a tie-breaker. Code searches follow the code column's case-sensitive collation; name searches follow the name column's collation. Pagination links preserve validated filter/sort parameters.

### Platform Audit Events

| Action | Old / new snapshots |
|---|---|
| `platform.created` | null / `code`, `name`, `is_active` |
| `platform.updated` | Changed `name` before / after |
| `platform.enabled` | `is_active`: false / true |
| `platform.disabled` | `is_active`: true / false |

All events use target type `platform`, capture the operator, target ID and Request IP, and are written synchronously on the business connection inside its transaction. Rename/status operations lock the platform before reading old values. Audit failure rolls back the business write. GET, rejected requests and no-op changes do not create success events. The existing Audit Log API can filter these events by action and target type; its separate `audit-logs.view` permission remains required.

Code is added to both audit snapshot allowlists as string/null only. Passwords, tokens, secrets, arbitrary nested values and unrelated fields remain excluded. This does not detect secrets embedded in otherwise allowed business strings.

No schema migration is added or changed. Unique-key competition tests use a real conflicting INSERT on one connection, not two concurrent connections. Row locks do not guarantee protection against arbitrary external SQL writers or configuration changes after endpoint authorization. Code immutability is enforced by the API allowlists and explicit assignments and by the Platform Model update persistence guard. Existing instances reject code changes with LogicException through update(), fill()->save(), direct assignment followed by save(), and quiet saves/updates, including case-only changes. Creation and Factory code assignment remain supported; name and status updates remain available. Reassigning the identical code is allowed by the Model, while update APIs reject the code field even when unchanged. Query Builder updates (including bulk Eloquent builder updates), direct SQL, and deliberate low-level Model state manipulation are outside this protection; no database trigger is added.

## MVP RBAC

RBAC uses exactly three core tables; existing Sanctum and framework tables remain separate:

| Table | Purpose and constraints |
|---|---|
| `admins` | Existing administrator identity and active status |
| `admin_roles` | `admin_id`, `role`; unique `(admin_id, role)`; foreign key to `admins.id` with delete cascade |
| `roles_permissions` | `role`, `permission`; unique `(role, permission)` |

Roles and permissions are string codes, with no standalone role or permission definition tables and no third-party permission package. Both role columns use `VARCHAR(50)` with `utf8mb4_unicode_ci`; permissions use `VARCHAR(100)`. The two mapping tables have no surrogate IDs or timestamps.

`Admin::hasPermission()` joins the mapping tables and uses `exists()` to evaluate the union of all assigned roles. Permissions are queried directly without Redis or token snapshots, so changes apply on the next query. `admin.permission` accepts one valid lowercase permission code and returns HTTP 403 when it is invalid or not granted.

String codes have no foreign-key-backed definition registry: spelling, case conventions, renaming, and allowed codes require application-level discipline. Role assignment APIs manage administrator-to-role mappings only; they do not implement role/permission definition CRUD or modify `roles_permissions`. `admins.create` does not grant role-assignment authority; `admins.update` is sensitive because it can change another account's password and active status.

### Role Assignment APIs

GET returns HTTP 200 with `{"data":{"admin_id":12,"roles":["manager","viewer"]}}`. Roles are sorted ascending; an administrator without roles returns an empty array. Reading your own roles still requires `admins.roles.read`.

POST accepts only `{"role":"viewer"}`. Role codes must be lowercase ASCII letters, digits, or underscores, start with a letter, and contain at most 50 characters. A new assignment returns HTTP 201 with `{"data":{"admin_id":12,"role":"viewer"}}`; an existing assignment returns HTTP 200 with the same structure after authorization checks. The composite unique index prevents duplicate assignments. Only duplicate-key errors for that index are handled as a competing assignment; other SQL errors propagate.

DELETE validates the path role and accepts no input fields or query parameters (an absent or empty JSON body is accepted). Success returns HTTP 204 with no body. It deletes only the specified `(admin_id, role)` mapping, preserving other assignments, `roles_permissions`, and Sanctum tokens.

The policy in `src/config/rbac.php` is deliberately limited:

| Setting | Current values |
|---|---|
| Known roles | `viewer`, `manager`, `security_admin` |
| Assignable roles | `viewer`, `manager` |
| Revocable roles | `viewer`, `manager` |
| Protected roles | `security_admin` |
| Delegable permissions | `admins.read` |

These settings do not create database configuration. Assignable/revocable roles must already have explicit, non-empty permissions in `roles_permissions`. Every permission must be in the delegable allowlist and held by the operator. Roles containing role-management, account/password-write, or other unreviewed capabilities are rejected, even if the operator holds those capabilities. Unknown or unsafe configurations are not automatically repaired.

Self-assignment and self-revocation are forbidden. Protected roles cannot be assigned or revoked through these APIs, even if accidentally included in the candidate lists. Missing configuration returns HTTP 422 for POST and HTTP 403 for DELETE. Other policy denials return HTTP 403. Unknown/invalid POST roles return HTTP 422; unknown/invalid DELETE roles and unassigned DELETE mappings return HTTP 404.

Authentication and endpoint permission checks run before target lookup, returning HTTP 401/403 without revealing target existence. For requests that pass those checks, invalid or nonexistent administrator IDs return a safe HTTP 404 before POST body or DELETE role/body validation. With an existing target, invalid input returns the applicable HTTP 404 or 422. Errors retain the existing `message` format and validation `errors` structure.

POST and DELETE lock the operator and target administrators in ID order inside a transaction, recheck authorization, and take shared locks on the target role's permission configuration. Role changes apply on the next request using existing tokens; they do not cancel requests already authorized. Future permission-configuration writers must coordinate with this locking policy and prevent later role changes from bypassing delegation restrictions.

Complete system-wide last-administrator protection is **not implemented**. Account status changes and future definition-management APIs need additional safeguards. Existing tests simulate unique-key competition on one connection; **real two-connection concurrency testing is not complete**. Transactions and existing tests must not be described as proof that all concurrency risks are resolved.

## Audit Logs

### Implemented Scope

Audit logs record successful administrator and role-assignment changes for operational traceability. Each event stores the operator's `admin_id`, `target_type` (`admin`), target administrator ID, filtered before/after snapshots, Request IP, and creation time.

| Action | Trigger | Snapshots |
|---|---|---|
| `admin.created` | Administrator created | Old: null; new: `username`, `name`, `is_active` |
| `admin.updated` | Username/name actually changed | Only changed `username`/`name` fields |
| `admin.enabled` | Inactive to active | Before/after `is_active` |
| `admin.disabled` | Active to inactive | Before/after `is_active` |
| `admin.password_changed` | Persisted password changed | Both null; no password metadata |
| `admin.role_assigned` | New role mapping inserted | Before/after `roles` arrays |
| `admin.role_revoked` | Assigned role removed | Before/after `roles` arrays |

Role snapshots use persisted string role codes sorted ascending. One update can produce multiple events. No-op updates, duplicate assignments, failed operations, and GET queries do not produce successful-change events.

Controllers write business data and audit records synchronously in the same transaction and pass the same connection name to `AuditLogService::record()`. Updates lock the target before taking old values; role mutations retain their existing locks and policies. The service does not manage transactions or swallow SQL exceptions. An audit failure rolls back the business operation and any earlier events in that transaction. Only the expected business unique-key errors receive their existing special handling.

### Read API and Access

`GET /api/v1/admin/audit-logs` uses `auth:sanctum` → `admin.token` → `admin.active` → `admin.permission:audit-logs.view`. A valid persisted administrator Bearer Token, active account, and explicit permission are required. Access is denied by default: no role is automatically granted this permission, and there is no production RBAC seeder that automatically authorizes it. The permission middleware supports lowercase dot-separated segments starting with a letter, with letters, digits, underscores, and hyphens thereafter (maximum 100 characters).

Authentication failures return 401; disabled accounts or missing permission return 403. Authentication and permission checks precede query validation, so an unauthorized request with invalid parameters still returns 401/403. Authorized invalid queries return the existing 422 `message`/`errors` response.

All filters are optional and combined with AND:

| Parameter | Validation and behavior |
|---|---|
| `admin_id`, `target_id` | Positive unsigned BIGINT, maximum `18446744073709551615`; historical IDs need not currently exist |
| `action` | Nonempty string, maximum 50 characters; exact match |
| `target_type` | Nonempty string, maximum 100 characters; exact match |
| `date_from`, `date_to` | Real dates in strict `YYYY-MM-DD`, from `1000-01-01` through `9999-12-30`; `date_to` must not precede `date_from` |
| `page` | Integer 1–10000; default 1 |
| `per_page` | Integer 1–100; default 20 |

Unknown parameters, including `sort` and `direction`, return 422. Sorting is fixed at `created_at DESC, id DESC`. Query values use Eloquent bindings rather than user-provided SQL.

Dates use UTC: `date_from` includes that day's midnight; `date_to` excludes the following day's midnight. Either date can be supplied alone. `9999-12-31` is rejected to prevent next-day overflow. These are query bounds, not a statement that MySQL TIMESTAMP supports storing every date in that range. Laravel uses UTC, and the MySQL connection explicitly sets its Session timezone to `+00:00`; this does not change the server's Global timezone.

Responses use Laravel Resource pagination with `data`, `links`, and `meta`. Example for one record at the default page size (URLs depend on the deployment):

```json
{
  "data": [{
    "id": 42,
    "admin_id": 1,
    "action": "admin.role_assigned",
    "target_type": "admin",
    "target_id": 12,
    "old_values": {"roles": []},
    "new_values": {"roles": ["viewer"]},
    "ip_address": "127.0.0.1",
    "created_at": "2026-11-01T12:00:00.000000Z"
  }],
  "links": {
    "first": "http://localhost/api/v1/admin/audit-logs?page=1",
    "last": "http://localhost/api/v1/admin/audit-logs?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [
      {"url": null, "label": "&laquo; Previous", "active": false},
      {"url": "http://localhost/api/v1/admin/audit-logs?page=1", "label": "1", "active": true},
      {"url": null, "label": "Next &raquo;", "active": false}
    ],
    "path": "http://localhost/api/v1/admin/audit-logs",
    "per_page": 20,
    "to": 1,
    "total": 1
  }
}
```

### Snapshot Safety and Limitations

Both service writes and Resource reads allow only `username`, `name`, `is_active`, `role`, `roles`, `code`, and `status`. Existing username/name/role/code values must be strings, active status must be boolean, and roles must be a list of strings; these existing fields continue to accept null. The `status` field accepts only integer 0 or 1; boolean, string, float, and null values are rejected. Other fields and nested objects/arrays under scalar fields are discarded. Historical JSON is filtered again on output, without loading administrator or token relations. Passwords, hashes, tokens, secrets, authorization fields, and arbitrary Request/Model serialization must not enter snapshots. This is a field/type allowlist, not content-based secret detection inside otherwise permitted strings.

`audit_logs` has nullable operator/target IDs, JSON snapshots, IPv4/IPv6 address storage, and a `created_at` TIMESTAMP without `updated_at`. Indexes cover `(admin_id, created_at)`, `(target_type, target_id, created_at)`, `(action, created_at)`, and `created_at`. No cascading foreign key deletes audit history.

There are no audit update/delete APIs or business methods. Database-level tamper-proof storage, retention/archival, broader request/authentication-event coverage, and export APIs are not implemented. Database users with write privileges can still alter records. Deep offset pagination and count queries may become expensive as history grows; index tuning and cursor pagination are future improvements. IP attribution depends on correct proxy configuration. Real two-connection concurrency testing and complete last-administrator protection remain incomplete.

## Development Environment

The local development environment is managed using Docker Compose.

### Start Containers

Build and start the required services:

```bash
docker compose up -d --build
```

### Check Container Status

Verify that the containers are running:

```bash
docker compose ps
```

### Stop Containers

Stop and remove the project containers:

```bash
docker compose down
```

## Testing

The project uses Laravel's built-in testing tools to validate application functionality and API behavior.

Before running database-writing tests, verify the effective environment is `testing`, the connection is MySQL at `mysql:3306`, `SELECT DATABASE()` returns `laravel_testing`, and configuration is not cached. The test database, required schema, and permissions must already exist. Do not substitute the development database or remove the protections in `src/phpunit.xml` and `src/tests/TestCase.php`.

Run the test suite from the repository root using the existing PHP container:

```bash
docker compose exec php php artisan test
```

Feature tests cover authentication, token revocation and device isolation, active-account enforcement, RBAC queries/middleware, administrator List, Detail, Create, and Update, and role listing/assignment/revocation, including error precedence and delegation restrictions. Audit tests cover snapshot filtering, business/audit rollback, query filters, UTC boundaries and serialization, pagination limits, and access control. On 2026-11-04, the Platform Model selection passed 18 tests with 64 assertions, the Issue #7 and affected audit selection passed 189 tests with 944 assertions, and the broader regression selection passed 487 tests with 1965 assertions, zero failures, and no risky tests. These selections overlap and must not be added together. Documentation-only changes do not require running application tests. If migrations are prohibited for a verification run, the default `RefreshDatabase` lifecycle must not be allowed to rebuild the schema; use an explicitly verified existing-schema execution approach and report it.

### Issue #7 Verification Scope (2026-11-04)

Verification used the existing PHP 8.3 container and MySQL `mysql:3306/laravel_testing`. A separate read-only preflight process rejected cached configuration and verified the effective testing environment, actual `SELECT DATABASE()`, UTC Session timezone, required columns, case-sensitive collations, unique indexes, and player FK RESTRICT before PHPUnit started. Successful transactional tests also exercised write permissions.

A temporary runner outside the repository set `RefreshDatabaseState::$migrated = true` only after the preflight succeeded. This retained the existing tests' transaction/rollback lifecycle while avoiding implicit `migrate:fresh`; neither `phpunit.xml`, `tests/TestCase.php`, nor `.env` was changed. Running the default test command without this verified existing-schema setup can invoke RefreshDatabase migrations.

The regression selection excluded `UserModelTest::test_conversion_and_rollback_refuse_existing_rows_without_modifying_them` via a negative PHPUnit filter. Static analysis shows its non-empty-users guard should throw before DDL, but it remains explicitly excluded by user instruction. It was not executed. Its previously authorized actual-database guard remains in place. No migration or schema rebuild was executed, and no development/production database was modified.

Validation after Model hardening: 18 Platform Model tests / 64 assertions passed; 189 Feature/Audit tests / 944 assertions passed; 487 regression tests / 1965 assertions passed (including those selections), zero failures and one case excluded. Model tests cover creation, update(), fill()->save(), direct attribute assignment followed by save(), quiet updates/saves, case-only changes, unchanged persisted data on rejection, and normal name/status updates. PSR-12 check-only validation passed for all 16 changed/new PHP files, and `git diff --check` passed. Real dual-connection races, external integration behavior, and database-level code immutability are not verified guarantees.

## Development Goals

The primary goals of this project are:

- Build maintainable and extensible RESTful APIs.
- Implement common features found in enterprise internal management systems.
- Establish a consistent development environment using Docker.
- Improve software reliability through automated testing.
- Continuously enhance application architecture, code quality, and development workflows.

## Project Status

**Under Development**

This project is actively being developed. Features, architecture, and technical documentation will be updated as development progresses.

## Admin Player Management API

Player management uses the existing `users` table and adds no migrations. The routes require a persisted Admin Sanctum Bearer Token, followed by `admin.token`, `admin.active`, and `admin.permission` checks.

| Method | Path | Permission | Response |
|---|---|---|---|
| GET | `/api/v1/admin/players` | `players.view` | 200, `data`, `links`, `meta` |
| GET | `/api/v1/admin/players/{id}` | `players.view` | 200, `data` |
| PATCH | `/api/v1/admin/players/{id}/status` | `players.manage` | 200, `data` |

View and manage permissions are independent. No default permission records or delegation capabilities are granted. Authentication and authorization precede target lookup and validation. Invalid or missing internal player IDs return 404 with `{"message":"Player not found."}`. IDs must be canonical positive unsigned BIGINT decimal values, at most `18446744073709551615`.

Resources expose only `id`, `platform_id`, `external_user_id`, nullable `name`, integer `status`, nullable `last_login_at`, `created_at`, and `updated_at`. Dates use UTC ISO 8601. Player resource IDs within PHP's signed integer range remain JSON integers; larger IDs are decimal strings. Clients should handle large numeric identifiers without JavaScript precision loss. Player lookups retain string primary keys to avoid Eloquent integer truncation. AuditLogService accepts validated decimal strings for target IDs in addition to its existing integer/null interface; administrator ID typing is unchanged.

List query parameters:

| Parameter | Contract |
|---|---|
| `page` | 1–10000, default 1 |
| `per_page` | 1–100, default 20 |
| `platform_id` | Positive unsigned BIGINT; a nonexistent platform returns an empty list |
| `external_user_id` | Nonempty string, at most 100 characters; exact case-sensitive comparison |
| `status` | 0 or 1 |
| `search` | Optional substring search across external ID and name, at most 100 characters |
| `sort` | id, platform_id, external_user_id, name, status, last_login_at, created_at, updated_at |
| `direction` | asc or desc, default desc |

Default order is `id DESC`; every other sort appends ID in the same direction. Filters combine with AND; search groups its two fields with OR. External IDs are unique only within a platform and are never lowercased. External ID comparisons follow `utf8mb4_0900_as_cs`; name search follows the name column collation. LIKE treats `%`, `_`, and `!` literally using an explicit escape character and bound parameters. Sorts use an allowlist. Unknown query parameters, arrays and invalid values return 422. Pagination links retain validated query options. Offset pagination provides deterministic ordering for a fixed dataset, not a snapshot across concurrent changes.

The existing global TrimStrings and ConvertEmptyStringsToNull middleware remains enabled: query strings, including `external_user_id`, are trimmed before validation, and empty strings become null. Consequently this API cannot select an external identity with significant leading/trailing whitespace through the exact filter. An empty external ID filter is invalid; empty search is ignored. No global middleware exception is introduced for other APIs.

Status PATCH accepts only a JSON object containing integer `status` 0 (Disabled) or 1 (Active), for example `{"status":0}`. Boolean, float, string, null, extra body fields and all query parameters return 422. Body validation follows target existence checks. A transaction on the User connection locks and rereads the target; disappearance returns the same safe 404. Existing statuses 2–255 remain readable but PATCH returns 409 with `{"message":"Player status is not supported."}` without modifying data. No schema CHECK constraint is added. Repeating the current status returns 200 without saving, changing `updated_at`, or recording an audit event.

Changes synchronously record `player.enabled` or `player.disabled`, target type `player`, internal player ID, operator and Request IP on the same connection and transaction. Snapshots contain only integer status 0/1 before and after. Audit service and Resource both filter status values strictly; existing snapshot field/type rules remain intact. Audit failure propagates and rolls back the player update. GET, rejected requests and no-ops produce no success events.

Players on disabled platforms remain manageable. These endpoints cannot create/delete players, change platform/external identity, name or last login, or edit wallets. They do not implement player sessions, external game admission checks, wallets, or platform integration. User Model fillable fields remain available to other trusted code; API field restrictions are not database-enforced immutability.

PlayerManagementTest and PlayerAuditLogTest were written in phase two. On 2026-11-09, the dedicated Player/Audit Service selection passed 64 tests / 339 assertions in 1.416 seconds; the safe regression selection passed 543 tests / 2263 assertions in 10.751 seconds, with zero failures, errors or risky tests. Selections overlap and must not be added together. They use transaction isolation against the existing dedicated testing schema. Before any later execution, verify effective testing/MySQL/mysql:3306/laravel_testing configuration, cached configuration, schema, permissions and session timezone. Keep existing TestCase/phpunit.xml guards. Migration execution and implicit RefreshDatabase schema rebuilds remain prohibited unless separately authorized. Exclude direct migration/DDL cases, including `UserModelTest::test_conversion_and_rollback_refuse_existing_rows_without_modifying_them`, from the later regression selection. Real two-connection concurrency verification remains outstanding.


### Issue #8 Verification Scope (2026-11-09)

Each PHPUnit process used a temporary, uncommitted runner with fresh preflight checks for effective uncached testing configuration, actual MySQL mysql:3306/laravel_testing connection and UTC session timezone, existing required schema, all 10 migration records, InnoDB tables, and absence of competing testing connections/processes. The runner held an advisory lock, blocked DDL on Laravel MySQL connections, and set RefreshDatabaseState::$migrated only after preflight. Existing transaction rollback and phpunit.xml/TestCase guards were preserved. The regression selection excluded UserModelTest::test_conversion_and_rollback_refuse_existing_rows_without_modifying_them; it is not an unfiltered full-suite result.

Initial dedicated runs exposed test-only issues: Factory attribute order differed from reloaded database rows, and the raw JSON float request omitted the persisted Bearer header. Baselines now reload persisted attributes before strict comparison, and the raw request forwards its authentication headers. The temporary runner also needed to release preflight error/exception handlers before PHPUnit initialized. No production code was changed during phase-four verification.

Postflight compared all table definitions, migration records and hashed persistent rows before/after each test run. They were unchanged. AUTO_INCREMENT counters were excluded from schema comparison: transactional test INSERTs can consume sequence numbers even after rollback. No migration or schema modification was executed. Large-ID coverage verifies resource hydration, exact query bindings and persisted audit target IDs; it does not create a player with a primary key above PHP_INT_MAX or verify a full status update against such a stored player. Real two-connection concurrency verification remains outstanding.
