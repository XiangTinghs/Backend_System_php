# Audit Logs

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

