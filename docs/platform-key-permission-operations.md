# Controlled platform key permission operations

This runbook covers only `platforms.keys.view`, `platforms.keys.register`, `platforms.keys.manage`, and `platforms.keys.revoke`. No grant is performed by deployment, migrations, seeds, login, API request bodies or default configuration. The existing AdminRoleController and its delegation allowlist are unchanged. This task executed these operations only on synthetic, rolled-back testing identities.

## Trust boundary and prerequisites

Use an approved maintenance session on the intended host through authenticated OS/container access. Restrict shell/Artisan access to trusted operators: anyone with arbitrary application/DB write access is already outside the API authorization boundary. Record the OS operator identity, intended environment/database, change ticket, target role and administrator IDs in the change approval. There is no HTTP entry point for this operation.

The application operator must be an existing active administrator with an existing `security_admin` role assignment, and must authenticate with that administrator's password in a hidden prompt. `--actor` alone is not authentication. Never put the password or bearer tokens into command arguments, shell history, environment variables, tickets or logs. Hidden-input fallback is disabled. Actual application requires interactive input, `--apply`, and exact confirmation text; `--no-interaction` is rejected. There is no `--force` bypass.

Only roles explicitly configured as protected in `rbac.protected_roles` are eligible targets; currently this is `security_admin`. The role must already have members. Viewer/manager/unknown/unprotected roles cannot receive these permissions through the command. The command never creates memberships, users or role definitions, and never modifies existing delegation policy. Review every listed member, including disabled accounts, because enabling those accounts later makes the granted role permission effective.

## First privileged-operator initialization

If no approved active administrator already belongs to `security_admin`, stop the permission command. Establishing that initial privileged identity is a separately approved infrastructure operation, not something an unprivileged administrator can perform through an API. Do not add it to a default seeder or temporarily weaken AdminRoleController.

The concrete bootstrap procedure is:

1. A trusted DBA/operations identity obtains approval naming one existing active administrator ID and verifies that person's identity independently. Before approval, read **all** current `security_admin` permission mappings and **all** current administrator members, including inactive members. Do not restrict the review to the four platform-key permissions. Configuration does not prove that the database role is empty.
2. Produce an explicit read-only preview containing the target administrator ID/status/current roles and effective permission union, the role's complete permission list, its current member IDs/statuses, the permissions the target will immediately inherit, and the net new effective capabilities. Show which people are affected: membership bootstrap changes the target's access; subsequent role-permission grants/revokes affect every member. Include restored/disabled accounts that could later be enabled. Record this preview, authenticated DBA/OS identity, exact environment/database and ticket in the change approval. A generic approval to create a bootstrap identity is insufficient; the actual inherited capabilities and affected people must be explicitly approved. No approval means no writes. This runbook does not authorize executing bootstrap now.
3. In a controlled maintenance window, use a parameter-binding client and begin a transaction. Lock affected administrator rows in ascending ID order (including the target and approved role members), then relevant membership mappings, then shared-lock the role permission definitions. Recheck the preview against the approved snapshot, verify the target is still active, and stop/rollback if membership, status or permissions changed. If the target already has security_admin, stop without a duplicate success event. The client must expand approved ID placeholders safely rather than interpolating user strings.
4. Insert only `(approved_admin_id, 'security_admin')` into `admin_roles` and synchronously insert the bootstrap audit on the same connection/transaction. This immediately grants all existing role permissions to the new member, even though no roles_permissions row changes. Check both affected-row counts are one. Any error/unapproved state requires rollback. Never auto-create a privileged administrator, clear existing mappings, relax delegation or treat membership insertion as a zero-permission operation.
5. Review the resulting assignment/audit before commit. Retain the DBA session identity and approved before/after capability preview with the ticket. Audit admin_id is NULL because this is a DBA infrastructure action; the target must not be falsely recorded as the actor. After bootstrap, use the administrator's own password and the command below for separately approved individual mapping changes. A membership removal needs its own approved maintenance transaction, with review of any permissions remaining through other roles.

Read-only preview queries include:

```sql
SELECT permission FROM roles_permissions WHERE role = 'security_admin' ORDER BY permission;
SELECT a.id, a.username, a.is_active FROM admins a JOIN admin_roles r ON r.admin_id = a.id
WHERE r.role = 'security_admin' ORDER BY a.id;
SELECT role FROM admin_roles WHERE admin_id = :approved_admin_id ORDER BY role;
SELECT DISTINCT p.permission FROM admin_roles r JOIN roles_permissions p ON p.role = r.role
WHERE r.admin_id = :approved_admin_id ORDER BY p.permission;
```

The application command below previews a single permission mapping and role members; it does not perform membership bootstrap and cannot replace this full inherited-permission review.

The bootstrap client's parameterized transaction body is illustrated below after acquiring the administrator/member locks described above. `:approved_admin_id` and `:ticket` are bound values supplied by the approved client, not shell substitution. The client must perform the checks/rollback/commit steps above; this is not an unattended SQL script.

```sql
SELECT id, is_active FROM admins WHERE id = :approved_admin_id FOR UPDATE;
SELECT role FROM admin_roles WHERE admin_id = :approved_admin_id ORDER BY role FOR UPDATE;
SELECT permission FROM roles_permissions WHERE role = 'security_admin' ORDER BY permission FOR SHARE;
INSERT INTO admin_roles (admin_id, role) VALUES (:approved_admin_id, 'security_admin');
INSERT INTO audit_logs (admin_id, action, target_type, target_id, old_values, new_values, ip_address)
VALUES (NULL, 'rbac.security_operator_bootstrapped', 'admin', :approved_admin_id, NULL,
        JSON_OBJECT('role', 'security_admin', 'ticket', :ticket), NULL);
```

This is an actual privileged authorization: the target immediately inherits every existing security_admin permission mapping, potentially including all four platform-key permissions and other sensitive capabilities. Only when the reviewed role has no relevant mappings will those particular permissions remain absent. Do not use bootstrap to bulk provision administrators or bypass the normal protected-role/delegation rules.

## Preview, grant and revoke

From repository root, explicitly select action, existing protected role, one permission, authenticated administrator ID and approved ticket. Example IDs/tickets are placeholders, not instructions to grant a live administrator now.

```bash
# Preview only: no writes and no success audit.
docker compose exec php php artisan platform-keys:permission grant security_admin platforms.keys.view --actor=12 --ticket=SEC-123

# Apply the reviewed role-wide grant; prompts for password and exact confirmation.
docker compose exec php php artisan platform-keys:permission grant security_admin platforms.keys.view --actor=12 --ticket=SEC-123 --apply

# Preview, then apply removal of that exact role/permission mapping.
docker compose exec php php artisan platform-keys:permission revoke security_admin platforms.keys.view --actor=12 --ticket=SEC-124
docker compose exec php php artisan platform-keys:permission revoke security_admin platforms.keys.view --actor=12 --ticket=SEC-124 --apply
```

The confirmation for the grant example is exactly `GRANT platforms.keys.view security_admin SEC-123`. Other permissions require separate explicit commands/approvals. The output displays environment/database, operator, action, role, permission, ticket, affected administrator IDs/status and current mapping. Recheck the environment/database and every member before confirming. No commands were run against development/production for this task.

The service locks administrator rows in ascending ID order, then relevant memberships and target role permission rows. It revalidates the active operator's security_admin membership, password, previewed recipients and mapping state in the transaction. A stale membership/permission preview fails without writes. Actual mapping change and AuditLogService write use the same connection/transaction. Audit failure rolls back the mapping change. An already-present grant or absent revoke is a no-op without a success event.

Events are `rbac.platform_key_permission_granted` and `rbac.platform_key_permission_revoked`, target type `role`, target ID NULL. Snapshots contain role, permission, ticket and boolean `is_active` meaning that this exact mapping is present/absent; this is not administrator active status. Operator Admin ID is recorded, and IP is NULL for console operations. Passwords, tokens, requests and PEM are never included. AuditLogResource allows the same string/null `permission`/`ticket` fields as AuditLogService. Application audit is not database-enforced immutable storage.

Revoke removes only the specified role/permission mapping. RBAC uses a live union of all assigned roles, so the same permission may still be granted by another role. Check all memberships and mappings when revoking an individual's effective access; do not treat one mapping removal as all-role revocation. Tokens remain intact, and subsequent requests observe the updated permission. Requests already authorized in flight are not canceled. Reversing a mistaken grant requires an explicit, separately ticketed revoke (and vice versa), not database rollback of unrelated changes. Revoking this permission does not remove the operator's separately controlled security_admin membership or change the CLI trust boundary.

## Verification and limits

Feature tests cover preview/no writes; all four permission grants/revokes and no-ops; active/privileged operator requirements; password/confirmation and argument rejection; noninteractive rejection; stale previews; live bearer-token authorization after grant/revoke; same-connection transaction/audit rollback; and multi-role union semantics. Tests run only on verified laravel_testing. No actual administrator was granted permissions by this work.

The command is a narrow privileged operations mechanism, not general role/permission CRUD or a replacement for OS access governance. Targeted public-key registration concurrency is tested in separate HTTP worker processes; full concurrent permission/membership configuration testing is not claimed. Initial DBA bootstrap and direct Migration up/down/rollback scenarios were not executed. Symfony advisories, production HTTPS/ingress rate limits and deployment configuration review remain formal prerequisites before public exposure.
