# Admin Player Management API

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
