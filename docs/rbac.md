# Role-Based Access Control

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

