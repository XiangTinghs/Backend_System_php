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
- Administrator deletion, role assignment APIs, permission management APIs, Sanctum token ability enforcement, token expiry/rotation, refresh tokens, and all-device logout are not implemented. They require explicit implementation and behavior tests before being documented as supported.
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
- Role assignment and permission management APIs remain unimplemented. String code consistency requires application validation; do not assume a database definition registry exists.
