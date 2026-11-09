# Platform Management API Reference

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

