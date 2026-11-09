# Administrator API Reference

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

